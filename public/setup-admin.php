<?php
/**
 * One-time initial administrator setup.
 *
 * Security properties:
 *  - The token comes from protected configuration, never from this file.
 *  - Setup is disabled when the configured token is empty.
 *  - A successful setup sets library_settings.setup_completed_at and creates
 *    an administrator, so the page cannot be used again.
 *  - Concurrent attempts serialize on the administrator role row, then
 *    re-check for an existing administrator and completion timestamp before
 *    inserting anything.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/users.php';
require_once __DIR__ . '/../includes/throttles.php';

if (SETUP_TOKEN === '') {
    abort_404('Administrator setup is not enabled on this installation.');
}

$settings = get_settings($pdo);
$adminCount = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE r.name = 'admin'"
)->fetchColumn();

$locked = $adminCount > 0 || !empty($settings['setup_completed_at']);
$errors = [];
$values = ['full_name' => '', 'email' => ''];

if (is_post()) {
    verify_csrf();

    if ($locked) {
        $errors['form'] = 'Administrator setup has already been completed.';
    } else {
        // Bound online guessing of the one-time token. Local dev is exempt.
        $setupBlocked = false;
        try {
            $setupBlocked = setup_throttle_state($pdo)['blocked'];
        } catch (PDOException $exception) {
            error_log('Setup throttle state unavailable: ' . $exception->getMessage());
        }
        if ($setupBlocked) {
            $errors['form'] = 'Too many setup attempts. Please wait one hour before trying again.';
        } else {
            try {
                setup_throttle_record($pdo);
            } catch (PDOException $exception) {
                error_log('Setup throttle record failed: ' . $exception->getMessage());
            }
        }

        $submittedToken = (string) ($_POST['setup_token'] ?? '');

        if (!hash_equals((string) SETUP_TOKEN, $submittedToken)) {
            $errors['setup_token'] = 'The setup token is not valid.';
        }

        $input = [
            'full_name'        => $_POST['full_name'] ?? '',
            'email'            => $_POST['email'] ?? '',
            'role'             => 'admin',
            'status'           => 'active',
            'student_number'   => '',
            'phone'            => '',
            'department'       => '',
            'password'         => $_POST['password'] ?? '',
            'password_confirm' => $_POST['password_confirm'] ?? '',
        ];

        $result = validate_user_management_input($pdo, $input, null, true);
        $errors = array_merge($errors, $result['errors']);

        $values = [
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'email'     => trim((string) ($_POST['email'] ?? '')),
        ];

        if ($errors === []) {
            try {
                $pdo->beginTransaction();

                // Serialize all administrator creation attempts.
                lock_admin_role($pdo);

                // Locking reads see the latest committed data after waiting.
                $adminStatement = $pdo->query(
                    "SELECT u.id
                     FROM users u
                     INNER JOIN roles r ON r.id = u.role_id
                     WHERE r.name = 'admin'
                     FOR UPDATE"
                );
                $existingAdmins = $adminStatement->fetchAll();

                $settingsStatement = $pdo->query(
                    'SELECT setup_completed_at FROM library_settings WHERE id = 1 FOR UPDATE'
                );
                $settingsRow = $settingsStatement->fetch();

                if ($settingsRow === false) {
                    throw new RuntimeException('The library settings row is missing.');
                }

                if ($existingAdmins !== [] || $settingsRow['setup_completed_at'] !== null) {
                    $pdo->rollBack();
                    $locked = true;
                    $errors['form'] = 'Administrator setup has already been completed.';
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO users
                            (role_id, full_name, email, password_hash, status)
                         VALUES
                            (:role_id, :full_name, :email, :password_hash, :status)'
                    );
                    $insert->execute([
                        'role_id'       => role_id_for($pdo, 'admin'),
                        'full_name'     => $result['values']['full_name'],
                        'email'         => $result['values']['email'],
                        'password_hash' => password_hash((string) $result['password'], PASSWORD_DEFAULT),
                        'status'        => 'active',
                    ]);

                    $newUserId = (int) $pdo->lastInsertId();

                    $pdo->prepare(
                        'UPDATE library_settings SET setup_completed_at = NOW() WHERE id = 1'
                    )->execute();

                    $pdo->commit();

                    log_activity(
                        $pdo,
                        $newUserId,
                        'setup',
                        'user',
                        $newUserId,
                        'Initial administrator account created; setup token consumed.'
                    );

                    flash('success', 'Administrator setup is complete. Sign in with the account you just created.');
                    redirect('login.php');
                }
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $driverCode = (int) ($exception->errorInfo[1] ?? 0);
                if ($exception->getCode() === '23000' || $driverCode === 1062) {
                    $errors['email'] = 'That email address is already registered.';
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
}

if ($locked) {
    http_response_code(403);
}

$pageTitle = 'Administrator setup';
$forceGuest = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrap">
    <section class="auth-intro">
        <span class="hero__eyebrow">One-time installation step</span>
        <h1>Create the first administrator.</h1>
        <p>
            This page works only once. It is protected by a setup token supplied through
            protected configuration, and it is permanently closed after the first
            administrator account is created.
        </p>
        <div class="auth-aside">
            <strong>Security notes</strong>
            <ul>
                <li>The setup token is never stored in this page or in the database.</li>
                <li>A successful setup records a completion timestamp.</li>
                <li>Concurrent setup attempts are serialized with a database transaction.</li>
                <li>After setup, use the administrator dashboard to create staff accounts.</li>
            </ul>
        </div>
    </section>

    <section class="auth-card" aria-labelledby="setup-title">
        <h2 id="setup-title">Administrator setup</h2>

        <?php if ($locked): ?>
            <div class="alert alert--info" role="status">
                <p>Setup has already been completed. Sign in with your administrator account instead.</p>
            </div>
            <div class="auth-meta">
                <p><a class="btn btn--primary btn--block" href="<?= e(url('login.php')) ?>">Go to sign in</a></p>
                <p class="small">If you have lost the administrator password, recovery must be handled directly by the site administrator.</p>
            </div>
        <?php else: ?>
            <p class="auth-card__lead">
                Enter the setup token from the protected configuration file, then choose the first administrator account.
            </p>

            <?php if (!empty($errors['form'])): ?>
                <div class="alert alert--error" role="alert"><p><?= e($errors['form']) ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('setup-admin.php')) ?>" novalidate autocomplete="off">
                <?= csrf_field() ?>

                <div class="field">
                    <label for="setup_token">Setup token</label>
                    <input type="password" id="setup_token" name="setup_token" required
                           autocomplete="off"
                           <?= isset($errors['setup_token']) ? 'aria-invalid="true"' : '' ?>>
                    <p class="field__hint">Loaded from the protected configuration with the <code>SETUP_TOKEN</code> setting.</p>
                    <?php if (isset($errors['setup_token'])): ?><p class="field__error"><?= e($errors['setup_token']) ?></p><?php endif; ?>
                </div>

                <div class="field">
                    <label for="full_name">Administrator full name</label>
                    <input type="text" id="full_name" name="full_name" maxlength="120"
                           value="<?= e($values['full_name']) ?>" required
                           <?= isset($errors['full_name']) ? 'aria-invalid="true"' : '' ?>>
                    <?php if (isset($errors['full_name'])): ?><p class="field__error"><?= e($errors['full_name']) ?></p><?php endif; ?>
                </div>

                <div class="field">
                    <label for="email">Administrator email</label>
                    <input type="email" id="email" name="email" maxlength="190"
                           value="<?= e($values['email']) ?>" required
                           <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>>
                    <?php if (isset($errors['email'])): ?><p class="field__error"><?= e($errors['email']) ?></p><?php endif; ?>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" maxlength="72"
                           autocomplete="new-password" required
                           <?= isset($errors['password']) ? 'aria-invalid="true"' : '' ?>>
                    <p class="field__hint">At least 8 characters, including a letter and a number.</p>
                    <?php if (isset($errors['password'])): ?><p class="field__error"><?= e($errors['password']) ?></p><?php endif; ?>
                </div>

                <div class="field">
                    <label for="password_confirm">Confirm password</label>
                    <input type="password" id="password_confirm" name="password_confirm" maxlength="72"
                           autocomplete="new-password" required
                           <?= isset($errors['password_confirm']) ? 'aria-invalid="true"' : '' ?>>
                    <?php if (isset($errors['password_confirm'])): ?><p class="field__error"><?= e($errors['password_confirm']) ?></p><?php endif; ?>
                </div>

                <div class="form-actions">
                    <button class="btn btn--primary btn--block" type="submit">Create administrator</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
