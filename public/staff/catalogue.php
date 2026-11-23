<?php
/**
 * Librarian/admin catalogue listing: books and physical copy summary.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

$books = catalogue_books_fetch($pdo);

$pageTitle = 'Catalogue';
$activeNav = 'catalogue';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro">
    <div>
        <h2>Catalogue</h2>
        <p>Browse recorded titles, then add or edit books and their physical copies.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn--primary btn--sm" href="<?= e(url('staff/book-form.php')) ?>">Add book</a>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('staff/categories.php')) ?>">Categories</a>
    </div>
</div>

<?php if ($books === []): ?>
    <div class="empty-state"><h3>No books</h3><p>No books are currently registered.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Title</th><th>Author</th><th>ISBN</th><th>Category</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
                <?php foreach ($books as $book): ?>
                    <tr>
                        <td><a href="<?= e(url('staff/book-form.php?id=' . (int) $book['id'])) ?>"><strong><?= e($book['title']) ?></strong></a></td>
                        <td><?= e($book['author']) ?></td>
                        <td class="nowrap mono"><?= e($book['isbn']) ?></td>
                        <td><?= e($book['category_name'] ?? '—') ?></td>
                        <td><span class="badge <?= e(badge_class((string) $book['status'])) ?>"><?= e(status_label((string) $book['status'])) ?></span></td>
                        <td class="nowrap">
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('staff/book-form.php?id=' . (int) $book['id'])) ?>">Edit</a>
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('staff/copy-form.php?book_id=' . (int) $book['id'])) ?>">Copies</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
