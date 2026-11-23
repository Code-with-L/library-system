<?php
/**
 * Student email verification: accept a token link or request a new one.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/throttles.php';

if (is_post()) {
    verify_csrf();
    $account = require_login($pdo);

    $resendBlocked = false;
    try {
        $resendBlocked = verify_resend_throttle_state($pdo, (int) $account['id'])['blocked'];
    } catch (PDOException $exception) {
        error_log('Verification resend throttle unavailable: ' . $exception->getMessage());
    }
    if ($resendBlocked) {
        flash('error', 'Too many verification requests. Please wait one hour before trying again.');
        redirect('verify-email.php');
    }
    try {
        verify_resend_throttle_record($pdo, (int) $account['id']);
    } catch (PDOException $exception) {
        error_log('Verification resend throttle failed: ' . $exception->getMessage());
    }

    email_verification_send($pdo, (int) $account['id'], (string) $account['email']);
    flash('success', 'A new verification link has been issued.');
    if (isset($_SESSION['dev_verification_link'])) {
        flash('info', 'Local verification link: ' . $_SESSION['dev_verification_link']);
        unset($_SESSION['dev_verification_link']);
    }
    redirect('verify-email.php');
}

$token = (string) ($_GET['token'] ?? '');
if ($token !== '') {
    $result = email_verification_verify_token($pdo, $token);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    $account = current_user($pdo);
    redirect($account !== null ? dashboard_path_for_role((string) $account['role_name']) : 'login.php');
}

$account = current_user($pdo);
$verified = $account !== null && email_verification_is_verified($pdo, (int) $account['id']);
$pageTitle = 'Verify email';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrap">
    <section class="auth-intro">
        <span class="hero__eyebrow">Account verification</span>
        <h1>Confirm your email address.</h1>
        <p>Verification protects your borrowing account. Open the link sent to your email address, or request a new link below.</p>
        <?php if (!email_verification_required()): ?>
            <div class="callout">Email verification is not enabled on this installation yet.</div>
        <?php endif; ?>
    </section>
    <section class="auth-card" aria-labelledby="verify-title">
        <h2 id="verify-title">Email verification</h2>
        <p class="auth-card__lead">
            <?php if ($verified): ?>Your email address is already verified.
            <?php elseif ($account !== null): ?>Request a fresh verification link if you cannot find the email.
            <?php else: ?>Sign in to request a new verification link.<?php endif; ?>
        </p>
        <?php if ($account !== null && !$verified): ?>
            <form method="post" action="<?= e(url('verify-email.php')) ?>">
                <?= csrf_field() ?>
                <div class="form-actions">
                    <button class="btn btn--primary btn--block" type="submit">Send a new verification link</button>
                </div>
            </form>
        <?php endif; ?>
        <?php if ($account === null): ?>
            <div class="auth-meta"><p><a href="<?= e(url('login.php')) ?>">Sign in</a></p></div>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
