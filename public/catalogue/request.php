<?php
/**
 * Student borrow-request submission.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_role($pdo, 'student');

if (!is_post()) {
    abort_405('Borrow requests must be submitted using a POST form.');
}

verify_csrf();

$bookId = filter_var($_POST['book_id'] ?? '', FILTER_VALIDATE_INT);
if ($bookId === false || $bookId === null || $bookId < 1) {
    abort_404('The requested book does not exist.');
}

$error = catalogue_student_can_request($pdo, (int) $user['id'], (int) $bookId);
if ($error !== null) {
    flash('error', catalogue_error_message($error));
    redirect('catalogue/detail.php?id=' . (int) $bookId);
}

try {
    $requestId = circulation_request_book($pdo, (int) $user['id'], (int) $bookId);
} catch (CirculationConflict $exception) {
    // A concurrent duplicate or a limit change can still fail after the
    // pre-check above. Show a clean validation error instead of a 500.
    flash('error', catalogue_error_message($exception->getConflictCode()) !== 'The request could not be completed.'
        ? catalogue_error_message($exception->getConflictCode())
        : $exception->getMessage());
    redirect('catalogue/detail.php?id=' . (int) $bookId);
}
log_activity($pdo, (int) $user['id'], 'borrow_request_create', 'borrow_request', $requestId, 'Student requested a title.');
flash('success', 'Your request has been submitted.');
redirect('catalogue/history.php');

