<?php
/**
 * Student email verification. Opt-in via EMAIL_VERIFICATION_ENABLED.
 * Tokens are random; only their SHA-256 hash is stored in the database.
 */

function email_verification_required(): bool
{
    return EMAIL_VERIFICATION_ENABLED;
}

function email_verification_row(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM email_verifications WHERE user_id = :id');
    $statement->execute(['id' => $userId]);
    $row = $statement->fetch();
    return $row === false ? null : (array) $row;
}

function email_verification_is_verified(PDO $pdo, int $userId): bool
{
    if (!email_verification_required()) {
        return true;
    }
    $row = email_verification_row($pdo, $userId);
    return $row !== null && $row['verified_at'] !== null;
}

/** Create (or replace) a verification token. Returns the raw token for the link. */
function email_verification_issue_token(PDO $pdo, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + EMAIL_VERIFICATION_TTL);
    $statement = $pdo->prepare(
        'INSERT INTO email_verifications (user_id, token_hash, expires_at, verified_at)
         VALUES (:id, :hash, :expires, NULL)
         ON DUPLICATE KEY UPDATE token_hash = :hash2, expires_at = :expires2, verified_at = NULL'
    );
    $statement->execute([
        'id' => $userId, 'hash' => $hash, 'expires' => $expires,
        'hash2' => $hash, 'expires2' => $expires,
    ]);
    return $token;
}

/**
 * Deliver the verification link. Delivery is deliberately optional:
 * - local development shows the link on screen so the flow can be tested;
 * - production uses PHP mail() only when a From address is configured.
 */
function email_verification_deliver(PDO $pdo, int $userId, string $email, string $token): bool
{
    $link = url('verify-email.php?token=' . urlencode($token));

    if (APP_ENV === 'development' && is_local_host_name()) {
        $_SESSION['dev_verification_link'] = $link;
        return true;
    }

    if (EMAIL_FROM_ADDRESS === '' || !filter_var(EMAIL_FROM_ADDRESS, FILTER_VALIDATE_EMAIL)) {
        error_log('Email verification enabled but EMAIL_FROM_ADDRESS is not configured.');
        return false;
    }

    $subject = 'Verify your ' . (string) get_settings($pdo)['library_name'] . ' account';
    $message = "Hello,\r\n\r\n"
        . "Please verify your email address by opening this link:\r\n" . $link . "\r\n\r\n"
        . "This link expires in 24 hours. If you did not create this account, ignore this message.\r\n";
    $headers = 'From: ' . EMAIL_FROM_ADDRESS . "\r\n"
        . 'Content-Type: text/plain; charset=UTF-8' . "\r\n";

    $sent = @mail(normalize_email($email), $subject, $message, $headers);
    if (!$sent) {
        error_log('Failed to send verification email to user ' . $userId . '.');
    }
    return (bool) $sent;
}

/** Issue and deliver a fresh token. */
function email_verification_send(PDO $pdo, int $userId, string $email): bool
{
    $token = email_verification_issue_token($pdo, $userId);
    $sent = email_verification_deliver($pdo, $userId, $email, $token);
    log_activity($pdo, $userId, 'email_verification_sent', 'user', $userId,
        'Verification email issued.');
    return $sent;
}

/** @return array{ok: bool, message: string} */
function email_verification_verify_token(PDO $pdo, string $token): array
{
    $hash = hash('sha256', $token);
    $statement = $pdo->prepare('SELECT * FROM email_verifications WHERE token_hash = :hash');
    $statement->execute(['hash' => $hash]);
    $row = $statement->fetch();
    if ($row === false) {
        return ['ok' => false, 'message' => 'That verification link is not valid.'];
    }
    if ($row['verified_at'] !== null) {
        return ['ok' => true, 'message' => 'This email address is already verified.'];
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return ['ok' => false, 'message' => 'That verification link has expired. Please request a new one.'];
    }

    $update = $pdo->prepare('UPDATE email_verifications SET verified_at = NOW() WHERE user_id = :id');
    $update->execute(['id' => (int) $row['user_id']]);
    log_activity($pdo, (int) $row['user_id'], 'email_verified', 'user', (int) $row['user_id'],
        'Email address verified.');
    return ['ok' => true, 'message' => 'Your email address has been verified.'];
}
