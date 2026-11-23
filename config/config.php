<?php
/**
 * Application configuration.
 *
 * This file is outside the public web root in the recommended development
 * layout. Never place database credentials in a file under public/.
 */

define('APP_ROOT', dirname(__DIR__));

/**
 * Production configuration is read from config/config.local.php when present.
 * That file lives outside the public document root, is git-ignored and may
 * contain database credentials and the setup token. Environment variables
 * still take precedence so the Docker development setup is unchanged.
 */
$localConfig = [];
$localConfigFile = __DIR__ . '/config.local.php';
if (is_file($localConfigFile)) {
    $loadedLocalConfig = require $localConfigFile;
    if (is_array($loadedLocalConfig)) {
        $localConfig = $loadedLocalConfig;
    }
    unset($loadedLocalConfig);
}

function campus_config(string $key, mixed $default = null, bool $emptyIsValue = false): mixed
{
    global $localConfig;
    $fromEnv = getenv($key);
    if ($fromEnv !== false && ($emptyIsValue || $fromEnv !== '')) {
        return $fromEnv;
    }
    if (array_key_exists($key, $localConfig)) {
        $value = $localConfig[$key];
        if ($emptyIsValue || $value !== null && $value !== '') {
            return $value;
        }
    }
    return $default;
}

define('APP_ENV', (string) campus_config('APP_ENV', 'production'));
define('APP_DEBUG', filter_var(campus_config('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN));
define('APP_TIMEZONE', 'Africa/Nairobi');
define('APP_CURRENCY', 'KES');

/**
 * Absolute application URL used for canonical links and fully-qualified
 * redirects when needed. Set APP_URL in production, e.g. https://library.example.com.
 */
define('APP_URL', rtrim((string) campus_config('APP_URL', ''), '/'));

/**
 * Whether to trust X-Forwarded-Proto from the hosting reverse proxy. Enable
 * only when the host is known to set it (InfinityFree terminates TLS upstream).
 */
define('APP_TRUST_PROXY_HEADERS', filter_var(campus_config('APP_TRUST_PROXY_HEADERS', 'false'), FILTER_VALIDATE_BOOLEAN));

/*
 * Canonical public path.
 *
 * Docker: APP_ROOT/public. Shared hosting: point APP_PUBLIC_PATH at the
 * web root. Error rendering and cover uploads use this path so the code does
 * not depend on an undocumented directory arrangement.
 */
$configuredPublicPath = campus_config('APP_PUBLIC_PATH', null);
define('APP_PUBLIC_PATH', rtrim(
    $configuredPublicPath !== null && $configuredPublicPath !== '' ? (string) $configuredPublicPath : APP_ROOT . '/public',
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
$configuredBasePath = campus_config('APP_BASE_PATH', false);
if ($configuredBasePath !== false && $configuredBasePath !== '') {
    $basePath = rtrim((string) $configuredBasePath, '/');
} elseif (APP_URL !== '') {
    $appUrlPath = parse_url(APP_URL, PHP_URL_PATH);
    $basePath = ($appUrlPath !== false && $appUrlPath !== null && $appUrlPath !== '/') ? rtrim($appUrlPath, '/') : '';
} else {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $publicPosition = strpos($scriptName, '/public/');
    $basePath = $publicPosition !== false
        ? substr($scriptName, 0, $publicPosition + 7)
        : '';
}
define('BASE_PATH', $basePath);

/*
 * Database settings come from environment variables (Docker development) or
 * config/config.local.php (production). No real credentials are stored here.
 * Empty defaults fail safely: database.php throws a generic error if unset.
 */
define('DB_HOST', (string) campus_config('DB_HOST', ''));
define('DB_PORT', (string) campus_config('DB_PORT', '3306'));
define('DB_NAME', (string) campus_config('DB_NAME', ''));
define('DB_USER', (string) campus_config('DB_USER', ''));
define('DB_PASS', (string) campus_config('DB_PASS', '', true));
define('DB_CHARSET', (string) campus_config('DB_CHARSET', 'utf8mb4'));

/*
 * One-time administrator setup token.
 * The setup page is disabled when this value is empty. In development it is
 * supplied by .dev/.env. On shared hosting it must be set to a long random
 * value inside this protected configuration file.
 */
define('SETUP_TOKEN', (string) campus_config('SETUP_TOKEN', '', true));

define('SESSION_NAME', 'CAMPUSLIB_SESSION');
define('SESSION_TIMEOUT', 3600);

/*
 * Email verification is opt-in. It stays off until real email delivery works,
 * so enabling it cannot lock existing students out of the system.
 */
define('EMAIL_VERIFICATION_ENABLED', filter_var(campus_config('EMAIL_VERIFICATION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN));
define('EMAIL_FROM_ADDRESS', (string) campus_config('EMAIL_FROM_ADDRESS', ''));
define('EMAIL_VERIFICATION_TTL', 86400);
define('PASSWORD_RESET_ENABLED', filter_var(campus_config('PASSWORD_RESET_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN));
define('PASSWORD_RESET_TTL', 1800);
define('MFA_ENABLED', filter_var(campus_config('MFA_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN));

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
$configuredHstsMaxAge = campus_config('HSTS_MAX_AGE', 0);
define('HSTS_MAX_AGE', $configuredHstsMaxAge !== '' && $configuredHstsMaxAge !== null ? max(0, (int) $configuredHstsMaxAge) : 0);

/*
 * Override session cookie `Secure`. Leave null to auto-detect HTTPS.
 */
$configuredSecureCookie = campus_config('SESSION_COOKIE_SECURE', null, true);
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
