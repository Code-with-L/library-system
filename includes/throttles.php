<?php
/**
 * Small durable request throttles built on the existing login_throttles table.
 * Keys are one-way hashes of a namespace + request IP; no raw identifiers stored.
 * No new table, dependency or build step is required.
 */

/** Dev/localhost is exempt so local test suites can register fixtures. */
function throttle_is_exempt(): bool
{
    return APP_ENV === 'development' && is_local_host_name();
}

/**
 * @return array{count: int, first: int, blocked: bool}
 */
function signup_throttle_state(PDO $pdo, int $window = 3600, int $limit = 10): array
{
    if (throttle_is_exempt()) {
        return ['count' => 0, 'first' => time(), 'blocked' => false];
    }

    $key = hash('sha256', 'register|' . (request_ip() ?? ''));
    $statement = $pdo->prepare(
        'SELECT attempts, first_attempt_at FROM login_throttles WHERE throttle_key = :key'
    );
    $statement->execute(['key' => $key]);
    $row = $statement->fetch();

    if ($row === false) {
        return ['count' => 0, 'first' => time(), 'blocked' => false];
    }

    $first = strtotime((string) $row['first_attempt_at']) ?: time();
    if ((time() - $first) >= $window) {
        $clear = $pdo->prepare('DELETE FROM login_throttles WHERE throttle_key = :key');
        $clear->execute(['key' => $key]);
        return ['count' => 0, 'first' => time(), 'blocked' => false];
    }

    $count = (int) $row['attempts'];
    return ['count' => $count, 'first' => $first, 'blocked' => $count >= $limit];
}

function signup_throttle_record(PDO $pdo): void
{
    if (throttle_is_exempt()) {
        return;
    }

    $key = hash('sha256', 'register|' . (request_ip() ?? ''));
    $now = date('Y-m-d H:i:s');
    $statement = $pdo->prepare(
        'INSERT INTO login_throttles (throttle_key, attempts, first_attempt_at, last_attempt_at)
         VALUES (:key, 1, :now1, :now2)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt_at = :now3'
    );
    $statement->execute(['key' => $key, 'now1' => $now, 'now2' => $now, 'now3' => $now]);
}

/** Password-reset requests: 3 per hour per email+IP. */
function reset_throttle_state(PDO $pdo, string $email, int $window = 3600, int $limit = 3): array
{
    if (throttle_is_exempt()) {
        return ['count' => 0, 'first' => time(), 'blocked' => false];
    }
    $key = hash('sha256', 'reset|' . normalize_email($email) . '|' . (request_ip() ?? ''));
    $statement = $pdo->prepare('SELECT attempts, first_attempt_at FROM login_throttles WHERE throttle_key = :key');
    $statement->execute(['key' => $key]);
    $row = $statement->fetch();
    if ($row === false) {
        return ['count' => 0, 'first' => time(), 'blocked' => false];
    }
    $first = strtotime((string) $row['first_attempt_at']) ?: time();
    if ((time() - $first) >= $window) {
        $pdo->prepare('DELETE FROM login_throttles WHERE throttle_key = :key')->execute(['key' => $key]);
        return ['count' => 0, 'first' => time(), 'blocked' => false];
    }
    $count = (int) $row['attempts'];
    return ['count' => $count, 'first' => $first, 'blocked' => $count >= $limit];
}

function reset_throttle_record(PDO $pdo, string $email): void
{
    if (throttle_is_exempt()) {
        return;
    }
    $key = hash('sha256', 'reset|' . normalize_email($email) . '|' . (request_ip() ?? ''));
    $now = date('Y-m-d H:i:s');
    $statement = $pdo->prepare(
        'INSERT INTO login_throttles (throttle_key, attempts, first_attempt_at, last_attempt_at)
         VALUES (:key, 1, :now1, :now2)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt_at = :now3'
    );
    $statement->execute(['key' => $key, 'now1' => $now, 'now2' => $now, 'now3' => $now]);
}

/** First-admin setup token attempts: 5 per hour per IP. */
function setup_throttle_state(PDO $pdo, int $window = 3600, int $limit = 5): array
{
    if (throttle_is_exempt()) {
        return ['count' => 0, 'first' => time(), 'blocked' => false];
    }
    $key = hash('sha256', 'setup|' . (request_ip() ?? ''));
    $statement = $pdo->prepare('SELECT attempts, first_attempt_at FROM login_throttles WHERE throttle_key = :key');
    $statement->execute(['key' => $key]);
    $row = $statement->fetch();
    if ($row === false) {
        return ['count' => 0, 'first' => time(), 'blocked' => false];
    }
    $first = strtotime((string) $row['first_attempt_at']) ?: time();
    if ((time() - $first) >= $window) {
        $pdo->prepare('DELETE FROM login_throttles WHERE throttle_key = :key')->execute(['key' => $key]);
        return ['count' => 0, 'first' => time(), 'blocked' => false];
    }
    $count = (int) $row['attempts'];
    return ['count' => $count, 'first' => $first, 'blocked' => $count >= $limit];
}

function setup_throttle_record(PDO $pdo): void
{
    if (throttle_is_exempt()) {
        return;
    }
    $key = hash('sha256', 'setup|' . (request_ip() ?? ''));
    $now = date('Y-m-d H:i:s');
    $statement = $pdo->prepare(
        'INSERT INTO login_throttles (throttle_key, attempts, first_attempt_at, last_attempt_at)
         VALUES (:key, 1, :now1, :now2)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt_at = :now3'
    );
    $statement->execute(['key' => $key, 'now1' => $now, 'now2' => $now, 'now3' => $now]);
}

/** Verification-code resend: 3 per hour per user+IP. */
function verify_resend_throttle_state(PDO $pdo, int $userId, int $window = 3600, int $limit = 3): array
{
    if (throttle_is_exempt()) {
        return ['count' => 0, 'first' => time(), 'blocked' => false];
    }
    $key = hash('sha256', 'verify|' . $userId . '|' . (request_ip() ?? ''));
    $statement = $pdo->prepare('SELECT attempts, first_attempt_at FROM login_throttles WHERE throttle_key = :key');
    $statement->execute(['key' => $key]);
    $row = $statement->fetch();
    if ($row === false) {
        return ['count' => 0, 'first' => time(), 'blocked' => false];
    }
    $first = strtotime((string) $row['first_attempt_at']) ?: time();
    if ((time() - $first) >= $window) {
        $pdo->prepare('DELETE FROM login_throttles WHERE throttle_key = :key')->execute(['key' => $key]);
        return ['count' => 0, 'first' => time(), 'blocked' => false];
    }
    $count = (int) $row['attempts'];
    return ['count' => $count, 'first' => $first, 'blocked' => $count >= $limit];
}

function verify_resend_throttle_record(PDO $pdo, int $userId): void
{
    if (throttle_is_exempt()) {
        return;
    }
    $key = hash('sha256', 'verify|' . $userId . '|' . (request_ip() ?? ''));
    $now = date('Y-m-d H:i:s');
    $statement = $pdo->prepare(
        'INSERT INTO login_throttles (throttle_key, attempts, first_attempt_at, last_attempt_at)
         VALUES (:key, 1, :now1, :now2)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt_at = :now3'
    );
    $statement->execute(['key' => $key, 'now1' => $now, 'now2' => $now, 'now3' => $now]);
}
