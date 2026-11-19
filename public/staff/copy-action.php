<?php
/**
 * Librarian creates or updates a physical copy (POST).
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

if (!is_post()) {
    abort_405('Copy forms must be submitted using a POST form.');
}

verify_csrf();

$bookId = filter_var($_POST['book_id'] ?? '', FILTER_VALIDATE_INT);
if ($bookId === false || $bookId === null || $bookId < 1) {
    abort_404('The requested book does not exist.');
}
$bookId = (int) $bookId;
if (catalogue_book_get($pdo, $bookId) === null) {
    abort_404('The requested book does not exist.');
}

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
if ($id !== false && $id !== null && $id >= 1) {
    $id = (int) $id;
    $copy = catalogue_copy_get($pdo, $id);
    if ($copy === null || (int) $copy['book_id'] !== $bookId) {
        $id = null;
    }
} else {
    $id = null;
}

$result = catalogue_save_copy($pdo, [
    'book_id' => $bookId,
    'copy_code' => (string) ($_POST['copy_code'] ?? ''),
    'status' => (string) ($_POST['status'] ?? 'available'),
    'shelf_location' => (string) ($_POST['shelf_location'] ?? ''),
    'condition_note' => (string) ($_POST['condition_note'] ?? ''),
], $id);

if ($result['errors'] !== []) {
    foreach ($result['errors'] as $error) {
        flash('error', $error);
    }
    redirect('staff/copy-form.php?book_id=' . $bookId . ($id !== null ? '&id=' . $id : ''));
}

$entityId = (int) $result['id'];
log_activity($pdo, (int) $user['id'], $id === null ? 'catalogue_copy_create' : 'catalogue_copy_update', 'copy', $entityId, $id === null ? 'Created physical copy.' : 'Updated physical copy.');
flash('success', $id === null ? 'Copy created.' : 'Copy updated.');
redirect('staff/catalogue.php');
