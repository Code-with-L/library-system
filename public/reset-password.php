<?php
/**
 * Choose a new password using a one-time emailed token.
 */
require_once __DIR__ . '/../includes/init.php';
require_guest($pdo);

$token = (string) ($_GET['token'] ?? '');
if (is_post()) {
    verify_csrf();
    $token = (string) ($_POST['token'] ?? '');
    $result = password_reset_complete(
        $pdo,
        $token,
        (string) ($_POST['password'] ?? ''),
        (string) ($_POST['password_confirm'] ?? '')
    );
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect($result['ok'] ? 'login.php' : 'reset-password.php?token=' . urlencode($token));
}

$row = $token !== '' ? password_reset_row($pdo, $token) : null;
$valid = $row !== null && strtotime((string) $row['expires_at']) >= time();
$pageTitle = 'Choose a new password';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrap">
    <section class="auth-intro">
        <span class="hero__eyebrow">Account access</span>
        <h1>Choose a new password.</h1>
        <p>Use at least 8 characters, including a letter and a number. Your new password must not exceed 72 characters.</p>
    </section>
    <section class="auth-card" aria-labelledby="reset-title">
        <h2 id="reset-title">New password</h2>
        <p class="auth-card__lead">This link works once and expires after 30 minutes.</p>
        <?php if (!$valid): ?>
            <div class="alert alert--error" role="alert"><p>That reset link is invalid or has expired.</p></div>
            <div class="auth-meta"><p><a href="<?= e(url('forgot-password.php')) ?>">Request a new link</a></p></div>
        <?php else: ?>
            <form method="post" action="<?= e(url('reset-password.php')) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="field">
                    <label for="password">New password</label>
                    <input type="password" id="password" name="password" autocomplete="new-password"
                           required maxlength="72">
                </div>
                <div class="field">
                    <label for="password_confirm">Confirm new password</label>
                    <input type="password" id="password_confirm" name="password_confirm"
                           autocomplete="new-password" required maxlength="72">
                </div>
                <div class="form-actions">
                    <button class="btn btn--primary btn--block" type="submit">Save new password</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
