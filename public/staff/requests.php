<?php
/**
 * Librarian request queue: list and search borrow requests.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

$status = trim((string) ($_GET['status'] ?? ''));
if (!in_array($status, ['pending', 'approved', 'rejected', 'cancelled', 'fulfilled'], true)) {
    $status = '';
}
$search = trim((string) ($_GET['q'] ?? ''));
$overdueOnly = filter_var($_GET['overdue_only'] ?? '', FILTER_VALIDATE_BOOLEAN);

$filters = ['status' => $status, 'search' => $search, 'overdue_only' => $overdueOnly];
$requests = catalogue_requests_fetch($pdo, $filters, 100);

$pageTitle = 'Borrow requests';
$activeNav = 'requests';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro"><div><h2>Borrow requests</h2><p>Approve or reject student requests. Approval assigns a physical copy; issuance happens separately.</p></div></div>

<form method="get" action="<?= e(url('staff/requests.php')) ?>" class="filter-bar">
    <div class="filter-group"><label for="q">Search</label><input id="q" name="q" type="search" value="<?= e($search) ?>" placeholder="Student, book or request"></div>
    <div class="filter-group"><label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All</option>
            <?php foreach (['pending', 'approved', 'rejected', 'cancelled', 'fulfilled'] as $statusOption): ?>
                <option value="<?= e($statusOption) ?>"<?= $status === $statusOption ? ' selected' : '' ?>><?= e(status_label($statusOption)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group filter-group--narrow"><label class="checkbox"><input type="checkbox" name="overdue_only" value="1"<?= $overdueOnly ? ' checked' : '' ?>> Overdue</label></div>
    <button type="submit" class="btn btn--primary btn--sm">Filter</button>
</form>

<?php if ($requests === []): ?>
    <div class="empty-state"><h3>No requests</h3><p>There are no borrow requests in the selected status.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Student</th><th>Book</th><th>Status</th><th>Reserved copy</th><th>Created</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td><a href="<?= e(url('staff/request-details.php?id=' . (int) $request['id'])) ?>"><?= e($request['student_name']) ?></a></td>
                        <td><a href="<?= e(url('staff/request-details.php?id=' . (int) $request['id'])) ?>"><?= e($request['book_title']) ?></a></td>
                        <td><span class="badge <?= e(badge_class((string) $request['status'])) ?>"><?= e(status_label((string) $request['status'])) ?></span></td>
                        <td class="nowrap"><?= $request['reserved_copy_id'] !== null ? e((string) $request['reserved_copy_id']) : '—' ?></td>
                        <td class="nowrap"><?= e(format_datetime((string) $request['created_at'])) ?></td>
                        <td class="nowrap"><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/request-details.php?id=' . (int) $request['id'])) ?>">Details</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted small">Showing <?= count($requests) ?> request(s).</p>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
