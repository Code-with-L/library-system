<?php
/**
 * Librarian fine details and calculation breakdown.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    abort_404('The requested fine does not exist.');
}
$id = (int) $id;

$fine = fines_get($pdo, $id);
if ($fine === null) {
    abort_404('The requested fine does not exist.');
}

$pageTitle = 'Fine details';
$activeNav = 'fines';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro"><div><h2>Fine #<?= e((string) $fine['id']) ?></h2><p>Borrowing and calculation breakdown.</p></div><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/fines.php')) ?>">Back to fines</a></div>

<div class="panel-grid">
    <section class="card span-6" aria-labelledby="fine-summary-title">
        <div class="card__header"><h2 id="fine-summary-title">Fine summary</h2></div>
        <div class="card__body">
            <div class="stack">
                <p><strong>Borrowing:</strong> #<?= e((string) $fine['borrowing_id']) ?></p>
                <p><strong>Student:</strong> <?= e((string) $fine['student_name']) ?></p>
                <p><strong>Due date:</strong> <?= e(format_date((string) $fine['due_date'] ?? '')) ?></p>
                <p><strong>Overdue days:</strong> <?= e((string) fines_active_overdue_days($pdo, (int) $fine['borrowing_id'])) ?></p>
                <p><strong>Rate:</strong> <?= e(fines_format((float) $fine['rate_per_day'], 'KES')) ?> per day</p>
                <p><strong>Amount:</strong> <?= e(fines_format((float) $fine['amount'], 'KES')) ?></p>
                <p><strong>Status:</strong> <span class="badge <?= e(badge_class((string) $fine['status'])) ?>"><?= e(status_label((string) $fine['status'])) ?></span></p>
                <?php if ($fine['settled_by_id'] !== null): ?><p><strong>Settled by:</strong> staff #<?= e((string) $fine['settled_by_id']) ?></p><?php endif; ?>
                <?php if ($fine['settled_at'] !== null): ?><p><strong>Settled at:</strong> <?= e(format_datetime((string) $fine['settled_at'])) ?></p><?php endif; ?>
            </div>
        </div>
    </section>
    <section class="card span-6" aria-labelledby="fine-copy-title">
        <div class="card__header"><h2 id="fine-copy-title">Physical copy</h2></div>
        <div class="card__body">
            <div class="stack">
                <p><strong>Copy:</strong> <?= e((string) $fine['copy_code'] ?? '—') ?></p>
                <p><strong>Borrowing status:</strong> <?= e((string) $fine['borrowing_status']) ?></p>
                <p><strong>Return date:</strong> <?= e(format_date((string) $fine['return_date'] ?? '')) ?></p>
            </div>
        </div>
    </section>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
