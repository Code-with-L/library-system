<?php
/**
 * Administrator activity log viewer.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_role($pdo, 'admin');

$page = max(1, (int) ($_GET['page'] ?? 1));
$actions = $pdo->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll();
$actionOptions = array_map(fn($r) => (string) $r['action'], $actions);

// Quick date presets keep long histories manageable. Manual From/To still work.
$range = (string) ($_GET['range'] ?? 'all');
if (!in_array($range, ['all', 'today', '7d', '30d', 'custom'], true)) {
    $range = 'all';
}
$today = date('Y-m-d');
$rangeFrom = '';
$rangeTo = '';
if ($range === 'today') {
    $rangeFrom = $today;
    $rangeTo = $today;
} elseif ($range === '7d') {
    $rangeFrom = date('Y-m-d', strtotime('-6 days'));
    $rangeTo = $today;
} elseif ($range === '30d') {
    $rangeFrom = date('Y-m-d', strtotime('-29 days'));
    $rangeTo = $today;
}

$filters = [
    'action'    => (string) ($_GET['action'] ?? ''),
    'user_id'   => (string) ($_GET['user_id'] ?? ''),
    'entity_id' => (string) ($_GET['entity_id'] ?? ''),
    'from'      => $rangeFrom !== '' ? $rangeFrom : (string) ($_GET['from'] ?? ''),
    'to'        => $rangeTo !== '' ? $rangeTo : (string) ($_GET['to'] ?? ''),
];
$perPage = 50;
$report = reporting_activity($pdo, $filters, $page, $perPage);
$rows = $report['rows'];
$pages = max(1, (int) ceil($report['total'] / $perPage));

$pageTitle = 'Activity log';
$activeNav = 'activity';
require __DIR__ . '/../../includes/header.php';

$pageLink = function (int $p) use ($filters, $range): string {
    return 'admin/activity-log.php?range=' . urlencode($range)
        . '&action=' . urlencode((string) $filters['action'])
        . '&user_id=' . urlencode((string) $filters['user_id'])
        . '&entity_id=' . urlencode((string) $filters['entity_id'])
        . '&from=' . urlencode((string) $filters['from'])
        . '&to=' . urlencode((string) $filters['to'])
        . '&page=' . $p;
};
?>

<div class="page-intro"><div><h2>Activity log</h2><p>Authorized chronological audit view. Append-only; no editing here.</p></div><a class="btn btn--ghost btn--sm" href="<?= e(url('admin/reports.php')) ?>">Back to reports</a></div>

<form method="get" action="<?= e(url('admin/activity-log.php')) ?>" class="filter-bar" aria-label="Activity filters">
    <div class="filter-group">
        <label for="range">Period</label>
        <select id="range" name="range" data-auto-submit>
            <option value="all"<?= $range === 'all' ? ' selected' : '' ?>>All time</option>
            <option value="today"<?= $range === 'today' ? ' selected' : '' ?>>Today</option>
            <option value="7d"<?= $range === '7d' ? ' selected' : '' ?>>Last 7 days</option>
            <option value="30d"<?= $range === '30d' ? ' selected' : '' ?>>Last 30 days</option>
            <option value="custom"<?= $range === 'custom' ? ' selected' : '' ?>>Custom dates</option>
        </select>
    </div>
    <div class="filter-group"><label for="action">Action</label><select id="action" name="action"><option value="">All actions</option><?php foreach ($actionOptions as $action): ?><option value="<?= e($action) ?>"<?= $filters['action'] === $action ? ' selected' : '' ?>><?= e($action) ?></option><?php endforeach; ?></select></div>
    <div class="filter-group"><label for="user_id">User ID</label><input id="user_id" name="user_id" type="number" min="1" value="<?= e((string) $filters['user_id']) ?>"></div>
    <div class="filter-group"><label for="entity_id">Entity ID</label><input id="entity_id" name="entity_id" type="number" min="1" value="<?= e((string) $filters['entity_id']) ?>"></div>
    <div class="filter-group"><label for="from">From</label><input id="from" name="from" type="date" value="<?= e((string) $filters['from']) ?>"></div>
    <div class="filter-group"><label for="to">To</label><input id="to" name="to" type="date" value="<?= e((string) $filters['to']) ?>"></div>
    <button type="submit" class="btn btn--primary btn--sm">Filter</button>
</form>

<?php if ($rows === []): ?>
    <div class="empty-state"><h3>No activity records</h3><p>The selected period and filters returned no activity records.</p></div>
<?php else: ?>
    <p class="muted small">Showing <?= e((string) count($rows)) ?> of <?= e((string) $report['total']) ?> entries &middot; newest first.</p>
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th scope="col">When</th>
                <th scope="col">User</th>
                <th scope="col">Action</th>
                <th scope="col">Record</th>
                <th scope="col">Details</th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="nowrap">
                            <span class="table__primary"><?= e(format_date((string) $row['created_at'])) ?></span>
                            <span class="table__secondary"><?= e(date('H:i', strtotime((string) $row['created_at']))) ?></span>
                        </td>
                        <td>
                            <span class="table__primary"><?= e((string) ($row['actor_name'] ?? 'Anonymous')) ?></span>
                            <span class="table__secondary"><?= e(role_label($row['actor_role'] ?? null)) ?></span>
                        </td>
                        <td><span class="badge badge--info"><?= e((string) $row['action']) ?></span></td>
                        <td class="nowrap"><?= e((string) ($row['entity_type'] ?? '—')) ?> #<?= e((string) ($row['entity_id'] ?? '—')) ?></td>
                        <td><?= e((string) $row['description']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
        <nav aria-label="Activity pagination">
            <ul class="pagination-simple">
                <?php $start = max(1, $page - 2); $end = min($pages, $page + 2); ?>
                <?php if ($start > 1): ?><li><a class="pagination-simple__link" href="<?= e(url($pageLink(1))) ?>">First</a></li><?php endif; ?>
                <?php for ($p = $start; $p <= $end; $p++): ?>
                    <li><a class="pagination-simple__link<?= $p === $page ? ' is-current' : '' ?>" href="<?= e(url($pageLink($p))) ?>"><?= e((string) $p) ?></a></li>
                <?php endfor; ?>
                <?php if ($end < $pages): ?><li><a class="pagination-simple__link" href="<?= e(url($pageLink($pages))) ?>">Last</a></li><?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
