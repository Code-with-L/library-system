<?php
/**
 * Public landing page.
 */
require_once __DIR__ . '/../includes/init.php';

$currentUser = current_user($pdo);
if ($currentUser !== null) {
    redirect(dashboard_path_for_role((string) $currentUser['role_name']));
}

$settings = get_settings($pdo);
$adminCount = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE r.name = 'admin'"
)->fetchColumn();

$setupAvailable = SETUP_TOKEN !== ''
    && $adminCount === 0
    && empty($settings['setup_completed_at']);

$pageTitle = 'Welcome';
$forceGuest = true;
require __DIR__ . '/../includes/header.php';
?>
<section class="hero">
    <div class="hero__content">
        <span class="hero__eyebrow">Campus Library Management</span>
        <h1>Borrowing, catalogue and accounts in one calm place.</h1>
        <p class="hero__lead">
            <?= e($settings['library_name']) ?> brings the catalogue, student borrowing
            records and staff workflows together. Phase 2 establishes secure accounts,
            role-based access and administrator user management.
        </p>

        <div class="hero__actions">
            <a class="btn btn--primary" href="<?= e(url('login.php')) ?>">Sign in to your account</a>
            <a class="btn btn--ghost" href="<?= e(url('register.php')) ?>">Create a student account</a>
            <?php if ($setupAvailable): ?>
                <a class="btn btn--secondary" href="<?= e(url('setup-admin.php')) ?>">Complete administrator setup</a>
            <?php endif; ?>
        </div>

        <p class="hero__note">
            Students register themselves. Librarian and administrator accounts are created by an administrator.
        </p>
    </div>

    <aside class="hero__card" aria-labelledby="hero-card-title">
        <h2 id="hero-card-title">What is available now</h2>
        <ul class="hero__list">
            <li>Secure sign-in with role-based dashboards</li>
            <li>Student self-registration and profile management</li>
            <li>Administrator user creation, editing and deactivation</li>
            <li>Activity auditing and last-administrator protection</li>
            <li>Catalogue and circulation foundations prepared for Phase 3</li>
        </ul>
    </aside>
</section>

<?php if (show_demo_credentials()): ?>
    <div class="review-banner" role="note">
        <span><strong>Development environment.</strong> Demonstration accounts are available for local review only.</span>
        <span>
            Librarian <code>librarian@campus.test</code> / <code>Librarian123!</code>
            &nbsp;&middot;&nbsp;
            Student <code>student@campus.test</code> / <code>Student123!</code>
        </span>
    </div>
<?php endif; ?>

<section class="panel-grid" aria-label="System foundations">
    <article class="card span-4">
        <div class="card__body">
            <h3>For students</h3>
            <p class="muted small">
                Create an account with your student number, keep your contact details current
                and review your borrowing activity from one dashboard.
            </p>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('register.php')) ?>">Register as a student</a>
        </div>
    </article>

    <article class="card span-4">
        <div class="card__body">
            <h3>For library staff</h3>
            <p class="muted small">
                Librarian accounts are issued by an administrator. Staff dashboards summarise
                the catalogue, pending requests and active loans.
            </p>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('login.php')) ?>">Staff sign in</a>
        </div>
    </article>

    <article class="card span-4">
        <div class="card__body">
            <h3>For administrators</h3>
            <p class="muted small">
                Administrators manage accounts, roles and access. The last active administrator
                cannot be demoted or deactivated.
            </p>
            <?php if ($setupAvailable): ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(url('setup-admin.php')) ?>">Set up the first administrator</a>
            <?php else: ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(url('login.php')) ?>">Administrator sign in</a>
            <?php endif; ?>
        </div>
    </article>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
