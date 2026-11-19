<?php
/**
 * 403 - Access denied.
 *
 * When reached through abort_403(), the error details are provided by globals.
 * The page also works when requested directly.
 */
require_once __DIR__ . '/../includes/init.php';

$code = (int) ($GLOBALS['errorCode'] ?? 403);
$title = (string) ($GLOBALS['errorTitle'] ?? 'Access denied');
$message = (string) ($GLOBALS['errorMessage'] ?? 'You do not have permission to access this page.');

render_error_document($code, $title, $message);
