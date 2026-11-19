<?php
/**
 * Librarian creates or updates a catalogue book (POST).
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

if (!is_post()) {
    abort_405('Book forms must be submitted using a POST form.');
}

verify_csrf();

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
if ($id !== false && $id !== null && $id > 0) {
    $id = (int) $id;
    $book = catalogue_book_get($pdo, $id);
    if ($book === null) {
        abort_404('The requested book does not exist.');
    }
} else {
    $id = null;
}

$result = catalogue_save_book($pdo, [
    'title' => (string) ($_POST['title'] ?? ''),
    'author' => (string) ($_POST['author'] ?? ''),
    'isbn' => (string) ($_POST['isbn'] ?? ''),
    'publisher' => (string) ($_POST['publisher'] ?? ''),
    'publication_year' => (string) ($_POST['publication_year'] ?? ''),
    'description' => (string) ($_POST['description'] ?? ''),
    'category_id' => (string) ($_POST['category_id'] ?? ''),
    'status' => (string) ($_POST['status'] ?? 'active'),
], $id);

if ($result['errors'] !== []) {
    foreach ($result['errors'] as $error) {
        flash('error', $error);
    }
    redirect('staff/book-form.php' . ($id !== null ? '?id=' . $id : ''));
}

$entityId = (int) $result['id'];
log_activity($pdo, (int) $user['id'], $id === null ? 'catalogue_book_create' : 'catalogue_book_update', 'book', $entityId, $id === null ? 'Created catalogue book.' : 'Updated catalogue book.');
flash('success', $id === null ? 'Book created.' : 'Book updated.');
redirect('staff/catalogue.php');
