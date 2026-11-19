<?php
/**
 * Signed-in account profile and password management.
 */
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/users.php';

$user = require_login($pdo);

$profileErrors = [];
$passwordErrors = [];
$profileValues = [
    'full_name'  => (string) $user['full_name'],
    'phone'      => (string) ($user['phone'] ?? ''),
    'department' => (string) ($user['department'] ?? ''),
];

if (is_post()) {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'profile') {
        $profileValues = [
            'full_name'  => trim((string) ($_POST['full_name'] ?? '')),
            'phone'      => trim((string) ($_POST['phone'] ?? '')),
            'department' => trim((string) ($_POST['department'] ?? '')),
        ];

        if (mb_strlen($profileValues['full_name']) < 2 || mb_strlen($profileValues['full_name']) > 120) {
            $profileErrors['full_name'] = 'Enter a full name between 2 and 120 characters.';
        }

        if (mb_strlen($profileValues['phone']) > 30) {
            $profileErrors['phone'] = 'Phone number must not exceed 30 characters.';
        }

        if (mb_strlen($profileValues['department']) > 100) {
            $profileErrors['department'] = 'Department must not exceed 100 characters.';
        }

        if ($profileErrors === []) {
            $statement = $pdo->prepare(
                'UPDATE users
                 SET full_name = :full_name, phone = :phone, department = :department
                 WHERE id = :id'
            );
            $statement->execute([
                'full_name'  => $profileValues['full_name'],
                'phone'      => $profileValues['phone'] === '' ? null : $profileValues['phone'],
                'department' => $profileValues['department'] === '' ? null : $profileValues['department'],
                'id'         => (int) $user['id'],
            ]);

            log_activity(
                $pdo,
                (int) $user['id'],
                'profile_update',
                'user',
                (int) $user['id'],
                'Account profile details updated.'
            );

            flash('success', 'Your profile has been updated.');
            redirect('account/profile.php');
        }
    } elseif ($action === 'password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['new_password_confirm'] ?? '');

        if ($currentPassword === '' || !password_verify($currentPassword, (string) $user['password_hash'])) {
            $passwordErrors['current_password'] = 'Your current password is not correct.';
        }

        if ($newPassword !== $confirmPassword) {
            $passwordErrors['new_password_confirm'] = 'The new password confirmation does not match.';
        } else {
            $passwordError = password_error($newPassword);
            if ($passwordError !== null) {
                $passwordErrors['new_password'] = $passwordError;
            }
        }

        if ($passwordErrors === []) {
            $newVersion = update_user_password($pdo, (int) $user['id'], $newPassword);

            log_activity(
                $pdo,
                (int) $user['id'],
                'password_change',
                'user',
                (int) $user['id'],
                'Account password changed.'
            );

            remember_current_session_version((int) $user['id'], (int) $user['id'], $newVersion);
            rotate_csrf_token();
            flash('success', 'Your password has been changed.');
            redirect('account/profile.php');
        }
    } else {
        abort_403('Unknown profile action.');
    }
}

$user = find_user_by_id($pdo, (int) $user['id']) ?? $user;

$pageTitle = 'My profile';
$activeNav = 'profile';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-intro">
    <div>
        <h2>Account details</h2>
        <p>Keep your contact information current. Your role and email address are managed by an administrator.</p>
    </div>
    <div class="page-actions">
        <span class="badge <?= e(badge_class((string) $user['status'])) ?>"><?= e(status_label((string) $user['status'])) ?></span>
        <span class="badge badge--info"><?= e(role_label((string) $user['role_name'])) ?></span>
    </div>
</div>

