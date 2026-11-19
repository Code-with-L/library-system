<?php
/**
 * Librarian request details: approve, reject or cancel.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    abort_404('The requested borrow request does not exist.');
}
$id = (int) $id;

$stmt = $pdo->prepare('SELECT * FROM borrow_requests WHERE id = :id');
$stmt->execute(['id' => $id]);
$request = (array) $stmt->fetch();
if ($request === false || $request === []) {
    abort_404('The requested borrow request does not exist.');
}

$book = catalogue_book_get($pdo, (int) $request['book_id']);
$availableCopies = catalogue_available_copies($pdo, (int) $request['book_id']);
$studentLoans = $pdo->prepare(
    'SELECT br.*, bc.copy_code, bc.status AS copy_status, b.title AS book_title
       FROM borrowings br
       JOIN book_copies bc ON bc.id = br.book_copy_id
       JOIN books b ON b.id = bc.book_id
      WHERE br.user_id = :uid AND br.status = \'active\'
      ORDER BY br.id DESC'
);
$studentLoans->execute(['uid' => (int) $request['user_id']]);
$studentLoans = (array) $studentLoans->fetchAll();

$pageTitle = 'Request details';
$activeNav = 'requests';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro"><div><h2>Borrow request #<?= e((string) $request['id']) ?></h2><p>Student, title and decision.</p></div></div>

<div class="panel-grid">
    <section class="card span-6" aria-labelledby="request-info-title">
        <div class="card__header"><h2 id="request-info-title">Request</h2></div>
        <div class="card__body">
            <div class="stack">
                <p><strong>Student:</strong> <?= e((string) $request['student_name']) ?></p>
                <p><strong>Title:</strong> <?= e((string) $book['title'] ?? '—') ?></p>
                <p><strong>Status:</strong> <span class="badge <?= e(badge_class((string) $request['status'])) ?>"><?= e(status_label((string) $request['status'])) ?></span></p>
                <p><strong>Reserved copy:</strong> <?= $request['reserved_copy_id'] !== null ? e((string) $request['reserved_copy_id']) : '—' ?></p>
                <p><strong>Requested at:</strong> <?= e(format_datetime((string) $request['created_at'])) ?></p>
                <?php if ($request['decision_note'] !== null): ?><p><strong>Decision note:</strong> <?= e((string) $request['decision_note']) ?></p><?php endif; ?>
                <?php if ($request['reviewed_by'] !== null): ?><p><strong>Decided by:</strong> staff #<?= e((string) $request['reviewed_by']) ?></p><?php endif; ?>
            </div>
        </div>
    </section>

    <section class="card span-6" aria-labelledby="student-loans-title">
        <div class="card__header"><h2 id="student-loans-title">Student active loans</h2></div>
        <div class="card__body">
            <?php if ($studentLoans === []): ?><p class="muted">No active loans.</p><?php else: ?>
                <div class="table-wrap"><table class="table"><thead><tr><th>Title</th><th>Copy</th><th>Due</th></tr></thead><tbody>
                    <?php foreach ($studentLoans as $loan): ?>
                        <tr><td><?= e((string) $loan['book_title']) ?></td><td><code><?= e((string) $loan['copy_code']) ?></code></td><td class="nowrap"><?= e(format_date((string) $loan['due_date'])) ?></td><td><?= e((string) $loan['copy_status']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="card" aria-labelledby="actions-title">
    <div class="card__header"><h2 id="actions-title">Actions</h2></div>
    <div class="card__body">
        <?php if (in_array($request['status'], ['pending', 'approved'], true)): ?>
            <h3>Approve</h3>
            <?php if ($availableCopies === []): ?>
                <p class="alert alert--error" role="alert">No available physical copies. The request cannot be approved until a copy is available.</p>
            <?php else: ?>
                <form method="post" action="<?= e(url('staff/request-action.php')) ?>" class="stack small">
                    <?= csrf_field() ?>
                    <input type="hidden" name="request_id" value="<?= e((int) $request['id']) ?>">
                    <input type="hidden" name="action" value="approve">
                    <div class="field"><label for="copy_id">Select copy</label>
                        <select id="copy_id" name="copy_id" required>
                            <option value="">Choose…</option>
                            <?php foreach ($availableCopies as $copy): ?>
                                <option value="<?= e((int) $copy['id']) ?>"><?= e((string) $copy['copy_code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn--primary" type="submit">Approve request</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (in_array($request['status'], ['pending', 'approved'], true)): ?>
            <h3 style="margin-top:18px;">Reject or cancel</h3>
            <form method="post" action="<?= e(url('staff/request-action.php')) ?>" class="stack small">
                <?= csrf_field() ?>
                <input type="hidden" name="request_id" value="<?= e((int) $request['id']) ?>">
                <div class="field"><label for="decision_note">Decision note (optional)</label><textarea id="decision_note" name="decision_note" rows="2" maxlength="255"></textarea></div>
                <button class="btn btn--danger btn--sm" type="submit" name="action" value="reject">Reject</button>
                <?php if ($request['status'] === 'pending'): ?><button class="btn btn--ghost btn--sm" type="submit" name="action" value="cancel">Cancel</button><?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
