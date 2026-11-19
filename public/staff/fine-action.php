<?php
/**
 * Librarian manual settlement of an outstanding fine (POST).
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_staff($pdo);

if (!is_post()) {
    abort_405('Fine settlement must be submitted using a POST form.');
}

verify_csrf();

$fineId = filter_var($_POST['fine_id'] ?? '', FILTER_VALIDATE_INT);
if ($fineId === false || $fineId === null || $fineId < 1) {
    abort_404('The requested fine does not exist.');
}

try {
    $fineId = fines_settle($pdo, (int) $fineId, (int) $user['id']);
    log_activity($pdo, (int) $user['id'], 'fine_settled', 'fine', $fineId, 'Librarian manually settled a fine.');
    flash('success', 'Fine settled.');
} catch (FinesConflict $exception) {
    flash('error', $exception->getMessage());
}

redirect('staff/fines.php');
