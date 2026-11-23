<?php
/**
 * Librarian records issuance of an approved request (POST).
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);
$settings = get_settings($pdo);

if (!is_post()) {
    abort_405('Issuance must be submitted using a POST form.');
}

verify_csrf();

$requestId = filter_var($_POST['request_id'] ?? '', FILTER_VALIDATE_INT);
if ($requestId === false || $requestId === null || $requestId < 1) {
    abort_404('The requested borrow request does not exist.');
}

$statement = $pdo->prepare(
    'SELECT id, user_id, book_id, status, reserved_copy_id, assigned_copy_id
       FROM borrow_requests WHERE id = :id FOR UPDATE'
);
$statement->execute(['id' => (int) $requestId]);
$request = $statement->fetch();
if ($request === false) {
    abort_404('The requested borrow request does not exist.');
}
if ((string) $request['status'] !== 'approved') {
    flash('error', 'Only approved requests can be issued.');
    redirect('staff/issue.php');
}

$copyId = (int) ($request['reserved_copy_id'] ?? $request['assigned_copy_id']);
if ($copyId < 1 || catalogue_copy_get($pdo, $copyId) === null) {
    flash('error', 'The approved copy is missing.');
    redirect('staff/issue.php');
}

try {
    $borrowingId = circulation_fulfil_request($pdo, (int) $request['id'], $copyId, (int) $user['id'], (int) $settings['loan_period_days']);
} catch (CirculationConflict $exception) {
    flash('error', $exception->getMessage());
    redirect('staff/issue.php');
}

$dueDateStatement = $pdo->prepare('SELECT due_date FROM borrowings WHERE id = :id');
$dueDateStatement->execute(['id' => $borrowingId]);
$dueDate = $dueDateStatement->fetchColumn();

log_activity($pdo, (int) $user['id'], 'book_issue', 'borrowings', $borrowingId, 'Librarian issued book to student.');
flash('success', 'Book issued.' . ($dueDate !== false ? ' Due date: ' . e((string) $dueDate) . '.' : ''));
redirect('staff/returns.php');
