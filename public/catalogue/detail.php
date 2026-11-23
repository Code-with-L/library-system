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
$requestAllowed = $canRequest === null && !$hasActiveRequest;
$coverPath = (string) ($book['cover_image'] ?? '');
$hasCover = strpos($coverPath, 'uploads/covers/') === 0;

// Other titles in the same category, excluding this one.
$related = [];
$suggestions = catalogue_books_fetch($pdo, ['category_id' => (int) ($book['category_id'] ?? 0)], 0, 8);
foreach ($suggestions as $suggestion) {
    if ((int) $suggestion['id'] === $id) {
        continue;
    }
    $related[] = $suggestion;
    if (count($related) >= 4) {
        break;
    }
}

$pageTitle = 'Book details';
$activeNav = 'catalogue';
require __DIR__ . '/../../includes/header.php';
?>

<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(url('catalogue.php')) ?>">Catalogue</a>
    <span aria-hidden="true">/</span>
    <span><?= e($book['category_name'] ?? 'Book') ?></span>
</nav>

<section class="book-hero" aria-labelledby="book-title">
    <div class="book-hero__cover">
        <?php if ($hasCover): ?>
            <img src="<?= e(url($coverPath)) ?>" alt="Cover of <?= e($book['title']) ?>">
        <?php else: ?>
            <div class="book-hero__cover-empty"><span><?= e($book['title']) ?></span></div>
        <?php endif; ?>
    </div>

    <div class="book-hero__info">
        <p class="book-hero__eyebrow"><?= e($book['category_name'] ?? 'Uncategorised') ?></p>
        <h1 id="book-title" class="book-hero__title"><?= e($book['title']) ?></h1>
        <p class="book-hero__author">by <strong><?= e($book['author']) ?></strong></p>

        <div class="book-hero__chips">
            <?php if (!empty($book['publication_year'])): ?>
                <span class="chip">Published <?= e((string) $book['publication_year']) ?></span>
            <?php endif; ?>
            <?php if (!empty($book['publisher'])): ?>
                <span class="chip"><?= e($book['publisher']) ?></span>
            <?php endif; ?>
            <span class="chip">ISBN <?= e($book['isbn']) ?></span>
            <span class="chip chip--strong"><?= e(catalogue_availability_label((int) $summary['available'])) ?> available</span>
        </div>

        <div class="book-hero__actions">
            <?php if ($requestAllowed): ?>
                <form method="post" action="<?= e(url('catalogue/request.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="book_id" value="<?= e($id) ?>">
                    <button class="btn btn--primary btn--lg" type="submit">Request this title</button>
                </form>
                <p class="book-hero__note">Placing a request joins the queue &mdash; a copy is assigned by library staff.</p>
            <?php elseif ($hasActiveRequest): ?>
                <span class="badge badge--info badge--lg">Request pending</span>
                <p class="book-hero__note">You already have an active request for this title.</p>
            <?php else: ?>
                <span class="badge badge--muted badge--lg">Not available to request</span>
                <p class="book-hero__note"><?= e(catalogue_error_message((string) $canRequest)) ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ((string) ($book['description'] ?? '') !== ''): ?>
    <section class="card detail-card">
        <div class="card__header"><h2>About this book</h2></div>
        <div class="card__body"><p class="detail-description"><?= nl2br(e($book['description'])) ?></p></div>
    </section>
<?php endif; ?>

<div class="panel-grid">
    <section class="card span-5" aria-labelledby="copy-summary-title">
        <div class="card__header"><h2 id="copy-summary-title">Availability</h2></div>
        <div class="card__body">
            <div class="stat-grid stat-grid--compact">
                <article class="stat stat--info"><p class="stat__label">Available</p><p class="stat__value"><?= e((string) $summary['available']) ?></p></article>
                <article class="stat"><p class="stat__label">Reserved</p><p class="stat__value"><?= e((string) $summary['reserved']) ?></p></article>
                <article class="stat"><p class="stat__label">Issued</p><p class="stat__value"><?= e((string) $summary['issued']) ?></p></article>
                <article class="stat stat--gold"><p class="stat__label">Maintenance</p><p class="stat__value"><?= e((string) $summary['maintenance']) ?></p></article>
            </div>
        </div>
    </section>

    <section class="card span-7" aria-labelledby="copies-title">
        <div class="card__header"><h2 id="copies-title">Physical copies</h2></div>
        <div class="card__body">
            <?php if ($copies === []): ?>
                <div class="empty-state"><h3>No copies yet</h3><p>This title has no physical copies registered.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table table--copies">
                        <thead><tr><th>Copy ID</th><th>Status</th><th>Location</th><th>Condition</th></tr></thead>
                        <tbody>
                            <?php foreach ($copies as $copy): ?>
                                <tr>
                                    <td data-label="Copy ID"><code><?= e($copy['copy_code']) ?></code></td>
                                    <td data-label="Status"><span class="badge <?= e(badge_class((string) $copy['status'])) ?>"><?= e(status_label((string) $copy['status'])) ?></span></td>
                                    <td data-label="Location"><?= e((string) $copy['shelf_location'] ?? '—') ?></td>
                                    <td data-label="Condition"><?= e((string) $copy['condition_note'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="card detail-card">
    <div class="card__header"><h2>How borrowing works</h2></div>
    <div class="card__body">
        <ol class="steps">
            <li><strong>Request the title.</strong> Your name joins the queue for the next available copy.</li>
            <li><strong>Wait for library staff.</strong> They assign a physical copy and mark it as issued to you.</li>
            <li><strong>Collect and return on time.</strong> Loan dates and any fines appear on your Loans and Fines pages.</li>
        </ol>
    </div>
</section>

<?php if ($related !== []): ?>
    <section class="card detail-card" aria-labelledby="related-title">
        <div class="card__header"><h2 id="related-title">More in <?= e($book['category_name'] ?? 'this category') ?></h2></div>
        <div class="card__body">
            <div class="related-grid">
                <?php foreach ($related as $item): ?>
                    <a class="related-card" href="<?= e(url('catalogue/detail.php?id=' . (int) $item['id'])) ?>">
                        <?= catalogue_cover_markup($item) ?>
                        <span class="related-card__title"><?= e($item['title']) ?></span>
                        <span class="related-card__author"><?= e($item['author']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<p class="detail-back"><a class="btn btn--ghost" href="<?= e(url('catalogue.php')) ?>">&larr; Back to catalogue</a></p>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
