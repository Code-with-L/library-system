<?php
/**
 * PDO database connection.
 *
 * The connection is created once and reused by the current request.
 * PDO exceptions are allowed to bubble up to the global exception handler,
 * which logs the technical detail and shows a safe error page.
 */

if (DB_HOST === '' || DB_NAME === '' || DB_USER === '') {
    // Fail closed with a generic message. No credentials, paths or SQL shown.
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Database not configured</title></head>'
        . '<body><main><h1>Database not configured</h1><p>Contact the site administrator.</p></main></body></html>';
    exit;
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    DB_HOST,
    DB_PORT,
    DB_NAME,
    DB_CHARSET
);

$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_STRINGIFY_FETCHES  => false,
    PDO::ATTR_PERSISTENT         => false,
]);

$pdo->exec("SET time_zone = '+03:00'");
