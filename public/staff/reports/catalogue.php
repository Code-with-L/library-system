<?php
/**
 * Librarian catalogue report.
 */
require_once __DIR__ . '/../../../includes/init.php';

$user = require_staff($pdo);
$report = reporting_catalogue($pdo);

$pageTitle = 'Catalogue report';
$activeNav = 'reports';
require __DIR__ . '/../../../includes/header.php';
?>

<div class="page-intro"><div><h2>Catalogue report</h2><p>Real authoritative copy allocation totals.</p></div><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/reports/index.php')) ?>">Back to reports</a></div>

<div class="stat-grid">
    <article class="stat"><p class="stat__label">Active titles</p><p class="stat__value"><?= e((string) $report['titles']) ?></p><p class="stat__hint">books.status = active</p></article>
    <article class="stat"><p class="stat__label">Physical copies</p><p class="stat__value"><?= e((string) $report['copies']) ?></p><p class="stat__hint">all copies</p></article>
    <article class="stat stat--gold"><p class="stat__label">Available</p><p class="stat__value"><?= e((string) $report['statuses']['available']) ?></p></article>
    <article class="stat"><p class="stat__label">Reserved</p><p class="stat__value"><?= e((string) $report['statuses']['reserved']) ?></p></article>
    <article class="stat stat--info"><p class="stat__label">Issued</p><p class="stat__value"><?= e((string) $report['statuses']['issued']) ?></p></article>
    <article class="stat"><p class="stat__label">Maintenance</p><p class="stat__value"><?= e((string) $report['statuses']['maintenance']) ?></p></article>
    <article class="stat"><p class="stat__label">Archived</p><p class="stat__value"><?= e((string) $report['statuses']['archived']) ?></p></article>
    <article class="stat"><p class="stat__label">CSV</p><p class="stat__value"><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/reports/export.php?report=catalogue')) ?>">Export</a></p></article>
</div>

<div class="table-wrap"><table class="table">
    <thead><tr><th>Category</th><th>Active books</th></tr></thead>
    <tbody><?php foreach ($report['books_by_category'] as $row): ?><tr><td><?= e((string) $row['category_name']) ?></td><td><?= e((string) $row['books']) ?></td></tr><?php endforeach; ?></tbody>
</table></div>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
