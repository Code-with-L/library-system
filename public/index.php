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
        <span class="hero__eyebrow">Campus Library System</span>
        <h1>Search, borrow, manage &mdash; all in one system.</h1>
        <p class="hero__lead">
            <?= e($settings['library_name']) ?> brings the book catalogue, student borrowing
            records and staff workflows together. Students keep track of their own loans and
            fines; librarians manage requests, issuing, returns and reports; administrators
            oversee accounts, settings and activity.
        </p>

        <div class="hero__actions">
            <a class="btn btn--primary" href="<?= e(url('login.php')) ?>">Sign in</a>
            <a class="btn btn--ghost" href="<?= e(url('register.php')) ?>">Register as a student</a>
            <?php if ($setupAvailable): ?>
                <a class="btn btn--secondary" href="<?= e(url('setup-admin.php')) ?>">Set up the first administrator</a>
            <?php endif; ?>
        </div>

        <p class="hero__note">
            Students create their own account. Library staff and administrators sign in with
            the account details issued to them.
        </p>
    </div>

    <aside class="hero__card" aria-labelledby="hero-card-title">
        <h2 id="hero-card-title">What the system does</h2>
        <ul class="hero__list">
            <li>Find books by title, author or ISBN and see which copies are available.</li>
            <li>Students request a title online and follow it until the book is issued.</li>
            <li>Librarians approve requests, issue copies, record returns and manage fines.</li>
            <li>Administrators manage accounts, loan rules and library settings.</li>
            <li>Reports and CSV exports summarise lending, overdue items, stock and fines.</li>
            <li>Secure sign-in protects each account, and key actions are recorded for review.</li>
        </ul>
    </aside>
</section>

<section class="panel-grid" aria-label="Who the system is for">
    <article class="card span-4">
        <div class="card__body">
            <h3>For students</h3>
            <p class="muted small">
                Register with your registration number, then sign in to search the catalogue,
                request titles, follow your loans and fines, and keep your profile up to date.
            </p>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('register.php')) ?>">Create a student account</a>
        </div>
    </article>

    <article class="card span-4">
        <div class="card__body">
            <h3>For library staff</h3>
            <p class="muted small">
                Librarians manage categories, books and copies; review and approve requests;
                issue and return stock; track overdue fines; and run reports with CSV export.
            </p>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('login.php')) ?>">Staff sign in</a>
        </div>
    </article>

    <article class="card span-4">
        <div class="card__body">
            <h3>For administrators</h3>
            <p class="muted small">
                Administrators manage staff and student accounts, roles and access, review the
                activity log, configure loan and fine settings, and view system-wide reports.
                The last active administrator cannot be demoted or deactivated.
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
