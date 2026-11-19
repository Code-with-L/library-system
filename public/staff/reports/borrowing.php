<?php
/**
 * Librarian borrowing report: paginated, filterable, date-range aware.
 */
require_once __DIR__ . '/../../../includes/init.php';

$user = require_staff($pdo);

$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = reporting_resolve_preset([
    'preset' => (string) ($_GET['preset'] ?? ''),
    'status' => (string) ($_GET['status'] ?? 'all'),
    'overdue' => (string) ($_GET['overdue'] ?? '') === '1',
    'student_id' => (string) ($_GET['student_id'] ?? ''),
    'from' => (string) ($_GET['from'] ?? ''),
    'to' => (string) ($_GET['to'] ?? ''),
]);
$validated = reporting_borrowing_filters($filters);
$parsed = reporting_parse_range($validated);
if ($validated['errors'] !== []) {
    foreach ($validated['errors'] as $error) { flash('error', $error); }
    $page = 1;
}
$validated = ['status' => $validated['status'], 'overdue' => $validated['overdue'], 'studentId' => $validated['studentId'], 'from' => $parsed['from'], 'to' => $parsed['to'], 'errors' => $validated['errors']];

$report = reporting_borrowings($pdo, $validated, $page, 50);
$rows = $report['rows'];

function p5_days_overdue(array $row): int { return (int) ($row['days_overdue'] ?? 0); }
function p5_overdue_cell(array $row): string { return (string) ($row['days_overdue'] ?? 0) . ' d'; }
function p5_fine_cell(array $row): string { return $row['fine_id'] ? fines_format((float) $row['fine_amount'], 'KES') . ($row['fine_status'] === 'settled' ? ' (settled)' : ' (outstanding)') : '—'; }

$pageTitle = 'Borrowing report';
$activeNav = 'reports';
require __DIR__ . '/../../../includes/header.php';
?>

<div class="page-intro"><div><h2>Borrowing report</h2><p>Current and completed borrowings with authoritative copy allocation data.</p></div><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/reports/index.php')) ?>">Back to reports</a></div>

<form method="get" action="<?= e(url('staff/reports/borrowing.php')) ?>" class="filter-bar" aria-label="Borrowing report filters">
    <div class="filter-group"><label for="q">Student or email search</label><input id="q" name="q" type="search" value="<?= e((string) ($_GET['q'] ?? '')) ?>"></div>
    <div class="filter-group filter-group--narrow"><label for="status">Status</label><select id="status" name="status"><option value="all">All</option><option value="active">Active</option><option value="returned">Returned</option></select></div>
    <div class="filter-group filter-group--narrow"><label for="overdue">Overdue</label><select id="overdue" name="overdue"><option value="0">All due dates</option><option value="1">Overdue only</option></select></div>
    <div class="filter-group filter-group--narrow"><label for="preset">Preset</label><select id="preset" name="preset"><option value="">No preset</option><option value="today"<?= ($_GET['preset'] ?? '') === 'today' ? ' selected' : '' ?>>Today</option><option value="last7"<?= ($_GET['preset'] ?? '') === 'last7' ? ' selected' : '' ?>>Last 7 days</option><option value="last30"<?= ($_GET['preset'] ?? '') === 'last30' ? ' selected' : '' ?>>Last 30 days</option><option value="current_month"<?= ($_GET['preset'] ?? '') === 'current_month' ? ' selected' : '' ?>>Current month</option></select></div>
    <div class="filter-group"><label for="from">From</label><input id="from" name="from" type="date" value="<?= e((string) ($_GET['from'] ?? '')) ?>"></div>
    <div class="filter-group"><label for="to">To</label><input id="to" name="to" type="date" value="<?= e((string) ($_GET['to'] ?? '')) ?>"></div>
    <button type="submit" class="btn btn--primary btn--sm">Filter</button>
    <a class="btn btn--ghost btn--sm" href="<?= e(url('staff/reports/export.php?report=borrowing')) ?>">CSV export</a>
</form>

<?php if ($report['rows'] === []): ?>
    <div class="empty-state"><h3>No borrowing records</h3><p>The filters returned no results.</p></div>
<?php else: ?>
    <p class="muted small">Total: <?= e((string) $report['total']) ?> borrowing(s); showing page <?= e((string) $page) ?>.</p>
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th scope="col">Borrowing</th><th scope="col">Student</th><th scope="col">Title</th><th scope="col">Copy</th>
                <th scope="col">Issue</th><th scope="col">Due</th><th scope="col">Return</th><th scope="col">Status</th>
                <th scope="col">Overdue</th><th scope="col">Fine</th>
            </tr></thead>
            <tbody>
                <?php foreach ($report['rows'] as $row): ?>
                    <tr>
                        <td class="mono"><?= e((string) $row['borrowing_id']) ?></td>
                        <td><?= e((string) $row['student_name']) ?></td>
                        <td><?= e((string) $row['book_title']) ?></td>
                        <td><code><?= e((string) $row['copy_code']) ?></code></td>
                        <td class="nowrap"><?= e(format_date((string) $row['issue_date'])) ?></td>
                        <td class="nowrap<?= ((string) $row['due_date'] < date('Y-m-d') && (string) $row['status'] === 'active') ? ' overdue-cell' : '' ?>"><?= e(format_date((string) $row['due_date'])) ?></td>
                        <td class="nowrap"><?= e($row['return_date'] !== null ? format_date((string) $row['return_date']) : '—') ?></td>
                        <td><span class="badge <?= e(badge_class((string) $row['status'])) ?>"><?= e(status_label((string) $row['status'])) ?></span></td>
                        <td class="nowrap"><?= e(p5_days_overdue($row) . ' d') ?></td>
                        <td class="nowrap"><?= e(p5_fine_cell($row)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <nav aria-label="Borrowing report pagination">
        <ul class="pagination-simple">
            <?php for ($p = 1; $p <= $report['pages']; $p++): ?>
                <li><a class="pagination-simple__link<?= $p === $page ? ' is-current' : '' ?>" href="<?= e(url('staff/reports/borrowing.php?status=' . urlencode((string) $validated['status']) . '&overdue=' . ($validated['overdue'] ? '1' : '0') . '&student_id=' . urlencode((string) $validated['studentId'] ?? '') . '&from=' . urlencode((string) $validated['from']) . '&to=' . urlencode((string) $validated['to']) . '&page=' . $p)) ?>"><?= e((string) $p) ?></a></li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
