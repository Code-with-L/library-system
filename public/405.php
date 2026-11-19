<?php
/**
 * 405 - Method not allowed.
 */
require_once __DIR__ . '/../includes/init.php';

$code = (int) ($GLOBALS['errorCode'] ?? 405);
$title = (string) ($GLOBALS['errorTitle'] ?? 'Method not allowed');
$message = (string) ($GLOBALS['errorMessage'] ?? 'This action must be submitted using a POST form.');

if (!headers_sent()) {
    header('Allow: POST');
}

render_error_document($code, $title, $message);
