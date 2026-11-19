<?php
/**
 * Small reusable helpers used throughout the application.
 *
 * These functions intentionally stay close to plain PHP so that each page
 * remains easy to read and explain.
 */

/**
 * Escape a value for safe HTML output.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Build a URL relative to the application's public base path.
 */
function url(string $path = ''): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $path = ltrim($path, '/');
    $base = BASE_PATH;

    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }

    return ($base === '' ? '' : $base) . '/' . $path;
}

/**
 * Redirect to another application path and stop the script.
 */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/**
 * Add a one-time message to the session.
 */
function flash(string $type, string $message): void
{
    $allowedTypes = ['success', 'error', 'warning', 'info'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'info';
    }

    $_SESSION['flashes'][] = [
        'type'    => $type,
        'message' => $message,
    ];
}

/**
 * Redirect after a successful state-changing operation.
 */
function redirect_with_flash(string $path, string $type, string $message): void
{
    flash($type, $message);
    redirect($path);
}

/**
 * Retrieve and clear all flash messages.
 *
 * @return array<int, array{type: string, message: string}>
 */
function get_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);

    return is_array($flashes) ? $flashes : [];
}

/**
 * Format a date for display.
 */
function format_date(?string $date, string $format = 'd M Y'): string
{
    if ($date === null || $date === '') {
        return '—';
    }

    try {
        return (new DateTimeImmutable($date))->format($format);
    } catch (Throwable $exception) {
        return '—';
    }
}

/**
 * Format a date and time for display.
 */
function format_datetime(?string $datetime, string $format = 'd M Y, H:i'): string
{
    return format_date($datetime, $format);
}

/**
 * Format a monetary amount.
 */
function format_money(float|int|string|null $amount, string $currency = 'KES'): string
{
    return $currency . ' ' . number_format((float) $amount, 2);
}

/**
 * Human-readable role label.
 */
function role_label(?string $role): string
{
    return match ($role) {
        'admin'     => 'Administrator',
        'librarian' => 'Librarian',
        'student'   => 'Student',
        default     => $role === null || $role === '' ? 'Unknown' : ucfirst($role),
    };
}

/**
 * Human-readable status label.
 */
function status_label(string $status): string
{
    return ucfirst(str_replace('_', ' ', $status));
}

/**
 * CSS badge modifier for a status value.
 */
function badge_class(string $status): string
{
    return match ($status) {
        'active', 'available', 'approved', 'fulfilled', 'success' => 'badge--success',
        'pending', 'reserved', 'maintenance', 'warning'           => 'badge--warning',
        'issued', 'returned', 'info'                              => 'badge--info',
        'rejected', 'inactive', 'error'                           => 'badge--danger',
        'cancelled', 'archived', 'muted'                          => 'badge--muted',
        default                                                   => 'badge--muted',
    };
}

/**
 * True when the current request is a POST request.
 */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * Client IP address, with basic validation.
 */
function request_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ?: null;
}

/**
 * Browser user agent for the audit log.
 */
function request_user_agent(): ?string
{
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return $agent === '' ? null : mb_substr($agent, 0, 255);
}

/**
 * Normalize an email address before storage or lookup.
 */
function normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

/**
 * Normalize a student number before storage or lookup.
 */
function normalize_student_number(string $number): string
{
    return mb_strtoupper(preg_replace('/\s+/', '', trim($number)) ?? '');
}

/**
 * True when the current request comes from a local development host.
 */
function is_local_host_name(): bool
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    if ($host === '') {
        return false;
    }
    $hostname = strtolower(explode(':', $host)[0]);
    return in_array($hostname, ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with($hostname, '.localhost');
}

/**
 * Show the development credential banner only on local development hosts.
 *
 * This avoids exposing demonstration accounts if APP_ENV is accidentally set
 * to development on a public host.
 */
function show_demo_credentials(): bool
{
    if (APP_ENV !== 'development') {
        return false;
    }
    if (!is_local_host_name()) {
        error_log('Demo credentials banner suppressed because APP_ENV=development on non-local host.');
        return false;
    }
    return true;
}

/**
 * Return a validation error for a password, or null when acceptable.
 */
function password_error(string $password): ?string
{
    if (mb_strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }

    if (strlen($password) > 72) {
        return 'Password must not exceed 72 characters.';
    }

    // bcrypt throws ValueError for NUL bytes; reject control characters up front.
    if (preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
        return 'Password must not contain control characters.';
    }

    if (!preg_match('/[A-Za-z]/', $password)) {
        return 'Password must contain at least one letter.';
    }

    if (!preg_match('/\d/', $password)) {
        return 'Password must contain at least one number.';
    }

    return null;
}

