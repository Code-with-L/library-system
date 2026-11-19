<?php
/**
 * Administrator dashboard.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_role($pdo, 'admin');
$settings = get_settings($pdo);

$roleRows = $pdo->query(
    "SELECT r.name AS role_name,
            COUNT(u.id) AS total,
            SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END) AS active_count
     FROM roles r
     LEFT JOIN users u ON u.role_id = r.id
     GROUP BY r.id, r.name
     ORDER BY r.id"
)->fetchAll();

$totals = [
    'users'  => 0,
    'active' => 0,
];
foreach ($roleRows as $row) {
    $totals['users'] += (int) $row['total'];
    $totals['active'] += (int) $row['active_count'];
}

$counts = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM books WHERE status = 'active') AS books,
        (SELECT COUNT(*) FROM book_copies) AS copies,
        (SELECT COUNT(*) FROM book_copies WHERE status = 'available') AS available_copies,
        (SELECT COUNT(*) FROM borrowings WHERE status = 'active') AS active_loans,
        (SELECT COUNT(*) FROM borrowings WHERE status = 'active' AND due_date < CURDATE()) AS overdue_loans,
        (SELECT COUNT(*) FROM borrow_requests WHERE status = 'pending') AS pending_requests,
        (SELECT COUNT(*) FROM borrow_requests WHERE status = 'approved') AS approved_requests"
)->fetch();

$fineStats = fines_outstanding_total_all($pdo);

$recentActivity = $pdo->query(
    'SELECT a.action, a.description, a.created_at, a.entity_type, a.entity_id,
            u.full_name
     FROM activity_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.id DESC
     LIMIT 8'
)->fetchAll();

$dbVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

$pageTitle = 'Administrator dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-intro">
    <div>
        <h2>System overview</h2>
        <p>Accounts, catalogue readiness and recent administrative activity.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn--primary btn--sm" href="<?= e(url('admin/user-form.php')) ?>">Add a user</a>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/users.php')) ?>">Manage users</a>
    </div>
</div>

<div class="stat-grid">
    <article class="stat">
        <p class="stat__label">Total users</p>
        <p class="stat__value"><?= e($totals['users']) ?></p>
        <p class="stat__hint"><?= e($totals['active']) ?> active accounts</p>
    </article>
    <article class="stat stat--gold">
        <p class="stat__label">Catalogue titles</p>
        <p class="stat__value"><?= e($counts['books']) ?></p>
        <p class="stat__hint"><?= e($counts['copies']) ?> physical copies</p>
    </article>
    <article class="stat">
        <p class="stat__label">Available copies</p>
        <p class="stat__value"><?= e($counts['available_copies']) ?></p>
        <p class="stat__hint">Ready to be issued</p>
    </article>
    <article class="stat stat--info">
        <p class="stat__label">Active loans</p>
        <p class="stat__value"><?= e((int) $counts['active_loans']) ?></p>
        <p class="stat__hint"><?= e((int) $counts['pending_requests']) ?> pending requests</p>
    </article>
    <article class="stat stat--danger">
        <p class="stat__label">Outstanding fines</p>
        <p class="stat__value"><?= e((int) $fineStats['outstanding_count']) ?></p>
        <p class="stat__hint"><?= e(fines_format((float) $fineStats['outstanding_balance'], 'KES')) ?> outstanding</p>
    </article>
    <article class="stat">
        <p class="stat__label">Settled fines</p>
        <p class="stat__value"><?= e((int) $fineStats['settled_count']) ?></p>
        <p class="stat__hint">Historical records</p>
    </article>
    <article class="stat stat--gold">
        <p class="stat__label">Approved awaiting issue</p>
        <p class="stat__value"><?= e((int) $counts['approved_requests']) ?></p>
        <p class="stat__hint">Catalogue circulation</p>
    </article>
    <article class="stat<?= (int) $counts['overdue_loans'] > 0 ? ' stat--danger' : '' ?>">
        <p class="stat__label">Overdue loans</p>
        <p class="stat__value"><?= e((int) $counts['overdue_loans']) ?></p>
        <p class="stat__hint">Past their due date</p>
    </article>
</div>

<div class="panel-grid">
    <section class="card span-7" aria-labelledby="role-summary-title">
        <div class="card__header">
            <div>
                <h2 id="role-summary-title">Accounts by role</h2>
                <p>Active accounts can sign in immediately.</p>
            </div>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/users.php')) ?>">Open user list</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Role</th>
                        <th scope="col">Total</th>
                        <th scope="col">Active</th>
                        <th scope="col">Inactive</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roleRows as $row): ?>
                        <tr>
                            <td><span class="table__primary"><?= e(role_label((string) $row['role_name'])) ?></span></td>
                            <td><?= e($row['total']) ?></td>
                            <td><?= e($row['active_count']) ?></td>
                            <td><?= e((int) $row['total'] - (int) $row['active_count']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card span-5" aria-labelledby="environment-title">
        <div class="card__header">
            <div>
                <h2 id="environment-title">Environment</h2>
                <p>Verified during Phase 2 development.</p>
            </div>
        </div>
        <div class="card__body">
            <div class="stack">
                <div>
                    <p class="stat__label">PHP runtime</p>
                    <p class="mb-0 mono"><?= e(PHP_VERSION) ?></p>
                </div>
                <div>
                    <p class="stat__label">Database server</p>
                    <p class="mb-0 mono"><?= e($dbVersion) ?></p>
                </div>
                <div>
                    <p class="stat__label">Administrator setup</p>
                    <p class="mb-0">
                        <?php if (!empty($settings['setup_completed_at'])): ?>
                            <span class="badge badge--success">Completed</span>
                            <span class="small muted"><?= e(format_datetime((string) $settings['setup_completed_at'])) ?></span>
                        <?php else: ?>
                            <span class="badge badge--warning">Pending</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <p class="stat__label">Application environment</p>
                    <p class="mb-0"><span class="badge badge--info"><?= e(APP_ENV) ?></span></p>
                </div>
            </div>
        </div>
    </section>

    <section class="card span-12" aria-labelledby="activity-title">
        <div class="card__header">
            <div>
                <h2 id="activity-title">Recent activity</h2>
                <p>The eight most recent audit-log entries.</p>
            </div>
        </div>
        <?php if ($recentActivity === []): ?>
            <div class="empty-state">
                <h3>No activity recorded yet</h3>
                <p>Sign-ins, account changes and setup events will appear here.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Actor</th>
                            <th scope="col">Action</th>
                            <th scope="col">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentActivity as $entry): ?>
                            <tr>
                                <td class="nowrap"><?= e(format_datetime((string) $entry['created_at'])) ?></td>
                                <td><?= e($entry['full_name'] ?? 'System') ?></td>
                                <td><span class="badge badge--muted"><?= e(status_label((string) $entry['action'])) ?></span></td>
                                <td><?= e($entry['description']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
