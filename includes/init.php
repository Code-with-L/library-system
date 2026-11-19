<?php
/**
 * Shared request bootstrap.
 *
 * Every public page includes this file before doing any work. It loads
 * configuration, helpers and secure session handling, then connects to MySQL.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/activity.php';

set_exception_handler('handle_exception');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
    );

    $cookieSecure = $isHttps;
    if (SESSION_COOKIE_SECURE_OVERRIDE !== null) {
        $cookieSecure = filter_var(SESSION_COOKIE_SECURE_OVERRIDE, FILTER_VALIDATE_BOOLEAN);
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string) SESSION_TIMEOUT);

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_PATH === '' ? '/' : BASE_PATH . '/',
        'domain'   => '',
        'secure'   => $cookieSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!headers_sent()) {
    foreach (security_headers($isHttps ?? false) as $headerName => $headerValue) {
        header($headerName . ': ' . $headerValue);
    }
}

// Expire idle authenticated sessions.
if (!empty($_SESSION['user_id']) && isset($_SESSION['last_activity'])) {
    $idleSeconds = time() - (int) $_SESSION['last_activity'];

    if ($idleSeconds > SESSION_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('warning', 'Your session expired. Please log in again.');
        redirect('login.php');
    }

    $_SESSION['last_activity'] = time();
}

require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/catalogue.php';
require_once __DIR__ . '/circulation.php';
require_once __DIR__ . '/fines.php';
require_once __DIR__ . '/../config/database.php';
