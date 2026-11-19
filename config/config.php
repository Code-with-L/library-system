<?php
/**
 * Application configuration.
 *
 * This file is outside the public web root in the recommended development
 * layout. Never place database credentials in a file under public/.
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN));
define('APP_TIMEZONE', 'Africa/Nairobi');

/*
 * Canonical public path.
 *
 * Docker: APP_ROOT/public. Shared hosting: point APP_PUBLIC_PATH at the
 * web root. Error rendering and cover uploads use this path so the code does
 * not depend on an undocumented directory arrangement.
 */
$configuredPublicPath = getenv('APP_PUBLIC_PATH');
define('APP_PUBLIC_PATH', rtrim(
    $configuredPublicPath !== false && $configuredPublicPath !== '' ? $configuredPublicPath : APP_ROOT . '/public',
    '/'
));

date_default_timezone_set(APP_TIMEZONE);
mb_internal_encoding('UTF-8');

/*
 * Base path used by the url() helper.
 *
 * Docker development: BASE_PATH is empty because public/ is the Apache
 * document root. XAMPP with the project under htdocs/campus-library/public
 * is auto-detected. A deployment can override this with APP_BASE_PATH.
 */
$configuredBasePath = getenv('APP_BASE_PATH');
if ($configuredBasePath !== false) {
    $basePath = rtrim($configuredBasePath, '/');
} else {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $publicPosition = strpos($scriptName, '/public/');
    $basePath = $publicPosition !== false
        ? substr($scriptName, 0, $publicPosition + 7)
        : '';
}
define('BASE_PATH', $basePath);

/*
 * Database settings come from environment variables in development.
 * On conventional shared hosting, replace the fallback values below with
 * the credentials supplied by the hosting control panel.
 */
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'campus_library');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

/*
 * One-time administrator setup token.
 * The setup page is disabled when this value is empty. In development it is
 * supplied by .dev/.env. On shared hosting it must be set to a long random
 * value inside this protected configuration file.
 */
define('SETUP_TOKEN', getenv('SETUP_TOKEN') !== false ? getenv('SETUP_TOKEN') : '');

define('SESSION_NAME', 'CAMPUSLIB_SESSION');
define('SESSION_TIMEOUT', 3600);

define('COVER_UPLOAD_DIR', APP_PUBLIC_PATH . '/uploads/covers');
define('COVER_MAX_BYTES', 2 * 1024 * 1024);
define('COVER_ALLOWED_MIME_TYPES', [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
]);
define('COVER_ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp']);

/*
 * HSTS is sent only when HTTPS is active and an explicit maximum age is set.
 * Set HSTS_MAX_AGE (seconds) in production after HTTPS is verified.
 */
$configuredHstsMaxAge = getenv('HSTS_MAX_AGE');
define('HSTS_MAX_AGE', $configuredHstsMaxAge !== false && $configuredHstsMaxAge !== '' ? max(0, (int) $configuredHstsMaxAge) : 0);

/*
 * Override session cookie `Secure`. Leave null to auto-detect HTTPS.
 */
$configuredSecureCookie = getenv('SESSION_COOKIE_SECURE');
define('SESSION_COOKIE_SECURE_OVERRIDE', $configuredSecureCookie === false ? null : $configuredSecureCookie);

$logDirectory = APP_ROOT . '/storage/logs';
if (!is_dir($logDirectory)) {
    @mkdir($logDirectory, 0775, true);
}
define('LOG_DIRECTORY', $logDirectory);
define('APP_LOG_FILE', LOG_DIRECTORY . '/app.log');

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_LOG_FILE);
ini_set('expose_php', '0');
