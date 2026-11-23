<?php
/**
 * Create or edit a user account.
 *
 * Self-demotion and self-deactivation are blocked here and re-checked inside
 * the transaction. Removing the last active administrator is also blocked
 * using the same serialized lock order as account activation actions.
 */
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/users.php';
require_once __DIR__ . '/../../includes/schools.php';

$actor = require_role($pdo, 'admin');

$id = null;
if (isset($_GET['id'])) {
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id < 1) {
        abort_404('The requested user account does not exist.');
    }
    $id = (int) $id;
}

$target = null;
if ($id !== null) {
    $target = find_user_by_id($pdo, $id);
    if ($target === null) {
        abort_404('The requested user account does not exist.');
    }
}

$isEdit = $target !== null;
$isSelf = $isEdit && (int) $target['id'] === (int) $actor['id'];
$errors = [];

$values = [
    'full_name'      => (string) ($target['full_name'] ?? ''),
    'email'          => (string) ($target['email'] ?? ''),
    'role'           => (string) ($target['role_name'] ?? 'student'),
    'status'         => (string) ($target['status'] ?? 'active'),
    'student_number' => (string) ($target['student_number'] ?? ''),
    'phone'          => (string) ($target['phone'] ?? ''),
    'department'     => (string) ($target['department'] ?? ''),
];

