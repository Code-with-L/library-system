<?php
/**
 * Add or edit a catalogue category (librarian).
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if ($id !== false && $id !== null && $id >= 1) {
    $id = (int) $id;
    $statement = $pdo->prepare('SELECT id, name, description FROM categories WHERE id = :id');
    $statement->execute(['id' => $id]);
    $category = $statement->fetch();
    if ($category === []) {
        abort_404('The requested category does not exist.');
    }
} else {
    $id = null;
    $category = ['name' => '', 'description' => ''];
}

$errors = [];
$values = ['name' => (string) $category['name'], 'description' => (string) $category['description']];

if (is_post()) {
    verify_csrf();
    $values = ['name' => (string) ($_POST['name'] ?? ''), 'description' => (string) ($_POST['description'] ?? '')];
    $result = catalogue_save_category($pdo, $values, $id);
    $errors = $result['errors'];
    if ($errors === []) {
        log_activity($pdo, (int) $user['id'], $id === null ? 'catalogue_category_create' : 'catalogue_category_update', 'category', (int) $result['id'], $id === null ? 'Created category.' : 'Updated category.');
        flash('success', $id === null ? 'Category created.' : 'Category updated.');
        redirect('staff/categories.php');
    }
}

$pageTitle = $id !== null ? 'Edit category' : 'Add category';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro"><div><h2><?= e($pageTitle) ?></h2><p>Category name and books grouped under it.</p></div><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/categories.php')) ?>">Back to categories</a></div>

<form method="post" action="<?= e(url('staff/category-action.php' . ($id !== null ? '?id=' . $id : ''))) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($id !== null): ?><input type="hidden" name="id" value="<?= e($id) ?>"><?php endif; ?>
    <div class="form-grid">
        <div class="field field--full"><label for="name">Name</label><input id="name" name="name" maxlength="80" value="<?= e($values['name']) ?>" required <?= isset($errors['name']) ? 'aria-invalid="true"' : '' ?>><?php if (isset($errors['name'])) { ?><p class="field__error"><?= e($errors['name']) ?></p><?php } ?></div>
        <div class="field field--full"><label for="description">Description</label><textarea id="description" name="description" rows="2" maxlength="255"><?= e($values['description']) ?></textarea><?php if (isset($errors['description'])) { ?><p class="field__error"><?= e($errors['description']) ?></p><?php } ?></div>
    </div>
    <div class="form-actions"><button class="btn btn--primary" type="submit"><?= $id !== null ? 'Save category' : 'Create category' ?></button><a class="btn btn--ghost" href="<?= e(url('staff/categories.php')) ?>">Back</a></div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
