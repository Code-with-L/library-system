<?php
/**
 * 500 - Server error.
 */
require_once __DIR__ . '/../includes/init.php';

$code = 500;
$title = 'Server error';
$message = 'The request could not be completed. The technical details have been logged.';

render_error_document($code, $title, $message);
