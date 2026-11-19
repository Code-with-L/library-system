<?php
/**
 * Librarian active-loan and overdue list.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

$search = trim((string) ($_GET['q'] ?? ''));
$where = ['br.status = \'active\''];
$params = [];
if ($search !== '') {
    $where[] = '(b.title LIKE :q OR bc.copy_code LIKE :q OR u.full_name LIKE :q OR u.email LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

$loans = $pdo->prepare(
    'SELECT br.id, br.user_id, br.book_copy_id, br.issue_date, br.due_date, br.status,
            b.title AS book_title, bc.copy_code, u.full_name AS student_name, u.email AS student_email
       FROM borrowings br
       JOIN book_copies bc ON bc.id = br.book_copy_id
       JOIN books b ON b.id = bc.book_id
       JOIN users u ON u.id = br.user_id
      WHERE ' . $whereSql . '
      ORDER BY br.id DESC'
);
$loans->execute($params);
$loans = (array) $loans->fetchAll();

$pageTitle = 'Active loans';
$activeNav = 'returns';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro"><div><h2>Active loans</h2><p>Record returns and find overdue items.</p></div></div>

<form method="get" action="<?= e(url('staff/returns.php')) ?>" class="filter-bar">
    <div class="filter-group field--full"><label for="q">Search student, copy or title</label><input id="q" name="q" type="search" value="<?= e($search) ?>" placeholder="Search active loans"></div>
    <button type="submit" class="btn btn--primary btn--sm">Filter</button>
</form>

<?php if ($loans === []): ?>
    <div class="empty-state"><h3>No active loans</h3><p>There are no active loans matching the search.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Student</th><th>Title</th><th>Copy</th><th>Issue date</th><th>Due date</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
                <?php foreach ($loans as $loan): ?>
                    <tr>
                        <td><?= e((string) $loan['student_name']) ?></td>
                        <td><?= e((string) $loan['book_title']) ?></td>
                        <td><code><?= e((string) $loan['copy_code']) ?></code></td>
                        <td class="nowrap"><?= e(format_date((string) $loan['issue_date'])) ?></td>
                        <td class="nowrap<?= ((string) $loan['due_date'] < date('Y-m-d')) ? ' overdue-cell' : '' ?>"><?= e(format_date((string) $loan['due_date'])) ?></td>
                        <td><span class="badge badge--info"><?= e(status_label((string) $loan['status'])) ?></span> <?= ((string) $loan['due_date'] < date('Y-m-d')) ? '<span class="badge badge--danger">Overdue</span>' : '' ?></td>
                        <td class="nowrap"><form method="post" action="<?= e(url('staff/return-action.php')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="borrowing_id" value="<?= e((int) $loan['id']) ?>">
                            <button class="btn btn--ghost btn--sm" type="submit">Record return</button>
                        </form></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted small">Overdue = due date earlier than today.</p>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
