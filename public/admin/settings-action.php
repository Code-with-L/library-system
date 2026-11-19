<?php
/**
 * Administrator approved library settings action.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_role($pdo, 'admin');

if (!is_post()) {
    abort_405('Settings must be submitted using a POST form.');
}
verify_csrf();

$input = [
    'library_name' => (string) ($_POST['library_name'] ?? ''),
    'loan_period_days' => (string) ($_POST['loan_period_days'] ?? ''),
    'fine_per_day' => (string) ($_POST['fine_per_day'] ?? ''),
];
$validation = reporting_validate_settings($input);
if ($validation['errors'] !== []) {
    foreach ($validation['errors'] as $error) { flash('error', $error); }
    redirect('admin/settings.php');
}

if (!reporting_update_settings($pdo, $validation, (int) $user['id'])) {
    flash('error', 'Settings could not be saved.');
} else {
    flash('success', 'Library settings updated.');
}
redirect('admin/settings.php');