/**
 * Read the single library settings row.
 *
 * @return array<string, mixed>
 */
function get_settings(PDO $pdo): array
{
    static $settings = null;

    if (is_array($settings)) {
        return $settings;
    }

    $statement = $pdo->query(
        'SELECT id, library_name, loan_period_days, max_active_borrowings,
                fine_per_day, currency_code, setup_completed_at
         FROM library_settings
         WHERE id = 1'
    );

    $settings = $statement->fetch() ?: [
        'library_name'          => 'Campus Library',
        'loan_period_days'      => 14,
        'max_active_borrowings' => 3,
        'fine_per_day'          => 10.00,
        'currency_code'         => 'KES',
        'setup_completed_at'    => null,
    ];

    return $settings;
}

/**
 * Strict, external-style Content Security Policy.
 */
function strict_content_security_policy(): string
{
    return "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self';"
        . " object-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:;"
        . " font-src 'self'; connect-src 'self'; media-src 'self'; manifest-src 'self'";
}

/**
 * Security headers returned by this application.
 *
 * HSTS is only sent when HTTPS is active and HSTS_MAX_AGE is positive.
 *
 * @return array<string, string>
 */
function security_headers(bool $isHttps = false): array
{
    $headers = [
        'Content-Security-Policy'       => strict_content_security_policy(),
        'X-Content-Type-Options'        => 'nosniff',
        'X-Frame-Options'               => 'SAMEORIGIN',
        'Referrer-Policy'               => 'strict-origin-when-cross-origin',
        'Permissions-Policy'            => 'geolocation=(), microphone=(), camera=()',
    ];

    if ($isHttps && HSTS_MAX_AGE > 0) {
        $headers['Strict-Transport-Security'] = sprintf('max-age=%d; includeSubDomains', HSTS_MAX_AGE);
    }

    return $headers;
}

/**
 * Central exception handler.
 *
 * Technical details are logged. Ordinary users receive a short, safe page.
 */
function handle_exception(Throwable $exception): void
{
    $logMessage = sprintf(
        "[%s] %s in %s:%d\n%s\n",
        date('c'),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );
    error_log($logMessage);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        foreach (security_headers() as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    $debugDetail = APP_DEBUG
        ? '<pre class="debug-detail">' . e($exception->getMessage()) . '</pre>'
        : '';

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Server error</title>'
        . '<link rel="stylesheet" href="' . e(url('assets/css/style.css')) . '">'
        . '</head><body><main class="error-card">'
        . '<h1>Something went wrong</h1>'
        . '<p>The request could not be completed. The technical details have been logged.</p>'
        . $debugDetail
        . '<p><a href="' . e(url('index.php')) . '">Return to the home page</a></p>'
        . '</main></body></html>';
    exit;
}

/**
 * Convert a status to a navigation-safe key.
 */
function nav_is_active(string $key, string $activeKey): bool
{
    return $key === $activeKey;
}

/**
 * Render a standalone error page.
 *
 * This deliberately avoids the database and session-dependent layout so that
 * it also works when the failure itself is a connection problem.
 */
function render_error_document(int $code, string $title, string $message): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    $homeUrl = e(url('index.php'));
    $loginUrl = e(url('login.php'));
    $stylesheet = e(url('assets/css/style.css'));

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' &middot; Campus Library</title>'
        . '<link rel="stylesheet" href="' . $stylesheet . '">'
        . '</head><body class="layout-guest">'
        . '<div class="guest-shell">'
        . '<header class="guest-header">'
        . '<a class="brand brand--guest" href="' . $homeUrl . '">'
        . '<span class="brand__text"><strong>Campus Library</strong>'
        . '<small>Campus Library System</small></span></a>'
        . '<nav class="guest-nav" aria-label="Account">'
        . '<a class="btn btn--ghost btn--sm" href="' . $loginUrl . '">Sign in</a>'
        . '</nav></header>'
        . '<main class="guest-main guest-main--narrow">'
        . '<section class="error-page" role="alert">'
        . '<p class="error-page__code">' . e((string) $code) . '</p>'
        . '<h1>' . e($title) . '</h1>'
        . '<p>' . e($message) . '</p>'
        . '<div class="error-page__actions">'
        . '<a class="btn btn--primary" href="' . $homeUrl . '">Return home</a>'
        . '<a class="btn btn--ghost" href="' . $loginUrl . '">Sign in</a>'
        . '</div></section></main></div></body></html>';
    exit;
}