<div class="panel-grid">
    <section class="card span-7" aria-labelledby="profile-details-title">
        <div class="card__header">
            <div>
                <h2 id="profile-details-title">Profile information</h2>
                <p>Visible to library staff when managing your account.</p>
            </div>
        </div>
        <div class="card__body">
            <?php if ($profileErrors !== []): ?>
                <div class="alert alert--error" role="alert"><p>Please correct the highlighted fields.</p></div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('account/profile.php')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile">

                <div class="form-grid">
                    <div class="field field--full">
                        <label for="full_name">Full name</label>
                        <input type="text" id="full_name" name="full_name" maxlength="120"
                               value="<?= e($profileValues['full_name']) ?>" required
                               <?= isset($profileErrors['full_name']) ? 'aria-invalid="true"' : '' ?>>
                        <?php if (isset($profileErrors['full_name'])): ?><p class="field__error"><?= e($profileErrors['full_name']) ?></p><?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="email_display">Email address</label>
                        <input type="email" id="email_display" value="<?= e($user['email']) ?>" disabled>
                        <p class="field__hint">Contact an administrator to change your email address.</p>
                    </div>

                    <div class="field">
                        <label for="student_number_display">Student number</label>
                        <input type="text" id="student_number_display"
                               value="<?= e($user['student_number'] ?? '—') ?>" disabled>
                        <p class="field__hint">Student numbers are managed by library staff.</p>
                    </div>

                    <div class="field">
                        <label for="phone">Phone number</label>
                        <input type="text" id="phone" name="phone" maxlength="30"
                               value="<?= e($profileValues['phone']) ?>" autocomplete="tel"
                               <?= isset($profileErrors['phone']) ? 'aria-invalid="true"' : '' ?>>
                        <?php if (isset($profileErrors['phone'])): ?><p class="field__error"><?= e($profileErrors['phone']) ?></p><?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="department">Department</label>
                        <input type="text" id="department" name="department" maxlength="100"
                               value="<?= e($profileValues['department']) ?>"
                               <?= isset($profileErrors['department']) ? 'aria-invalid="true"' : '' ?>>
                        <?php if (isset($profileErrors['department'])): ?><p class="field__error"><?= e($profileErrors['department']) ?></p><?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn--primary" type="submit">Save profile</button>
                </div>
            </form>
        </div>
    </section>

    <section class="card span-5" aria-labelledby="password-title">
        <div class="card__header">
            <div>
                <h2 id="password-title">Change password</h2>
                <p>Use a unique password you do not use elsewhere.</p>
            </div>
        </div>
        <div class="card__body">
            <?php if ($passwordErrors !== []): ?>
                <div class="alert alert--error" role="alert"><p>Please correct the highlighted fields.</p></div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('account/profile.php')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">

                <div class="field">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" maxlength="72"
                           autocomplete="current-password" required
                           <?= isset($passwordErrors['current_password']) ? 'aria-invalid="true"' : '' ?>>
                    <?php if (isset($passwordErrors['current_password'])): ?><p class="field__error"><?= e($passwordErrors['current_password']) ?></p><?php endif; ?>
                </div>

                <div class="field">
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password" maxlength="72"
                           autocomplete="new-password" required
                           <?= isset($passwordErrors['new_password']) ? 'aria-invalid="true"' : '' ?>>
                    <p class="field__hint">At least 8 characters, including a letter and a number.</p>
                    <?php if (isset($passwordErrors['new_password'])): ?><p class="field__error"><?= e($passwordErrors['new_password']) ?></p><?php endif; ?>
                </div>

                <div class="field">
                    <label for="new_password_confirm">Confirm new password</label>
                    <input type="password" id="new_password_confirm" name="new_password_confirm" maxlength="72"
                           autocomplete="new-password" required
                           <?= isset($passwordErrors['new_password_confirm']) ? 'aria-invalid="true"' : '' ?>>
                    <?php if (isset($passwordErrors['new_password_confirm'])): ?><p class="field__error"><?= e($passwordErrors['new_password_confirm']) ?></p><?php endif; ?>
                </div>

                <div class="form-actions">
                    <button class="btn btn--primary" type="submit">Change password</button>
                </div>
            </form>

            <div class="callout mt-2">
                <p>Changing your password rotates this session's form token. Other sessions are not affected in Phase 2.</p>
            </div>
        </div>
    </section>

    <section class="card span-12">
        <div class="card__header">
            <div>
                <h2>Account timeline</h2>
                <p>Read-only information about this account.</p>
            </div>
        </div>
        <div class="card__body">
            <div class="panel-grid">
                <div class="span-4">
                    <p class="stat__label">Created</p>
                    <p class="mb-0"><?= e(format_datetime((string) $user['created_at'])) ?></p>
                </div>
                <div class="span-4">
                    <p class="stat__label">Last sign-in</p>
                    <p class="mb-0"><?= e(format_datetime($user['last_login_at'] !== null ? (string) $user['last_login_at'] : null)) ?></p>
                </div>
                <div class="span-4">
                    <p class="stat__label">Role</p>
                    <p class="mb-0"><?= e(role_label((string) $user['role_name'])) ?></p>
                </div>
            </div>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
