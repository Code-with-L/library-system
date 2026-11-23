<?php
/**
 * Password reset by email. Opt-in via PASSWORD_RESET_ENABLED.
 * Tokens are random; only their SHA-256 hash is stored, and they are single-use.
 */

function password_reset_issue(PDO $pdo, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + PASSWORD_RESET_TTL);
    $statement = $pdo->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at)
         VALUES (:id, :hash, :expires)
         ON DUPLICATE KEY UPDATE token_hash = :hash2, expires_at = :expires2'
    );
    $statement->execute([
        'id' => $userId, 'hash' => $hash, 'expires' => $expires,
        'hash2' => $hash, 'expires2' => $expires,
    ]);
    return $token;
}

function password_reset_deliver(PDO $pdo, int $userId, string $email, string $token): bool
{
    $link = url('reset-password.php?token=' . urlencode($token));

    if (APP_ENV === 'development' && is_local_host_name()) {
        $_SESSION['dev_reset_link'] = $link;
        return true;
    }
    if (EMAIL_FROM_ADDRESS === '' || !filter_var(EMAIL_FROM_ADDRESS, FILTER_VALIDATE_EMAIL)) {
        error_log('Password reset enabled but EMAIL_FROM_ADDRESS is not configured.');
        return false;
    }

    $subject = 'Reset your ' . (string) get_settings($pdo)['library_name'] . ' password';
    $message = "Hello,\r\n\r\n"
        . "Use this link to choose a new password (valid 30 minutes):\r\n" . $link . "\r\n\r\n"
        . "If you did not request this, ignore this message and your password will stay unchanged.\r\n";
    $headers = 'From: ' . EMAIL_FROM_ADDRESS . "\r\n"
        . 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $sent = @mail(normalize_email($email), $subject, $message, $headers);
    if (!$sent) {
        error_log('Failed to send password reset email to user ' . $userId . '.');
    }
    return (bool) $sent;
}

/** Always behaves the same externally; never reveals whether an email exists. */
function password_reset_request(PDO $pdo, string $email): void
{
    $email = normalize_email($email);
    if ($email === '') {
        return;
    }
    $user = find_user_by_email($pdo, $email);
    if ($user === null) {
        return;
    }
    $token = password_reset_issue($pdo, (int) $user['id']);
    password_reset_deliver($pdo, (int) $user['id'], $email, $token);
    log_activity($pdo, (int) $user['id'], 'password_reset_requested', 'user', (int) $user['id'],
        'Password reset link requested.');
}

function password_reset_row(PDO $pdo, string $token): ?array
{
    $statement = $pdo->prepare('SELECT * FROM password_resets WHERE token_hash = :hash');
    $statement->execute(['hash' => hash('sha256', $token)]);
    $row = $statement->fetch();
    return $row === false ? null : (array) $row;
}

/** @return array{ok: bool, message: string} */
function password_reset_complete(PDO $pdo, string $token, string $newPassword, string $confirm = ''): array
{
    if ($confirm !== '' && $newPassword !== $confirm) {
        return ['ok' => false, 'message' => 'The password confirmation does not match.'];
    }
    $row = password_reset_row($pdo, $token);
    if ($row === null) {
        return ['ok' => false, 'message' => 'That reset link is not valid or has already been used.'];
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = :id')->execute(['id' => $row['user_id']]);
        return ['ok' => false, 'message' => 'That reset link has expired. Please request a new one.'];
    }
    $error = password_error($newPassword);
    if ($error !== null) {
        return ['ok' => false, 'message' => $error];
    }

    $update = $pdo->prepare(
        'UPDATE users
            SET password_hash = :hash, session_version = session_version + 1
          WHERE id = :id'
    );
    $update->execute([
        'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id'   => $row['user_id'],
    ]);
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = :id')->execute(['id' => $row['user_id']]);
    log_activity($pdo, (int) $row['user_id'], 'password_reset_completed', 'user', (int) $row['user_id'],
        'Password reset through emailed link.');
    return ['ok' => true, 'message' => 'Your password has been reset. Please sign in.'];
}
