<?php
/**
 * Librarian fine management: list, search and settlement.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

$status = trim((string) ($_GET['status'] ?? ''));
if (!in_array($status, ['outstanding', 'settled'], true)) {
    $status = '';
}
$search = trim((string) ($_GET['q'] ?? ''));

$filters = ['status' => $status, 'search' => $search];
$fines = fines_fetch($pdo, $filters, 100);

function phase4_fine_days(PDO $pdo, array $fine): int
{
    if ((int) ($fine['borrowing_status'] ?? '') === 0) {
        return 0;
    }
    if ((string) ($fine['borrowing_status'] ?? '') === 'active' && $fine['due_date'] !== null) {
        return fines_active_overdue_days($pdo, (int) $fine['borrowing_id']);
    }
    if ((string) ($fine['borrowing_status'] ?? '') === 'returned' && $fine['return_date'] !== null) {
        return fines_returned_days((string) $fine['return_date'], (string) $fine['due_date']);
    }
    return 0;
}

$pageTitle = 'Fines';
$activeNav = 'fines';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro"><div><h2>Library fines</h2><p>Outstanding and settled fines, with settlement by library staff.</p></div></div>

<form method="get" action="<?= e(url('staff/fines.php')) ?>" class="filter-bar" aria-label="Fine filters">
    <div class="filter-group"><label for="q">Search student, email or title</label><input id="q" name="q" type="search" value="<?= e($search) ?>" placeholder="Search fines"></div>
    <div class="filter-group filter-group--narrow"><label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All</option>
            <option value="outstanding"<?= $status === 'outstanding' ? ' selected' : '' ?>>Outstanding</option>
            <option value="settled"<?= $status === 'settled' ? ' selected' : '' ?>>Settled</option>
        </select>
    </div>
    <button type="submit" class="btn btn--primary btn--sm">Filter</button>
</form>

<?php if ($fines === []): ?>
    <div class="empty-state"><h3>No fines</h3><p>No fines match the selected status and search criteria.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th scope="col">Fine</th>
                <th scope="col">Student</th>
                <th scope="col">Title</th>
                <th scope="col">Copy</th>
                <th scope="col">Due date</th>
                <th scope="col">Days overdue</th>
                <th scope="col">Amount</th>
                <th scope="col">Status</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr></thead>
            <tbody>
                <?php foreach ($fines as $fine): ?>
                    <tr>
                        <td class="mono"><?= e((string) $fine['id']) ?></td>
                        <td><a href="<?= e(url('staff/fine-details.php?id=' . (int) $fine['id'])) ?>"><?= e((string) $fine['student_name']) ?></a></td>
                        <td><?= e((string) $fine['book_title'] ?? '—') ?></td>
                        <td><code><?= e((string) $fine['copy_code'] ?? '—') ?></code></td>
                        <td class="nowrap"><?= e(format_date((string) $fine['due_date'] ?? '')) ?></td>
                        <td class="nowrap"><?= e((string) phase4_fine_days($pdo, $fine)) ?></td>
                        <td class="nowrap"><?= e(fines_format((float) $fine['amount'], 'KES')) ?></td>
                        <td><span class="badge <?= e(badge_class((string) $fine['status'])) ?>"><?= e(status_label((string) $fine['status'])) ?></span></td>
                        <td class="nowrap">
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('staff/fine-details.php?id=' . (int) $fine['id'])) ?>">Details</a>
                            <?php if ($fine['status'] === 'outstanding'): ?>
                                <form method="post" action="<?= e(url('staff/fine-action.php')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="fine_id" value="<?= e((int) $fine['id']) ?>">
                                    <input type="hidden" name="action" value="settle">
                                    <button class="btn btn--primary btn--sm" type="submit">Settle</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted small">Amounts are shown in KES by default; use library settings for other approved currencies.</p>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
