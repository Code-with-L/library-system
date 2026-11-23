<?php
/**
 * Student catalogue book details and request form.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_role($pdo, 'student');

$id = filter_var($_GET['id'] ?? $_POST['id'] ?? '', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    abort_404('The requested book does not exist.');
}
$id = (int) $id;

$book = catalogue_book_get($pdo, $id);
if ($book === null) {
    abort_404('The requested book does not exist.');
}

$copies = catalogue_copies_for_book($pdo, $id);
$summary = catalogue_book_copy_summary($pdo, $id);
$canRequest = catalogue_student_can_request($pdo, (int) $user['id'], $id);
$hasActiveRequest = catalogue_student_has_active_request($pdo, (int) $user['id'], $id);
$requestFormAction = url('catalogue/request.php');

$pageTitle = 'Book details';
$activeNav = 'catalogue';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro">
    <div>
        <?= catalogue_cover_markup($book, true) ?>
        <h2><?= e($book['title']) ?></h2>
        <p><strong><?= e($book['author']) ?></strong></p>
        <p class="muted small">Published in <?= e((string) $book['publication_year'] ?? '—') ?> · <?= e($book['category_name'] ?? '—') ?></p>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('catalogue.php')) ?>">Back to catalogue</a>
    </div>
</div>

<?php if ((string) $book['description'] !== ''): ?>
    <section class="card">
        <div class="card__body"><h3>About this book</h3><p><?= e($book['description']) ?></p></div>
    </section>
<?php endif; ?>

<div class="panel-grid">
    <section class="card span-5" aria-labelledby="copy-summary-title">
        <div class="card__header"><h2 id="copy-summary-title">Physical copies</h2></div>
        <div class="card__body">
            <?php if ($summary['available'] > 0): ?>
                <p class="stat__value stat--info">Available now</p>
                <p class="mb-0">You can request this title.</p>
            <?php else: ?>
                <p class="stat__value">No copies available right now</p>
                <p class="mb-0 muted">New requests can be placed once a copy becomes available again.</p>
            <?php endif; ?>
            <div class="stat-grid" style="margin-top: 16px;">
                <article class="stat stat--info"><p class="stat__label">Available</p><p class="stat__value"><?= e($summary['available']) ?></p></article>
                <article class="stat"><p class="stat__label">Reserved</p><p class="stat__value"><?= e($summary['reserved']) ?></p></article>
                <article class="stat"><p class="stat__label">Issued</p><p class="stat__value"><?= e($summary['issued']) ?></p></article>
                <article class="stat"><p class="stat__label">In maintenance</p><p class="stat__value"><?= e($summary['maintenance']) ?></p></article>
            </div>
        </div>
    </section>

    <section class="card span-7" aria-labelledby="copies-title">
        <div class="card__header"><h2 id="copies-title">Copies</h2></div>
        <div class="card__body">
            <?php if ($copies === []): ?>
                <div class="empty-state"><h3>No copies yet</h3><p>This title has no physical copies registered.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Copy ID</th><th>Status</th><th>Location</th><th><span class="sr-only">Condition note</span></th></tr></thead>
                        <tbody>
                            <?php foreach ($copies as $copy): ?>
                                <tr>
                                    <td><code><?= e($copy['copy_code']) ?></code></td>
                                    <td><span class="badge <?= e(badge_class((string) $copy['status'])) ?>"><?= e(status_label((string) $copy['status'])) ?></span></td>
                                    <td><?= e((string) $copy['shelf_location'] ?? '—') ?></td>
                                    <td><?= e((string) $copy['condition_note'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php if ($canRequest === null && !$hasActiveRequest): ?>
    <form method="post" action="<?= e($requestFormAction) ?>" class="panel-grid">
        <section class="card span-12">
            <div class="card__header"><h2>Request this title</h2></div>
            <div class="card__body">
                <input type="hidden" name="book_id" value="<?= e($id) ?>">
                <p>Submitting a request places your name in the queue. It does not assign a physical copy yet.</p>
                <div class="form-actions">
                    <button class="btn btn--primary" type="submit">Request this title</button>
                </div>
            </div>
        </section>
    </form>
<?php elseif ($hasActiveRequest): ?>
    <div class="alert alert--info" role="status">You already have an active request for this title.</div>
<?php else: ?>
    <div class="alert alert--error" role="alert">You cannot request this title: <?= e(catalogue_error_message((string) $canRequest)) ?></div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

