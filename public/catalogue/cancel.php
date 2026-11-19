<?php
/**
 * Cancel a student borrow request.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_role($pdo, 'student');

if (!is_post()) {
    abort_405('Borrow request cancellation must be submitted using a POST form.');
}

verify_csrf();

$requestId = filter_var($_POST['request_id'] ?? '', FILTER_VALIDATE_INT);
if ($requestId === false || $requestId === null || $requestId < 1) {
    abort_404('The requested borrow request does not exist.');
}

try {
    circulation_cancel_student_request($pdo, (int) $requestId, (int) $user['id'], (int) $user['id']);
    log_activity($pdo, (int) $user['id'], 'borrow_request_cancel', 'borrow_request', (int) $requestId, 'Student cancelled a borrow request.');
    flash('success', 'Your borrow request has been cancelled.');
} catch (CirculationConflict $exception) {
    flash('error', catalogue_error_message($exception->getConflictCode()));
}

redirect('catalogue/history.php');

