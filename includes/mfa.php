<?php
/**
 * Dependency-free TOTP MFA (RFC 6238) plus one-time recovery codes.
 * Global opt-in: MFA_ENABLED. Per-user opt-in: enabled flag in user_mfa.
 */

function mfa_base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function mfa_base32_decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper($secret)) as $char) {
        $index = strpos($alphabet, $char);
        if ($index === false) {
            continue;
        }
        $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) < 8) {
            break;
        }
        $out .= chr(bindec($chunk));
    }
    return $out;
}

function mfa_generate_secret(): string
{
    return mfa_base32_encode(random_bytes(20));
}

function mfa_totp(string $secret, int $time = 0): string
{
    $time = $time > 0 ? $time : time();
    $counter = intdiv($time, 30);
    $message = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
    $hash = hash_hmac('sha1', $message, mfa_base32_decode($secret), true);
    $offset = ord($hash[19]) & 0x0F;
    $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function mfa_verify_code(string $secret, string $code): bool
{
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    for ($delta = -1; $delta <= 1; $delta++) {
        if (hash_equals(mfa_totp($secret, time() + ($delta * 30)), $code)) {
            return true;
        }
    }
    return false;
}

function mfa_row(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM user_mfa WHERE user_id = :id');
    $statement->execute(['id' => $userId]);
    $row = $statement->fetch();
    return $row === false ? null : (array) $row;
}

function mfa_is_enabled(PDO $pdo, int $userId): bool
{
    if (!MFA_ENABLED) {
        return false;
    }
    $row = mfa_row($pdo, $userId);
    return $row !== null && (int) $row['enabled'] === 1;
}

/** Begin setup: store a pending (disabled) secret. Returns the secret for the app. */
function mfa_setup_start(PDO $pdo, int $userId): string
{
    $secret = mfa_generate_secret();
    $statement = $pdo->prepare(
        'INSERT INTO user_mfa (user_id, secret, enabled, recovery_codes)
         VALUES (:id, :secret, 0, NULL)
         ON DUPLICATE KEY UPDATE secret = :secret2, enabled = 0, recovery_codes = NULL, enabled_at = NULL'
    );
    $statement->execute(['id' => $userId, 'secret' => $secret, 'secret2' => $secret]);
    return $secret;
}

function mfa_generate_recovery_codes(PDO $pdo, int $userId): array
{
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
    }
    $hashes = array_map(fn($c) => hash('sha256', $c), $codes);
    $pdo->prepare('UPDATE user_mfa SET recovery_codes = :codes WHERE user_id = :id')
        ->execute(['codes' => json_encode($hashes), 'id' => $userId]);
    return $codes;
}

function mfa_enable(PDO $pdo, int $userId, string $code): bool
{
    $row = mfa_row($pdo, $userId);
    if ($row === null || !mfa_verify_code((string) $row['secret'], $code)) {
        return false;
    }
    $pdo->prepare('UPDATE user_mfa SET enabled = 1, enabled_at = NOW() WHERE user_id = :id')
        ->execute(['id' => $userId]);
    return true;
}

function mfa_disable(PDO $pdo, int $userId): void
{
    $pdo->prepare('DELETE FROM user_mfa WHERE user_id = :id')->execute(['id' => $userId]);
}

function mfa_verify_recovery(PDO $pdo, int $userId, string $code): bool
{
    $row = mfa_row($pdo, $userId);
    if ($row === null || (string) $row['recovery_codes'] === '') {
        return false;
    }
    $hashes = json_decode((string) $row['recovery_codes'], true);
    if (!is_array($hashes)) {
        return false;
    }
    $needle = hash('sha256', strtoupper(trim($code)));
    $found = false;
    $remaining = [];
    foreach ($hashes as $hash) {
        if (!$found && hash_equals((string) $hash, $needle)) {
            $found = true;
        } else {
            $remaining[] = $hash;
        }
    }
    if ($found) {
        $pdo->prepare('UPDATE user_mfa SET recovery_codes = :codes WHERE user_id = :id')
            ->execute(['codes' => json_encode(array_values($remaining)), 'id' => $userId]);
    }
    return $found;
}
