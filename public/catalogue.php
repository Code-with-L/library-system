<?php
/**
 * Student catalogue listing: search, category filter and pagination.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/pagination.php';

$user = require_role($pdo, 'student');

$categoryId = filter_var($_GET['category'] ?? '', FILTER_VALIDATE_INT);
if ($categoryId === false || $categoryId === null || $categoryId < 1) {
    $categoryId = null;
}

$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$filters = ['category_id' => $categoryId, 'search' => $search];

$total = catalogue_books_count($pdo, $filters);
$totalPages = max(1, (int) ceil($total / $perPage));
// Clamp before fetching so an out-of-range page shows the last real page.
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$books = catalogue_books_fetch($pdo, $filters, $offset, $perPage);
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();

$pageTitle = 'Catalogue';
$activeNav = 'catalogue';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-intro">
    <div>
        <h2>Catalogue</h2>
        <p>Browse titles, see how many copies are available and request what you need.</p>
    </div>
</div>

<form method="get" action="<?= e(url('catalogue.php')) ?>" class="filter-bar" aria-label="Catalogue filters">
    <div class="filter-group">
        <label for="q">Search by title, author or ISBN</label>
        <input id="q" name="q" type="search" value="<?= e($search) ?>" placeholder="Search…" autocomplete="off">
    </div>
    <div class="filter-group filter-group--narrow">
        <label for="category">Category</label>
        <select id="category" name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= e((int) $category['id']) ?>"<?= $categoryId === (int) $category['id'] ? ' selected' : '' ?>><?= e($category['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn--primary btn--sm">Search</button>
</form>

<?php if ($books === []): ?>
    <div class="empty-state">
        <h3>No books found</h3>
        <p>Try a different search, clear the category filter, or check back later for new titles.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Cover</th>
                    <th scope="col">Title</th>
                    <th scope="col">Author</th>
                    <th scope="col">Category</th>
                    <th scope="col">Available</th>
                    <th scope="col"><span class="sr-only">Details</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($books as $book): ?>
                    <tr>
                        <td><?= catalogue_cover_markup($book) ?></td>
                        <td><a href="<?= e(url('catalogue/detail.php?id=' . (int) $book['id'])) ?>"><strong><?= e($book['title']) ?></strong></a></td>
                        <td><?= e($book['author']) ?></td>
                        <td><?= e($book['category_name'] ?? '—') ?></td>
                        <td class="nowrap"><?= e((string) $book['status'] === 'active' ? count(catalogue_available_copies($pdo, (int) $book['id'])) : 0) ?> available</td>
                        <td class="nowrap"><a class="btn btn--ghost btn--sm" href="<?= e(url('catalogue/detail.php?id=' . (int) $book['id'])) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination-note">
        Showing <?= e((int) count($books)) ?> of <?= e($total) ?> titles.
    </div>

    <?php render_pagination($page, $totalPages, 'catalogue.php', [
        'category' => $categoryId ?? '',
        'q'        => $search,
    ]); ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
