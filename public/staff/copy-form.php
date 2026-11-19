<?php
/**
 * Add or edit physical copies (librarian).
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

$bookId = filter_var($_GET['book_id'] ?? '', FILTER_VALIDATE_INT);
if ($bookId === false || $bookId === null || $bookId < 1) {
    abort_404('The requested book does not exist.');
}
$bookId = (int) $bookId;

if (!catalogue_book_get($pdo, $bookId)) {
    abort_404('The requested book does not exist.');
}

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$copy = null;
if ($id !== false && $id !== null && $id >= 1) {
    $id = (int) $id;
    $copy = catalogue_copy_get($pdo, $id);
    if ($copy === null || (int) $copy['book_id'] !== $bookId) {
        $id = null;
    }
} else {
    $id = null;
}

$errors = [];
$values = [
    'book_id' => $bookId, 'copy_code' => '', 'status' => 'available',
    'shelf_location' => '', 'condition_note' => '',
];
if ($copy !== null) {
    $values = [
        'book_id' => $bookId, 'copy_code' => (string) $copy['copy_code'], 'status' => (string) $copy['status'],
        'shelf_location' => (string) ($copy['shelf_location'] ?? ''), 'condition_note' => (string) ($copy['condition_note'] ?? ''),
    ];
}

$pageTitle = $copy !== null ? 'Edit copy' : 'Add copy';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro"><div><h2><?= e($pageTitle) ?></h2><p>Physical identifier, status and location for book <?= e((string) catalogue_book_get($pdo, $bookId)['title']) ?>.</p></div><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/catalogue.php')) ?>">Back to catalogue</a></div>

<form method="post" action="<?= e(url('staff/copy-action.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="book_id" value="<?= e($bookId) ?>">
    <?php if ($copy !== null): ?><input type="hidden" name="id" value="<?= e($id) ?>"><?php endif; ?>
    <div class="form-grid">
        <div class="field field--full"><label for="copy_code">Copy identifier</label><input id="copy_code" name="copy_code" maxlength="40" value="<?= e($values['copy_code']) ?>" required <?= isset($errors['copy_code']) ? 'aria-invalid="true"' : '' ?>><p class="field__hint">Unique identifier, e.g. CLS-001-01.</p></div>
        <div class="field"><label for="status">Status</label><select id="status" name="status">
            <?php foreach (catalogue_copy_status_allowed() as $status): ?>
                <option value="<?= e($status) ?>"<?= $values['status'] === $status ? ' selected' : '' ?>><?= e(status_label($status)) ?></option>
            <?php endforeach; ?>
        </select></div>
        <div class="field"><label for="shelf_location">Shelf location</label><input id="shelf_location" name="shelf_location" maxlength="40" value="<?= e($values['shelf_location']) ?>"></div>
        <div class="field field--full"><label for="condition_note">Condition note</label><textarea id="condition_note" name="condition_note" rows="2" maxlength="255"><?= e($values['condition_note']) ?></textarea></div>
    </div>
    <div class="form-actions"><button class="btn btn--primary" type="submit"><?= $copy !== null ? 'Save copy' : 'Add copy' ?></button><a class="btn btn--ghost" href="<?= e(url('staff/catalogue.php')) ?>">Back</a></div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
