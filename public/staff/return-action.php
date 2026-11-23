<?php
/**
 * Librarian records a return (POST).
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

if (!is_post()) {
    abort_405('Return actions must be submitted using a POST form.');
}

verify_csrf();

$borrowingId = filter_var($_POST['borrowing_id'] ?? '', FILTER_VALIDATE_INT);
if ($borrowingId === false || $borrowingId === null || $borrowingId < 1) {
    abort_404('The requested borrowing does not exist.');
}

$copyStatus = (string) ($_POST['copy_status'] ?? 'available');
if (!in_array($copyStatus, ['available', 'maintenance'], true)) {
    $copyStatus = 'available';
}

try {
    circulation_return_borrowing($pdo, (int) $borrowingId, (int) $user['id'], date('Y-m-d'), $copyStatus);
    log_activity($pdo, (int) $user['id'], 'book_return', 'borrowings', (int) $borrowingId,
        $copyStatus === 'maintenance'
            ? 'Librarian recorded a lost/damaged return; copy placed in maintenance.'
            : 'Librarian recorded a return.');
    flash('success', $copyStatus === 'maintenance'
        ? 'Return recorded. The copy is in maintenance for review.'
        : 'Return recorded. The copy is available again.');
} catch (CirculationConflict $exception) {
    flash('error', $exception->getMessage());
}

redirect('staff/returns.php');
