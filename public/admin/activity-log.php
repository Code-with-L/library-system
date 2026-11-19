<?php
/**
 * Administrator activity log viewer.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_role($pdo, 'admin');

$page = max(1, (int) ($_GET['page'] ?? 1));
$actions = $pdo->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll();
$actionOptions = array_map(fn($r) => (string) $r['action'], $actions);

$filters = [
    'action' => (string) ($_GET['action'] ?? ''),
    'user_id' => (string) ($_GET['user_id'] ?? ''),
    'entity_id' => (string) ($_GET['entity_id'] ?? ''),
    'from' => (string) ($_GET['from'] ?? ''),
    'to' => (string) ($_GET['to'] ?? ''),
];
$report = reporting_activity($pdo, $filters, $page, 100);
$rows = $report['rows'];

$pageTitle = 'Activity log';
$activeNav = 'activity';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro"><div><h2>Activity log</h2><p>Authorized chronological audit view. Append-only; no editing here.</p></div><a class="btn btn--ghost btn--sm" href="<?= e(url('admin/reports.php')) ?>">Back to reports</a></div>

<form method="get" action="<?= e(url('admin/activity-log.php')) ?>" class="filter-bar" aria-label="Activity filters">
    <div class="filter-group"><label for="action">Action</label><select id="action" name="action"><option value="">All actions</option><?php foreach ($actionOptions as $action): ?><option value="<?= e($action) ?>"<?= $filters['action'] === $action ? ' selected' : '' ?>><?= e($action) ?></option><?php endforeach; ?></select></div>
    <div class="filter-group"><label for="user_id">User ID</label><input id="user_id" name="user_id" type="number" min="1" value="<?= e((string) ($_GET['user_id'] ?? '')) ?>"></div>
    <div class="filter-group"><label for="entity_id">Entity ID</label><input id="entity_id" name="entity_id" type="number" min="1" value="<?= e((string) ($_GET['entity_id'] ?? '')) ?>"></div>
    <div class="filter-group"><label for="from">From</label><input id="from" name="from" type="date" value="<?= e((string) ($_GET['from'] ?? '')) ?>"></div>
    <div class="filter-group"><label for="to">To</label><input id="to" name="to" type="date" value="<?= e((string) ($_GET['to'] ?? '')) ?>"></div>
    <button type="submit" class="btn btn--primary btn--sm">Filter</button>
</form>

<?php if ($rows === []): ?>
    <div class="empty-state"><h3>No activity records</h3><p>The filters returned no activity records.</p></div>
<?php else: ?>
    <p class="muted small">Total: <?= e((string) $report['total']) ?>.</p>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>Description</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="nowrap"><?= e(format_datetime((string) $row['created_at'])) ?></td>
                        <td><?= e((string) ($row['actor_name'] ?? 'Anonymous')) ?></td>
                        <td><span class="badge badge--info"><?= e((string) $row['action']) ?></span></td>
                        <td><?= e((string) $row['entity_type'] ?? '') ?> #<?= e((string) $row['entity_id'] ?? '') ?></td>
                        <td><?= e((string) $row['description']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <nav aria-label="Activity pagination">
        <ul class="pagination-simple">
            <?php $pages = max(1, (int) ceil($report['total'] / 100)); for ($p = 1; $p <= $pages; $p++): ?>
                <li><a class="pagination-simple__link<?= $p === $page ? ' is-current' : '' ?>" href="<?= e(url('admin/activity-log.php?action=' . urlencode((string) $filters['action']) . '&user_id=' . urlencode((string) $filters['user_id']) . '&entity_id=' . urlencode((string) $filters['entity_id']) . '&from=' . urlencode((string) ($_GET['from'] ?? '')) . '&to=' . urlencode((string) ($_GET['to'] ?? '')) . '&page=' . $p)) ?>"><?= e((string) $p) ?></a></li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
