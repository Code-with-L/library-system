<?php
/**
 * Authentication functions.
 *
 * Authentication answers "who is this user?". Authorization is handled
 * separately in authorization.php.
 */

const AUTH_DUMMY_HASH = '$2y$10$DNA1avunvuE9wq7DDk6kvOZkG6NFF2R/LylQiBjdw0P7RYaOElNtC';

/**
 * Find a user by email, including the role name.
 *
 * @return array<string, mixed>|null
 */
function find_user_by_email(PDO $pdo, string $email): ?array
{
    $statement = $pdo->prepare(
        'SELECT u.id, u.role_id, u.full_name, u.email, u.password_hash,
                u.session_version, u.student_number, u.phone, u.department, u.status,
                u.last_login_at, u.created_at, r.name AS role_name
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.email = :email
         LIMIT 1'
    );
    $statement->execute(['email' => normalize_email($email)]);

    $user = $statement->fetch();
    return $user ?: null;
}

/**
 * Attempt to authenticate a user.
 *
 * @return array<string, mixed>|null
 */
function login_user(PDO $pdo, string $email, string $password): ?array
{
    // bcrypt/password_verify may treat a trailing NUL as part of the string or
    // ignore it; reject control characters before verification.
    if (preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
        log_failed_login($pdo, $email);
        return null;
    }

    $user = find_user_by_email($pdo, $email);

    if ($user === null) {
        // Perform a hash comparison anyway to reduce timing differences.
        try {
            password_verify($password, AUTH_DUMMY_HASH);
        } catch (ValueError $exception) {
            // A NUL byte cannot be a valid password; reject generically.
        }
        log_failed_login($pdo, $email);
        return null;
    }

    try {
        $verified = password_verify($password, (string) $user['password_hash']);
    } catch (ValueError $exception) {
        log_failed_login($pdo, $email);
        return null;
    }

    if (!$verified) {
        log_failed_login($pdo, $email);
        return null;
    }

    if ($user['status'] !== 'active') {
        log_failed_login($pdo, $email);
        return null;
    }

    // Upgrade the hash if PHP's default algorithm changes in future.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $update->execute(['hash' => $newHash, 'id' => $user['id']]);
    }

    // MFA users pause here: no authenticated session until the second factor passes.
    if (mfa_is_enabled($pdo, (int) $user['id'])) {
        $_SESSION['mfa_pending_user_id'] = (int) $user['id'];
        return ['mfa_required' => true];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_activity'] = time();
    $_SESSION['session_version'] = (int) $user['session_version'];
    rotate_csrf_token();

    $now = date('Y-m-d H:i:s');
    $updateLogin = $pdo->prepare('UPDATE users SET last_login_at = :now WHERE id = :id');
    $updateLogin->execute(['now' => $now, 'id' => $user['id']]);

    log_activity(
        $pdo,
        (int) $user['id'],
        'login',
        'user',
        (int) $user['id'],
        'User signed in.'
    );

    $user['last_login_at'] = $now;
    return $user;
}

/**
 * Durable login throttle backed by the application database (Phase 6, C-10).
 *
 * Failed attempts survive session loss because the counter is stored in the
 * `login_throttles` table. The key is a one-way hash of the normalized email
 * and request IP, so no credentials or raw identifiers are stored.
 *
 * @return array{count: int, first: int, blocked: bool}
 */
function login_throttle_state(PDO $pdo, string $email): array
{
    $key = hash('sha256', normalize_email($email) . '|' . (request_ip() ?? ''));
    $statement = $pdo->prepare(
        'SELECT attempts, first_attempt_at FROM login_throttles WHERE throttle_key = :key'
    );
    $statement->execute(['key' => $key]);
    $row = $statement->fetch();

    if ($row === false) {
        return ['count' => 0, 'first' => time(), 'blocked' => false];
    }

    $first = strtotime((string) $row['first_attempt_at']) ?: time();
    if ((time() - $first) >= 900) {
        login_throttle_clear($pdo, $email);
        return ['count' => 0, 'first' => time(), 'blocked' => false];
    }

    $count = (int) $row['attempts'];
    return ['count' => $count, 'first' => $first, 'blocked' => $count >= 5];
}

function login_throttle_record_failure(PDO $pdo, string $email): void
{
    $key = hash('sha256', normalize_email($email) . '|' . (request_ip() ?? ''));
    $now = date('Y-m-d H:i:s');
    $statement = $pdo->prepare(
        'INSERT INTO login_throttles (throttle_key, attempts, first_attempt_at, last_attempt_at)
         VALUES (:key, 1, :now1, :now2)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt_at = :now3'
    );
    $statement->execute(['key' => $key, 'now1' => $now, 'now2' => $now, 'now3' => $now]);
}

function login_throttle_clear(PDO $pdo, string $email): void
{
    $key = hash('sha256', normalize_email($email) . '|' . (request_ip() ?? ''));
    $statement = $pdo->prepare('DELETE FROM login_throttles WHERE throttle_key = :key');
    $statement->execute(['key' => $key]);
}

/**
 * Return the authenticated user from the current session, or null.
 *
 * The user is re-read from the database on each request so a deactivated
 * account loses access immediately.
 *
 * @return array<string, mixed>|null
 */
function current_user(PDO $pdo): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !ctype_digit((string) $userId)) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT u.id, u.role_id, u.full_name, u.email, u.password_hash,
                u.session_version, u.student_number, u.phone, u.department, u.status,
                u.last_login_at, u.created_at, r.name AS role_name
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => (int) $userId]);
    $user = $statement->fetch();

    if (!$user) {
        unset($_SESSION['user_id'], $_SESSION['last_activity']);
        return null;
    }

    if ($user['status'] !== 'active') {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }

    // Revoke sessions if the account's session version changed after login.
    $sessionVersion = $_SESSION['session_version'] ?? null;
    if ($sessionVersion !== null && (int) $sessionVersion !== (int) $user['session_version']) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }

    return $user;
}

/**
 * Destroy the authenticated session.
 */
function logout_user(PDO $pdo): void
{
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

    if ($userId !== null) {
        log_activity($pdo, $userId, 'logout', 'user', $userId, 'User signed out.');
    }

    $_SESSION = [];

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // Clear all data and rotate the identifier. The old session record is
    // deleted, while the empty new session can carry a confirmation flash.
    session_regenerate_id(true);
}

/**
 * Dashboard path for a role.
 */
function dashboard_path_for_role(string $role): string
{
    return match ($role) {
        'admin'     => 'admin/dashboard.php',
        'librarian' => 'staff/dashboard.php',
        default     => 'student/dashboard.php',
    };
}

/**
 * Redirect an already-authenticated visitor away from guest pages.
 */
function redirect_authenticated_user(PDO $pdo): void
{
    $user = current_user($pdo);
    if ($user !== null) {
        redirect(dashboard_path_for_role($user['role_name']));
    }
}
