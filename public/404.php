<?php
/**
 * 404 - Page not found.
 */
require_once __DIR__ . '/../includes/init.php';

$code = (int) ($GLOBALS['errorCode'] ?? 404);
$title = (string) ($GLOBALS['errorTitle'] ?? 'Page not found');
$message = (string) ($GLOBALS['errorMessage'] ?? 'The requested page or record could not be found.');

render_error_document($code, $title, $message);
