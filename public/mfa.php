<?php
/**
 * Second factor for sign-in. Reached only after a correct password.
 */
require_once __DIR__ . '/../includes/init.php';

$pendingId = (int) ($_SESSION['mfa_pending_user_id'] ?? 0);
if ($pendingId < 1) {
    redirect('login.php');
}

$errors = [];
if (!isset($_SESSION['mfa_attempts'])) {
    $_SESSION['mfa_attempts'] = 0;
}

if (is_post()) {
    verify_csrf();

    if ((int) $_SESSION['mfa_attempts'] >= 5) {
        $errors['form'] = 'Too many incorrect codes. Please sign in again to restart.';
    } else {
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $ok = false;
        $kind = 'totp';

        if ($code !== '' && preg_match('/^\d{6}$/', $code)) {
            $row = mfa_row($pdo, $pendingId);
            $ok = $row !== null && mfa_verify_code((string) $row['secret'], $code);
        } elseif ($code !== '') {
            $ok = mfa_verify_recovery($pdo, $pendingId, $code);
            $kind = 'recovery';
        }

        if ($ok) {
            $statement = $pdo->prepare('SELECT * FROM users WHERE id = :id');
            $statement->execute(['id' => $pendingId]);
            $user = $statement->fetch();
            if ($user === false) {
                redirect('login.php');
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['last_activity'] = time();
            $_SESSION['session_version'] = (int) $user['session_version'];
            rotate_csrf_token();
            unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_attempts']);

            $now = date('Y-m-d H:i:s');
            $pdo->prepare('UPDATE users SET last_login_at = :now WHERE id = :id')
                ->execute(['now' => $now, 'id' => $user['id']]);
            log_activity($pdo, (int) $user['id'], $kind === 'recovery' ? 'mfa_recovery_used' : 'mfa_verified',
                'user', (int) $user['id'], 'Second factor verified at sign-in.');
            log_activity($pdo, (int) $user['id'], 'login', 'user', (int) $user['id'], 'User signed in.');

            flash('success', 'Welcome back, ' . (string) $user['full_name'] . '.');
            redirect(dashboard_path_for_role((string) $user['role_name']));
        }

        $_SESSION['mfa_attempts'] = (int) $_SESSION['mfa_attempts'] + 1;
        $errors['form'] = 'That code was not accepted. You can also use a recovery code.';
    }
}

$pageTitle = 'Two-factor sign in';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrap">
    <section class="auth-intro">
        <span class="hero__eyebrow">Account security</span>
        <h1>One more step.</h1>
        <p>Enter the 6-digit code from your authenticator app. If you cannot access it, use one of your recovery codes.</p>
    </section>
    <section class="auth-card" aria-labelledby="mfa-title">
        <h2 id="mfa-title">Two-factor verification</h2>
        <p class="auth-card__lead">Your password was correct. Confirm it is really you.</p>
        <?php if (!empty($errors['form'])): ?>
            <div class="alert alert--error" role="alert"><p><?= e($errors['form']) ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('mfa.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="code">Authenticator code or recovery code</label>
                <input type="text" id="code" name="code" inputmode="text" autocomplete="one-time-code"
                       required maxlength="32" autofocus>
                <p class="field__hint">6 digits, or a code like ABCD-EFGH.</p>
            </div>
            <div class="form-actions">
                <button class="btn btn--primary btn--block" type="submit">Verify</button>
            </div>
        </form>
        <div class="auth-meta"><p><a href="<?= e(url('login.php')) ?>">Cancel sign in</a></p></div>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
