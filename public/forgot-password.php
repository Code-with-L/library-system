<?php
/**
 * Request a password reset link. Always returns the same generic confirmation.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/throttles.php';
require_guest($pdo);

$email = '';
$errors = [];

if (is_post()) {
    verify_csrf();
    $email = normalize_email((string) ($_POST['email'] ?? ''));

    $blocked = false;
    try {
        $blocked = reset_throttle_state($pdo, $email)['blocked'];
    } catch (PDOException $exception) {
        error_log('Reset throttle state unavailable: ' . $exception->getMessage());
    }

    if ($blocked) {
        $errors['form'] = 'Too many reset requests. Please wait one hour before trying again.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['form'] = 'Enter a valid email address.';
    } else {
        try {
            reset_throttle_record($pdo, $email);
        } catch (PDOException $exception) {
            error_log('Reset throttle record failed: ' . $exception->getMessage());
        }
        password_reset_request($pdo, $email);
        if (isset($_SESSION['dev_reset_link'])) {
            flash('info', 'Local reset link: ' . $_SESSION['dev_reset_link']);
            unset($_SESSION['dev_reset_link']);
        }
        flash('success', 'If that email address is registered, a reset link has been issued.');
        redirect('forgot-password.php');
    }
}

$pageTitle = 'Forgot password';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrap">
    <section class="auth-intro">
        <span class="hero__eyebrow">Account access</span>
        <h1>Reset your password.</h1>
        <p>Enter your account email address. If it is registered, you will receive a link to choose a new password.</p>
    </section>
    <section class="auth-card" aria-labelledby="forgot-title">
        <h2 id="forgot-title">Request a reset link</h2>
        <p class="auth-card__lead">The link is valid for 30 minutes and can be used once.</p>
        <?php if (!PASSWORD_RESET_ENABLED): ?>
            <div class="callout">Password reset is not enabled on this installation.</div>
        <?php endif; ?>
        <?php if (!empty($errors['form'])): ?>
            <div class="alert alert--error" role="alert"><p><?= e($errors['form']) ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('forgot-password.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>"
                       autocomplete="email" required maxlength="190">
            </div>
            <div class="form-actions">
                <button class="btn btn--primary btn--block" type="submit" <?= PASSWORD_RESET_ENABLED ? '' : 'disabled' ?>>Send reset link</button>
            </div>
        </form>
        <div class="auth-meta"><p><a href="<?= e(url('login.php')) ?>">Back to sign in</a></p></div>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
