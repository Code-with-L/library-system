<?php
/**
 * Librarian category list with book counts.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

$statement = $pdo->prepare(
    'SELECT c.id, c.name, c.description, (SELECT COUNT(*) FROM books b WHERE b.category_id = c.id) AS book_count
       FROM categories c
      ORDER BY c.name ASC'
);
$categories = (array) $statement->fetchAll();

$pageTitle = 'Categories';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro"><div><h2>Categories</h2><p>Book catalogue categories.</p></div><a class="btn btn--primary btn--sm" href="<?= e(url('staff/category-form.php')) ?>">Add category</a></div>

<?php if ($categories === []): ?>
    <div class="empty-state"><h3>No categories</h3><p>There are no book categories yet.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Name</th><th>Books</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <td><a href="<?= e(url('staff/category-form.php?id=' . (int) $category['id'])) ?>"><strong><?= e($category['name']) ?></strong></a> <?php if ((int) $category['book_count'] > 0) { echo '(' . (int) $category['book_count'] . ' books)'; } ?></td>
                        <td><?= e((string) ($category['book_count'] ?? 0)) ?></td>
                        <td class="nowrap">
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('staff/category-form.php?id=' . (int) $category['id'])) ?>">Edit</a>
                            <?php if ((int) $category['book_count'] === 0): ?>
                                <form method="post" action="<?= e(url('staff/category-action.php')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e((int) $category['id']) ?>">
                                    <button class="btn btn--ghost btn--sm" type="submit">Delete</button>
                                </form>
                            <?php else: ?>
                                <span class="muted small">Referenced by books</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
