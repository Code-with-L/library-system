<?php
/**
 * Librarian assesses a fine for an active overdue borrowing (POST).
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

if (!is_post()) {
    abort_405('Fine assessment must be submitted using a POST form.');
}
verify_csrf();

$borrowingId = filter_var($_POST['borrowing_id'] ?? '', FILTER_VALIDATE_INT);
if ($borrowingId === false || $borrowingId === null || $borrowingId < 1) {
    abort_404('The requested borrowing does not exist.');
}

try {
    $fineId = fines_assess_active_overdue_for_borrowing($pdo, (int) $borrowingId);
    if ($fineId > 0) {
        log_activity($pdo, (int) $user['id'], 'fine_assessed', 'fine', $fineId,
            'Librarian assessed a fine for an active overdue loan.');
        flash('success', 'Fine assessed and recorded as outstanding.');
    } else {
        flash('info', 'No new fine was needed for this borrowing.');
    }
} catch (Throwable $exception) {
    error_log('Active fine assessment failed: ' . $exception->getMessage());
    flash('error', 'The fine could not be assessed. Please try again.');
}

redirect('staff/reports/overdue.php');
