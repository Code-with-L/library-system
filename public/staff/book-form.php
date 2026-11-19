<?php
/**
 * Add or edit a catalogue book (librarian).
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();

$id = null;
$book = null;
$isEdit = false;
if (isset($_GET['id'])) {
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($id !== false && $id !== null && $id >= 1) {
        $id = (int) $id;
        $book = catalogue_book_get($pdo, $id);
        if ($book !== null) {
            $isEdit = true;
        } else {
            $id = null;
        }
    } else {
        $id = null;
    }
}

$errors = [];
$values = [
    'title' => '', 'author' => '', 'isbn' => '', 'publisher' => '',
    'publication_year' => '', 'description' => '', 'category_id' => '', 'status' => 'active',
];
if ($book !== null) {
    $values = [
        'title' => (string) $book['title'], 'author' => (string) $book['author'], 'isbn' => (string) $book['isbn'],
        'publisher' => (string) ($book['publisher'] ?? ''), 'publication_year' => (string) ($book['publication_year'] ?? ''),
        'description' => (string) ($book['description'] ?? ''), 'category_id' => (string) $book['category_id'],
        'status' => (string) $book['status'],
    ];
}

if (is_post()) {
    verify_csrf();
    $values = [
        'title' => (string) ($_POST['title'] ?? ''), 'author' => (string) ($_POST['author'] ?? ''),
        'isbn' => (string) ($_POST['isbn'] ?? ''), 'publisher' => (string) ($_POST['publisher'] ?? ''),
        'publication_year' => (string) ($_POST['publication_year'] ?? ''), 'description' => (string) ($_POST['description'] ?? ''),
        'category_id' => (string) ($_POST['category_id'] ?? ''), 'status' => (string) ($_POST['status'] ?? 'active'),
    ];
    $result = catalogue_save_book($pdo, $values, $id);
    $errors = $result['errors'];
    if ($errors === []) {
        $action = $isEdit ? 'catalogue_book_update' : 'catalogue_book_create';
        $entityId = (int) $result['id'];
        log_activity($pdo, (int) $user['id'], $action, 'book', $entityId, $isEdit ? 'Updated catalogue book.' : 'Created catalogue book.');
        flash('success', $isEdit ? 'Book updated.' : 'Book created.');
        redirect('staff/catalogue.php');
    }
}

$pageTitle = $isEdit ? 'Edit book' : 'Add book';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro"><div><h2><?= e($isEdit ? 'Edit book' : 'Add book') ?></h2><p>Catalogue titles. Physical copies are managed separately.</p></div></div>

<form method="post" action="<?= e(url('staff/book-action.php' . ($isEdit ? '?id=' . $id : ''))) ?>">
    <?= csrf_field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= e($id) ?>"><?php endif; ?>
    <div class="form-grid">
        <div class="field field--full"><label for="title">Title</label><input id="title" name="title" maxlength="255" value="<?= e($values['title']) ?>" required <?= isset($errors['title']) ? 'aria-invalid="true"' : '' ?>><?php if (isset($errors['title'])) { ?><p class="field__error"><?= e($errors['title']) ?></p><?php } ?></div>
        <div class="field field--full"><label for="author">Author</label><input id="author" name="author" maxlength="200" value="<?= e($values['author']) ?>" required <?= isset($errors['author']) ? 'aria-invalid="true"' : '' ?>><?php if (isset($errors['author'])) { ?><p class="field__error"><?= e($errors['author']) ?></p><?php } ?></div>
        <div class="field field--full"><label for="isbn">ISBN</label><input id="isbn" name="isbn" maxlength="20" value="<?= e($values['isbn']) ?>" <?= isset($errors['isbn']) ? 'aria-invalid="true"' : '' ?>><?php if (isset($errors['isbn'])) { ?><p class="field__error"><?= e($errors['isbn']) ?></p><?php } ?></div>
        <div class="field"><label for="category_id">Category</label><select id="category_id" name="category_id" required <?= isset($errors['category_id']) ? 'aria-invalid="true"' : '' ?>>
            <option value="">Choose category…</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= e((int) $category['id']) ?>"<?= $values['category_id'] === (string) $category['id'] ? ' selected' : '' ?>><?= e($category['name']) ?></option>
            <?php endforeach; ?>
        </select><?php if (isset($errors['category_id'])) { ?><p class="field__error"><?= e($errors['category_id']) ?></p><?php } ?></div>
        <div class="field"><label for="publication_year">Publication year</label><input id="publication_year" name="publication_year" maxlength="4" value="<?= e($values['publication_year']) ?>" inputmode="numeric" placeholder="Year"></div>
        <div class="field"><label for="publisher">Publisher</label><input id="publisher" name="publisher" maxlength="150" value="<?= e($values['publisher']) ?>"></div>
        <div class="field"><label for="status">Status</label><select id="status" name="status"><option value="active"<?= $values['status'] === 'active' ? ' selected' : '' ?>>Active</option><option value="archived"<?= $values['status'] === 'archived' ? ' selected' : '' ?>>Archived</option></select></div>
        <div class="field field--full"><label for="description">Description</label><textarea id="description" name="description" rows="3"><?= e($values['description']) ?></textarea></div>
    </div>
    <div class="form-actions"><button class="btn btn--primary" type="submit"><?= $isEdit ? 'Save book' : 'Create book' ?></button><a class="btn btn--ghost" href="<?= e(url('staff/catalogue.php')) ?>">Back</a></div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
