<?php
/**
 * Librarian category create/update/delete (POST).
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

if (!is_post()) {
    abort_405('Category forms must be submitted using a POST form.');
}

verify_csrf();

$action = (string) ($_POST['action'] ?? '');
if ($action === 'delete') {
    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id < 1) {
        abort_404('The requested category does not exist.');
    }
    $id = (int) $id;
    if (!catalogue_delete_category_if_unused($pdo, $id)) {
        flash('error', 'The category cannot be deleted while it is used by books.');
        redirect('staff/categories.php');
    }
    log_activity($pdo, (int) $user['id'], 'catalogue_category_delete', 'category', $id, 'Deleted category.');
    flash('success', 'Category deleted.');
    redirect('staff/categories.php');
}

if ($action === 'save') {
    $id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
    $id = ($id !== false && $id !== null && $id >= 1) ? (int) $id : null;
    $result = catalogue_save_category($pdo, [
        'name' => (string) ($_POST['name'] ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
    ], $id);
    if ($result['errors'] !== []) {
        foreach ($result['errors'] as $error) {
            flash('error', $error);
        }
        redirect('staff/category-form.php' . ($id !== null ? '?id=' . $id : ''));
    }
    $entityId = (int) $result['id'];
    log_activity($pdo, (int) $user['id'], $id === null ? 'catalogue_category_create' : 'catalogue_category_update', 'category', $entityId, $id === null ? 'Created category.' : 'Updated category.');
    flash('success', $id === null ? 'Category created.' : 'Category updated.');
    redirect('staff/categories.php');
}

abort_404('Unknown category action.');
