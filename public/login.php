<?php
/**
 * Sign-in page.
 */
require_once __DIR__ . '/../includes/init.php';
require_guest($pdo);

$settings = get_settings($pdo);
$email = '';
$errors = [];

$throttle = $_SESSION['login_throttle'] ?? ['count' => 0, 'first' => time()];
if ((time() - (int) $throttle['first']) >= 900) {
    $throttle = ['count' => 0, 'first' => time()];
}

$blocked = (int) $throttle['count'] >= 5;

if (is_post()) {
    verify_csrf();

    $email = normalize_email((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($blocked) {
        log_failed_login($pdo, $email);
        $errors['form'] = 'Too many failed sign-in attempts. Please wait 15 minutes before trying again.';
    } elseif ($email === '' || $password === '') {
        $errors['form'] = 'Enter both your email address and password.';
    } else {
        $user = login_user($pdo, $email, $password);

        if ($user !== null) {
            unset($_SESSION['login_throttle']);
            flash('success', 'Welcome back, ' . $user['full_name'] . '.');
            redirect(dashboard_path_for_role((string) $user['role_name']));
        }

        $throttle['count'] = (int) $throttle['count'] + 1;
        $_SESSION['login_throttle'] = $throttle;
        $errors['form'] = 'Invalid email address or password.';
    }
}

$adminCount = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE r.name = 'admin'"
)->fetchColumn();
$setupAvailable = SETUP_TOKEN !== '' && $adminCount === 0 && empty($settings['setup_completed_at']);

$pageTitle = 'Sign in';
$forceGuest = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrap">
    <section class="auth-intro">
        <span class="hero__eyebrow">Campus Library</span>
        <h1>Your library account, ready when you are.</h1>
        <p>
            Sign in to reach your dashboard, manage your profile and, in later phases,
            request titles and track your borrowing.
        </p>

        <?php if (show_demo_credentials()): ?>
            <div class="demo-box">
                <strong>Local demonstration accounts</strong>
                Librarian: <code>librarian@campus.test</code> / <code>Librarian123!</code><br>
                Student: <code>student@campus.test</code> / <code>Student123!</code>
            </div>
        <?php endif; ?>

        <?php if ($setupAvailable): ?>
            <div class="auth-aside">
                <strong>First-time installation?</strong>
                <p class="mb-0">No administrator exists yet. Complete the one-time setup using the token from the protected configuration.</p>
                <p class="mb-0"><a href="<?= e(url('setup-admin.php')) ?>">Open administrator setup</a></p>
            </div>
        <?php endif; ?>
    </section>

    <section class="auth-card" aria-labelledby="login-title">
        <h2 id="login-title">Sign in</h2>
        <p class="auth-card__lead">Use the email address and password issued to you.</p>

        <?php if (!empty($errors['form'])): ?>
            <div class="alert alert--error" role="alert">
                <p><?= e($errors['form']) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($blocked && !is_post()): ?>
            <div class="alert alert--warning" role="alert">
                <p>Too many failed attempts. Please wait 15 minutes before trying again.</p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('login.php')) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="field">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>"
                       autocomplete="username" required autofocus maxlength="190">
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required maxlength="72">
                <p class="field__hint">
                    <button class="btn btn--link btn--sm" type="button" data-toggle-password="password"
                            aria-pressed="false">Show</button>
                </p>
            </div>

            <div class="form-actions">
                <button class="btn btn--primary btn--block" type="submit" <?= $blocked ? 'disabled' : '' ?>>
                    Sign in
                </button>
            </div>
        </form>

        <div class="auth-meta">
            <p>New student? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>
            <p class="small">Staff accounts are issued by an administrator.</p>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
