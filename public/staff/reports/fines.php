<?php
/**
 * Librarian fines report with authoritative totals.
 */
require_once __DIR__ . '/../../../includes/init.php';

$user = require_staff($pdo);

$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = reporting_resolve_preset([
    'preset' => (string) ($_GET['preset'] ?? ''),
    'status' => (string) ($_GET['status'] ?? 'all'),
    'from'   => (string) ($_GET['from'] ?? ''),
    'to'     => (string) ($_GET['to'] ?? ''),
]);
$report = reporting_fines($pdo, $filters, $page, 50);
$rows = $report['rows'];

$pageTitle = 'Fines report';
$activeNav = 'reports';
require __DIR__ . '/../../../includes/header.php';
?>

<div class="page-intro"><div><h2>Fines report</h2><p>Outstanding and settled fines with authoritative totals.</p></div><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/reports/index.php')) ?>">Back to reports</a></div>

<form method="get" action="<?= e(url('staff/reports/fines.php')) ?>" class="filter-bar">
    <div class="filter-group"><label for="status">Status</label><select id="status" name="status">
        <option value="all">All</option>
        <option value="outstanding">Outstanding</option>
        <option value="settled">Settled</option>
    </select></div>
    <div class="filter-group filter-group--narrow"><label for="preset">Preset</label><select id="preset" name="preset"><option value="">No preset</option><option value="today"<?= ($_GET['preset'] ?? '') === 'today' ? ' selected' : '' ?>>Today</option><option value="last7"<?= ($_GET['preset'] ?? '') === 'last7' ? ' selected' : '' ?>>Last 7 days</option><option value="last30"<?= ($_GET['preset'] ?? '') === 'last30' ? ' selected' : '' ?>>Last 30 days</option><option value="current_month"<?= ($_GET['preset'] ?? '') === 'current_month' ? ' selected' : '' ?>>Current month</option></select></div>
    <div class="filter-group"><label for="from">From</label><input id="from" name="from" type="date" value="<?= e((string) ($_GET['from'] ?? '')) ?>"></div>
    <div class="filter-group"><label for="to">To</label><input id="to" name="to" type="date" value="<?= e((string) ($_GET['to'] ?? '')) ?>"></div>
    <button type="submit" class="btn btn--primary btn--sm">Filter</button>
    <a class="btn btn--ghost btn--sm" href="<?= e(url('staff/reports/export.php?report=fines')) ?>">CSV export</a>
</form>

<div class="stat-grid">
    <article class="stat stat--danger"><p class="stat__label">Outstanding count</p><p class="stat__value"><?= e((string) $report['outstanding_count']) ?></p><p class="stat__hint">outstanding</p></article>
    <article class="stat"><p class="stat__label">Outstanding total</p><p class="stat__value"><?= e(fines_format($report['outstanding_total'], 'KES')) ?></p><p class="stat__hint">authoritative aggregate</p></article>
    <article class="stat"><p class="stat__label">Settled count</p><p class="stat__value"><?= e((string) $report['settled_count']) ?></p><p class="stat__hint">historical records</p></article>
    <article class="stat"><p class="stat__label">Settled total</p><p class="stat__value"><?= e(fines_format($report['settled_total'], 'KES')) ?></p><p class="stat__hint">historical records</p></article>
</div>

<?php if ($rows === []): ?>
    <div class="empty-state"><h3>No fines match the filters</h3><p>Try all statuses or clear the date range.</p></div>
<?php else: ?>
    <p class="muted small">Total rows: <?= e((string) $report['total_rows']) ?>. Showing page <?= e((string) $page) ?>.</p>
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th scope="col">Fine</th><th scope="col">Student</th><th scope="col">Book</th><th scope="col">Copy</th>
                <th scope="col">Due</th><th scope="col">Status</th><th scope="col">Amount</th><th scope="col">Settled by</th><th scope="col">Settled at</th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="mono"><?= e((string) $row['fine_id']) ?></td>
                        <td><?= e((string) $row['student_name']) ?></td>
                        <td><?= e((string) $row['book_title']) ?></td>
                        <td><code><?= e((string) $row['copy_code']) ?></code></td>
                        <td class="nowrap"><?= e(format_date((string) $row['due_date'])) ?></td>
                        <td><span class="badge <?= e(badge_class((string) $row['status'])) ?>"><?= e(status_label((string) $row['status'])) ?></span></td>
                        <td class="nowrap"><?= e(fines_format((float) $row['amount'], 'KES')) ?></td>
                        <td class="nowrap"><?= e((string) ($row['settled_by_id'] ?? '')) ?></td>
                        <td class="nowrap"><?= e($row['settled_at'] !== null ? format_datetime((string) $row['settled_at']) : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <nav aria-label="Fines report pagination">
        <ul class="pagination-simple">
            <?php $pages = max(1, (int) ceil($report['total_rows'] / 50)); for ($p = 1; $p <= $pages; $p++): ?>
                <li><a class="pagination-simple__link<?= $p === $page ? ' is-current' : '' ?>" href="<?= e(url('staff/reports/fines.php?status=' . urlencode((string) $filters['status']) . '&from=' . urlencode((string) ($_GET['from'] ?? '')) . '&to=' . urlencode((string) ($_GET['to'] ?? '')) . '&page=' . $p)) ?>"><?= e((string) $p) ?></a></li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
