<?php
/**
 * Librarian action: approve, reject or cancel a borrow request.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

if (!is_post()) {
    abort_405('Borrow request actions must be submitted using a POST form.');
}

verify_csrf();

$requestId = filter_var($_POST['request_id'] ?? '', FILTER_VALIDATE_INT);
$action = (string) ($_POST['action'] ?? '');
if ($requestId === false || $requestId === null || $requestId < 1 || !in_array($action, ['approve', 'reject', 'cancel'], true)) {
    abort_404('The requested borrow request does not exist.');
}
$requestId = (int) $requestId;

$statement = $pdo->prepare('SELECT * FROM borrow_requests WHERE id = :id FOR UPDATE');
$statement->execute(['id' => $requestId]);
$request = $statement->fetch();
if ($request === false || $request === []) {
    abort_404('The requested borrow request does not exist.');
}

if ($action === 'approve') {
    $copyId = filter_var($_POST['copy_id'] ?? '', FILTER_VALIDATE_INT);
    if ($copyId === false || $copyId === null || $copyId < 1) {
        abort_404('The requested copy does not exist.');
    }
    circulation_approve_request($pdo, $requestId, (int) $copyId, (int) $user['id']);
    $assignedCopyId = (int) $copyId;
    log_activity($pdo, (int) $user['id'], 'borrow_request_approve', 'borrow_request', $requestId, 'Librarian approved request and reserved copy.');
    flash('success', 'Request approved. Copy reserved for collection.');
} elseif ($action === 'reject') {
    $note = trim((string) ($_POST['decision_note'] ?? ''));
    circulation_close_request($pdo, $requestId, 'rejected', (int) $user['id'], $note === '' ? null : $note);
    log_activity($pdo, (int) $user['id'], 'borrow_request_reject', 'borrow_request', $requestId, 'Librarian rejected request.');
    flash('success', 'Request rejected.');
} else {
    $note = trim((string) ($_POST['decision_note'] ?? ''));
    circulation_close_request($pdo, $requestId, 'cancelled', (int) $user['id'], $note === '' ? null : $note);
    log_activity($pdo, (int) $user['id'], 'borrow_request_cancel', 'borrow_request', $requestId, 'Librarian cancelled request.');
    flash('success', 'Request cancelled.');
}

redirect('staff/requests.php');
