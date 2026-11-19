<?php
/**
 * Librarian overdue borrowing report.
 */
require_once __DIR__ . '/../../../includes/init.php';

$user = require_staff($pdo);

$page = max(1, (int) ($_GET['page'] ?? 1));
$filters = reporting_resolve_preset([
    'preset' => (string) ($_GET['preset'] ?? ''),
    'from' => (string) ($_GET['from'] ?? ''),
    'to' => (string) ($_GET['to'] ?? ''),
]);
$parsed = reporting_parse_range($filters);
foreach ($parsed['errors'] as $error) { flash('error', $error); }
$report = reporting_overdue($pdo, $filters, 200);
$rows = $report['rows'];
$overdueRows = array_slice($rows, ($page - 1) * 50, 50);

$pageTitle = 'Overdue report';
$activeNav = 'reports';
require __DIR__ . '/../../../includes/header.php';
?>

<div class="page-intro"><div><h2>Overdue report</h2><p>Overdue active borrowings with days overdue and outstanding fines.</p></div><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/reports/index.php')) ?>">Back to reports</a></div>

<form method="get" action="<?= e(url('staff/reports/overdue.php')) ?>" class="filter-bar" aria-label="Overdue report filters">
    <div class="filter-group"><label for="preset">Preset</label><select id="preset" name="preset"><option value="">No preset</option><option value="today"<?= ($_GET['preset'] ?? '') === 'today' ? ' selected' : '' ?>>Today</option><option value="last7"<?= ($_GET['preset'] ?? '') === 'last7' ? ' selected' : '' ?>>Last 7 days</option><option value="last30"<?= ($_GET['preset'] ?? '') === 'last30' ? ' selected' : '' ?>>Last 30 days</option><option value="current_month"<?= ($_GET['preset'] ?? '') === 'current_month' ? ' selected' : '' ?>>Current month</option></select></div>
    <div class="filter-group"><label for="from">From</label><input id="from" name="from" type="date" value="<?= e((string) ($_GET['from'] ?? '')) ?>"></div>
    <div class="filter-group"><label for="to">To</label><input id="to" name="to" type="date" value="<?= e((string) ($_GET['to'] ?? '')) ?>"></div>
    <button type="submit" class="btn btn--primary btn--sm">Filter</button>
    <a class="btn btn--ghost btn--sm" href="<?= e(url('staff/reports/export.php?report=overdue')) ?>">CSV export</a>
</form>

<?php if ($rows === []): ?>
    <div class="empty-state"><h3>No overdue borrowings</h3><p>There are no active overdue borrowings.</p></div>
<?php else: ?>
    <p class="muted small">Total overdue: <?= e((string) $report['total']) ?>. Showing page <?= e((string) $page) ?>.</p>
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th scope="col">Borrowing</th><th scope="col">Student</th><th scope="col">Book</th><th scope="col">Copy</th>
                <th scope="col">Due date</th><th scope="col">Days overdue</th><th scope="col">Fine</th>
            </tr></thead>
            <tbody>
                <?php foreach ($overdueRows as $row): ?>
                    <tr>
                        <td class="mono"><?= e((string) $row['borrowing_id']) ?></td>
                        <td><?= e((string) $row['student_name']) ?></td>
                        <td><?= e((string) $row['book_title']) ?></td>
                        <td><code><?= e((string) $row['copy_code']) ?></code></td>
                        <td class="nowrap"><?= e(format_date((string) $row['due_date'])) ?></td>
                        <td class="nowrap overdue-cell"><?= e((string) $row['days_overdue']) ?></td>
                        <td class="nowrap"><?= $row['fine_id'] ? e(fines_format((float) $row['fine_amount'], 'KES') . ($row['fine_status'] === 'settled' ? ' (settled)' : ' (outstanding)')) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
