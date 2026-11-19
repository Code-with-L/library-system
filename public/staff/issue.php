<?php
/**
 * Librarian issuance queue: approved requests awaiting physical issuance.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);
$settings = get_settings($pdo);

$statement = $pdo->prepare(
    "SELECT br.*, b.title AS book_title, u.full_name AS student_name
       FROM borrow_requests br
       JOIN books b ON b.id = br.book_id
       JOIN users u ON u.id = br.user_id
      WHERE br.status = 'approved'
        AND NOT EXISTS (SELECT 1 FROM borrowings bb WHERE bb.request_id = br.id AND bb.status = 'active')
      ORDER BY br.id ASC"
);
$statement->execute();
$awaitingIssuance = (array) $statement->fetchAll();

$pageTitle = 'Await issuance';
$activeNav = 'issue';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro"><div><h2>Approved requests awaiting issuance</h2><p>Select a copy and record the borrowing. Approval and issuance are separate steps.</p></div></div>

<?php if ($awaitingIssuance === []): ?>
    <div class="empty-state"><h3>Nothing to issue</h3><p>No approved requests are awaiting collection.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Student</th><th>Title</th><th>Reserved copy</th><th>Created</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
                <?php foreach ($awaitingIssuance as $request): ?>
                    <tr>
                        <td><?= e($request['student_name'] ?? '—') ?></td>
                        <td><?= e($request['book_title']) ?></td>
                        <td><?= e((string) $request['reserved_copy_id'] ?? '—') ?></td>
                        <td class="nowrap"><?= e(format_datetime((string) $request['created_at'])) ?></td>
                        <td class="nowrap"><form method="post" action="<?= e(url('staff/issue-action.php')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="request_id" value="<?= e((int) $request['id']) ?>">
                            <button class="btn btn--primary btn--sm" type="submit">Issue</button>
                        </form></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
