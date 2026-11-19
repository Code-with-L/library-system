<?php
/**
 * Administrator system reports: role totals, catalogue, circulation, fines, activity.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_role($pdo, 'admin');

$users = reporting_admin_users($pdo);
$catalogue = reporting_catalogue($pdo);
$overdue = reporting_overdue($pdo, [], 100);
$fines = reporting_fines($pdo, ['status' => 'all', 'from' => '', 'to' => ''], 1, 100000);
$activity = reporting_activity($pdo, ['action' => '', 'user_id' => '', 'entity_id' => '', 'from' => '', 'to' => ''], 1, 20);

$pageTitle = 'System reports';
$activeNav = 'reports';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro"><div><h2>System reports</h2><p>System-wide catalogue, circulation, fines and activity reporting.</p></div></div>

<div class="stat-grid">
    <article class="stat"><p class="stat__label">Users</p><p class="stat__value"><?= e((string) array_sum(array_map(fn($r) => (int) $r['total'], $users))) ?></p><p class="stat__hint">by role</p></article>
    <article class="stat"><p class="stat__label">Active titles</p><p class="stat__value"><?= e((string) $catalogue['titles']) ?></p><p class="stat__hint">active catalogue</p></article>
    <article class="stat stat--gold"><p class="stat__label">Available copies</p><p class="stat__value"><?= e((string) $catalogue['statuses']['available']) ?></p><p class="stat__hint">ready for issue</p></article>
    <article class="stat stat--info"><p class="stat__label">Active loans</p><p class="stat__value"><?= e((string) $catalogue['statuses']['issued']) ?></p><p class="stat__hint">issued</p></article>
    <article class="stat stat--danger"><p class="stat__label">Overdue loans</p><p class="stat__value"><?= e((string) $overdue['total']) ?></p><p class="stat__hint">active overdue</p></article>
    <article class="stat stat--danger"><p class="stat__label">Outstanding fines</p><p class="stat__value"><?= e((string) $fines['outstanding_count']) ?></p><p class="stat__hint"><?= e(fines_format($fines['outstanding_total'], 'KES')) ?></p></article>
    <article class="stat"><p class="stat__label">Activity records</p><p class="stat__value"><?= e((string) $activity['total']) ?></p><p class="stat__hint">recent audit trail</p></article>
</div>

<div class="panel-grid">
    <section class="card span-6" aria-labelledby="admin-roles-title"><div class="card__header"><h2 id="admin-roles-title">Users by role</h2></div><div class="card__body"><div class="table-wrap"><table class="table"><thead><tr><th>Role</th><th>Total</th><th>Active</th></tr></thead><tbody><?php foreach ($users as $row): ?><tr><td><?= e((string) $row['role_name']) ?></td><td><?= e((string) $row['total']) ?></td><td><?= e((string) $row['active_count']) ?></td></tr><?php endforeach; ?></tbody></table></div></div></section>

    <section class="card span-6" aria-labelledby="admin-overdue-title"><div class="card__header"><h2 id="admin-overdue-title">Overdue loans</h2><p><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/reports/overdue.php')) ?>">View librarian overdue report</a></p></div><div class="card__body"><div class="table-wrap"><table class="table"><thead><tr><th>Borrowing</th><th>Student</th><th>Book</th><th>Copy</th><th>Due</th><th>Days</th><th>Fine</th></tr></thead><tbody><?php foreach ($overdue['rows'] as $row): ?><tr><td class="mono"><?= e((string) $row['borrowing_id']) ?></td><td><?= e((string) $row['student_name']) ?></td><td><?= e((string) $row['book_title']) ?></td><td><code><?= e((string) $row['copy_code']) ?></code></td><td class="nowrap"><?= e(format_date((string) $row['due_date'])) ?></td><td class="nowrap overdue-cell"><?= e((string) $row['days_overdue']) ?></td><td class="nowrap"><?= $row['fine_id'] ? e(fines_format((float) $row['fine_amount'], 'KES')) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div></div></section>

    <section class="card span-6" aria-labelledby="admin-fines-title"><div class="card__header"><h2 id="admin-fines-title">Fines</h2><p><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/reports/fines.php')) ?>">View librarian fines report</a></p></div><div class="card__body"><div class="stat-grid" style="margin-top:16px;"><article class="stat stat--danger"><p class="stat__label">Outstanding</p><p class="stat__value"><?= e((string) $fines['outstanding_count']) ?></p><p class="stat__hint"><?= e(fines_format($fines['outstanding_total'], 'KES')) ?></p></article><article class="stat"><p class="stat__label">Settled</p><p class="stat__value"><?= e((string) $fines['settled_count']) ?></p><p class="stat__hint"><?= e(fines_format($fines['settled_total'], 'KES')) ?></p></article></div></div></section>

    <section class="card span-6" aria-labelledby="admin-activity-title"><div class="card__header"><h2 id="admin-activity-title">Recent activity</h2><p><a class="btn btn--ghost btn--sm" href="<?= e(url('admin/activity-log.php')) ?>">Open activity log</a></p></div><div class="card__body"><div class="table-wrap"><table class="table"><thead><tr><th>Time</th><th>Action</th><th>Entity</th><th>Description</th></tr></thead><tbody><?php foreach ($activity['rows'] as $row): ?><tr><td class="nowrap"><?= e(format_datetime((string) $row['created_at'])) ?></td><td><span class="badge badge--info"><?= e((string) $row['action']) ?></span></td><td><?= e((string) $row['entity_type']) ?> #<?= e((string) $row['entity_id']) ?></td><td><?= e((string) $row['description']) ?></td></tr><?php endforeach; ?></tbody></table></div></div></section>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