if (is_post()) {
    verify_csrf();

    $postedId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if ($postedId !== false && $postedId !== null && (int) $postedId > 0) {
        $id = (int) $postedId;
    }

    $isEdit = $id !== null;
    if ($isEdit) {
        $target = find_user_by_id($pdo, (int) $id);
        if ($target === null) {
            abort_404('The requested user account does not exist.');
        }
        $isSelf = (int) $target['id'] === (int) $actor['id'];
    }

    $values = [
        'full_name'      => trim((string) ($_POST['full_name'] ?? '')),
        'email'          => trim((string) ($_POST['email'] ?? '')),
        'role'           => (string) ($_POST['role'] ?? 'student'),
        'status'         => (string) ($_POST['status'] ?? 'active'),
        'student_number' => trim((string) ($_POST['student_number'] ?? '')),
        'phone'          => trim((string) ($_POST['phone'] ?? '')),
        'department'     => trim((string) ($_POST['department'] ?? '')),
    ];

    $result = validate_user_management_input(
        $pdo,
        [
            'full_name'        => $values['full_name'],
            'email'            => $values['email'],
            'role'             => $values['role'],
            'status'           => $values['status'],
            'student_number'   => $values['student_number'],
            'phone'            => $values['phone'],
            'department'       => $values['department'],
            'password'         => $_POST['password'] ?? '',
            'password_confirm' => $_POST['password_confirm'] ?? '',
        ],
        $isEdit ? (int) $id : null,
        !$isEdit
    );

    $errors = $result['errors'];

    if ($isSelf) {
        if ($result['values']['role'] !== 'admin') {
            $errors['role'] = 'You cannot demote your own administrator account.';
        }
        if ($result['values']['status'] !== 'active') {
            $errors['status'] = 'You cannot deactivate your own administrator account.';
        }
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            // One lock order across every administrative operation.
            lock_admin_role($pdo);

            if ($isEdit) {
                $lockedTarget = lock_user_for_update($pdo, (int) $id);

                if ($lockedTarget === null) {
                    $pdo->rollBack();
                    abort_404('The requested user account does not exist.');
                }

                if ((int) $lockedTarget['id'] === (int) $actor['id']
                    && ($result['values']['role'] !== 'admin' || $result['values']['status'] !== 'active')) {
                    $pdo->rollBack();
                    $errors['form'] = 'You cannot demote or deactivate your own account.';
                } elseif (removes_last_active_admin(
                    $pdo,
                    $lockedTarget,
                    (string) $result['values']['role'],
                    (string) $result['values']['status']
                )) {
                    $pdo->rollBack();
                    $errors['form'] = 'This change would remove the last active administrator.';
                }
            }

            if ($errors === []) {
                if ($isEdit) {
                    $sql = 'UPDATE users
                            SET role_id = :role_id,
                                full_name = :full_name,
                                email = :email,
                                student_number = :student_number,
                                phone = :phone,
                                department = :department,
                                status = :status';
                    $updateParams = [
                        'role_id'        => role_id_for($pdo, (string) $result['values']['role']),
                        'full_name'      => $result['values']['full_name'],
                        'email'          => $result['values']['email'],
                        'student_number' => $result['values']['student_number'],
                        'phone'          => $result['values']['phone'],
                        'department'     => $result['values']['department'],
                        'status'         => $result['values']['status'],
                        'id'             => (int) $id,
                    ];

                    if ($result['password'] !== null) {
                        $sql .= ', password_hash = :password_hash, session_version = session_version + 1';
                        $updateParams['password_hash'] = password_hash((string) $result['password'], PASSWORD_DEFAULT);
                    }

                    $sql .= ' WHERE id = :id';

                    $statement = $pdo->prepare($sql);
                    $statement->execute($updateParams);

                    $logDescription = sprintf(
                        'Updated %s account for %s.',
                        $result['values']['role'],
                        $result['values']['email']
                    );
                    $logAction = 'user_update';
                    $logEntityId = (int) $id;
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO users
                            (role_id, full_name, email, password_hash, student_number,
                             phone, department, status, created_by)
                         VALUES
                            (:role_id, :full_name, :email, :password_hash, :student_number,
                             :phone, :department, :status, :created_by)'
                    );
                    $statement->execute([
                        'role_id'        => role_id_for($pdo, (string) $result['values']['role']),
                        'full_name'      => $result['values']['full_name'],
                        'email'          => $result['values']['email'],
                        'password_hash'  => password_hash((string) $result['password'], PASSWORD_DEFAULT),
                        'student_number' => $result['values']['student_number'],
                        'phone'          => $result['values']['phone'],
                        'department'     => $result['values']['department'],
                        'status'         => $result['values']['status'],
                        'created_by'     => (int) $actor['id'],
                    ]);

                    $logEntityId = (int) $pdo->lastInsertId();
                    $logDescription = sprintf(
                        'Created %s account for %s.',
                        $result['values']['role'],
                        $result['values']['email']
                    );
                    $logAction = 'user_create';
                }

                $pdo->commit();

                if ($isEdit && $result['password'] !== null) {
                    refresh_session_version_for_user($pdo, (int) $actor['id'], (int) $id);
                }

                log_activity(
                    $pdo,
                    (int) $actor['id'],
                    $logAction,
                    'user',
                    $logEntityId,
                    $logDescription
                );

                flash('success', $isEdit ? 'The user account has been updated.' : 'The user account has been created.');
                redirect('admin/users.php');
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ($exception->getCode() === '23000' || $driverCode === 1062) {
                $errors['form'] = 'That email address or registration number is already registered.';
            } else {
                throw $exception;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}

$pageTitle = $isEdit ? 'Edit user account' : 'Add a user';
$activeNav = 'users';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-intro">
    <div>
        <h2><?= $isEdit ? 'Edit account' : 'New account' ?></h2>
        <p>
            <?= $isEdit
                ? 'Update role, status and contact information. Leave the password blank to keep the current one.'
                : 'Create a librarian, student or additional administrator account.' ?>
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/users.php')) ?>">Back to users</a>
    </div>
</div>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert--error" role="alert"><p><?= e($errors['form']) ?></p></div>
<?php endif; ?>

<div class="panel-grid">
    <section class="card span-8" aria-labelledby="user-form-title">
        <div class="card__header">
            <div>
                <h2 id="user-form-title"><?= $isEdit ? e($target['full_name']) : 'Account details' ?></h2>
                <?php if ($isEdit): ?>
                    <p>Created <?= e(format_datetime((string) $target['created_at'])) ?></p>
                <?php else: ?>
                    <p>All required fields are marked in their label.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="card__body">
            <?php if ($isSelf): ?>
                <div class="alert alert--info" role="note">
                    <p>This is your own account. Your role and status are protected and cannot be changed here.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('admin/user-form.php' . ($isEdit ? '?id=' . (int) $id : ''))) ?>" novalidate>
                <?= csrf_field() ?>
                <?php if ($isEdit): ?>
                    <input type="hidden" name="id" value="<?= e((int) $id) ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="field field--full">
                        <label for="full_name">Full name</label>
                        <input type="text" id="full_name" name="full_name" maxlength="120"
                               value="<?= e($values['full_name']) ?>" required
                               <?= isset($errors['full_name']) ? 'aria-invalid="true"' : '' ?>>
                        <?php if (isset($errors['full_name'])): ?><p class="field__error"><?= e($errors['full_name']) ?></p><?php endif; ?>
                    </div>

                    <div class="field field--full">
                        <label for="email">Email address</label>
                        <input type="email" id="email" name="email" maxlength="190"
                               value="<?= e($values['email']) ?>" required
                               <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>>
                        <p class="field__hint">Used as the sign-in identifier and must be unique.</p>
                        <?php if (isset($errors['email'])): ?><p class="field__error"><?= e($errors['email']) ?></p><?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="role">Role</label>
                        <?php if ($isSelf): ?>
                            <input type="hidden" name="role" value="<?= e($values['role']) ?>">
                        <?php endif; ?>
                        <select id="role" <?= $isSelf ? 'disabled' : 'name="role"' ?>
                                <?= isset($errors['role']) ? 'aria-invalid="true"' : '' ?>>
                            <?php foreach (role_options() as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $values['role'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['role'])): ?><p class="field__error"><?= e($errors['role']) ?></p><?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="status">Account status</label>
                        <?php if ($isSelf): ?>
                            <input type="hidden" name="status" value="<?= e($values['status']) ?>">
                        <?php endif; ?>
                        <select id="status" <?= $isSelf ? 'disabled' : 'name="status"' ?>
                                <?= isset($errors['status']) ? 'aria-invalid="true"' : '' ?>>
                            <option value="active" <?= $values['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $values['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                        <?php if (isset($errors['status'])): ?><p class="field__error"><?= e($errors['status']) ?></p><?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="student_number">Registration number</label>
                        <input type="text" id="student_number" name="student_number" maxlength="20"
                               placeholder="e.g. SIT/B/01-02392/2024" pattern="[A-Z]{3}/[BDCG]/[0-9]{2}-[0-9]{5}/[0-9]{4}"
                               title="MMUST format: XXX/Y/01-00000/YYYY, uppercase letters only"
                               value="<?= e($values['student_number']) ?>"
                               <?= isset($errors['student_number']) ? 'aria-invalid="true"' : '' ?>>
                        <p class="field__hint">Format: <strong>PROGRAMME/LEVEL/INTAKE-SERIAL/YEAR</strong>, e.g. <strong>SIT/B/01-02392/2024</strong>. Uppercase only &mdash; level: B Bachelor's, D Diploma, C Certificate, G Postgraduate. Required for students; cleared automatically for staff roles.</p>
                        <?php if (isset($errors['student_number'])): ?><p class="field__error"><?= e($errors['student_number']) ?></p><?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="phone">Phone number <span class="muted">(optional)</span></label>
                        <input type="text" id="phone" name="phone" maxlength="30"
                               value="<?= e($values['phone']) ?>"
                               <?= isset($errors['phone']) ? 'aria-invalid="true"' : '' ?>>
                        <?php if (isset($errors['phone'])): ?><p class="field__error"><?= e($errors['phone']) ?></p><?php endif; ?>
                    </div>

                    <div class="field field--full">
                        <label for="department">School / Department <span class="muted">(optional)</span></label>
                        <select id="department" name="department" <?= isset($errors['department']) ? 'aria-invalid="true"' : '' ?>>
                            <?= mmust_school_options((string) $values['department']) ?>
                        </select>
                        <?php if (isset($errors['department'])): ?><p class="field__error"><?= e($errors['department']) ?></p><?php endif; ?>
                    </div>
                </div>

                <hr class="divider">

                <p class="form-section__title"><?= $isEdit ? 'Reset password' : 'Initial password' ?></p>
                <div class="form-grid">
                    <div class="field">
                        <label for="password"><?= $isEdit ? 'New password (optional)' : 'Password' ?></label>
                        <input type="password" id="password" name="password" maxlength="72"
                               autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>
                               <?= isset($errors['password']) ? 'aria-invalid="true"' : '' ?>>
                        <p class="field__hint">At least 8 characters, including a letter and a number.</p>
                        <?php if (isset($errors['password'])): ?><p class="field__error"><?= e($errors['password']) ?></p><?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="password_confirm">Confirm password</label>
                        <input type="password" id="password_confirm" name="password_confirm" maxlength="72"
                               autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>
                               <?= isset($errors['password_confirm']) ? 'aria-invalid="true"' : '' ?>>
                        <?php if (isset($errors['password_confirm'])): ?><p class="field__error"><?= e($errors['password_confirm']) ?></p><?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create account' ?></button>
                    <a class="btn btn--ghost" href="<?= e(url('admin/users.php')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </section>

    <aside class="card span-4" aria-labelledby="guardrails-title">
        <div class="card__header">
            <div>
                <h2 id="guardrails-title">Account guardrails</h2>
            </div>
        </div>
        <div class="card__body">
            <div class="stack small">
                <p><strong>Last administrator.</strong> The final active administrator cannot be demoted or deactivated, even by another administrator.</p>
                <p><strong>Self-protection.</strong> You cannot demote or deactivate the account you are currently signed in with.</p>
                <p><strong>Deactivation over deletion.</strong> Inactive accounts keep their borrowing history and audit trail.</p>
                <p class="mb-0"><strong>Session expiry.</strong> A deactivated account loses access on its next request because the account is re-read from the database.</p>
            </div>
        </div>
    </aside>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
