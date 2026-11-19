<?php
/**
 * Append a failed-sign-in audit event without storing the password.
 *
 * Used for login failures, throttle bypasses and generic invalid credentials.
 * The normalized email address and source IP are the only identifying data.
 */
function log_failed_login(PDO $pdo, string $email): void
{
    try {
        $statement = $pdo->prepare(
            'INSERT INTO activity_logs
                (user_id, action, entity_type, entity_id, description, ip_address, user_agent)
             VALUES
                (NULL, \'login_failed\', \'auth\', NULL, :description, :ip_address, :user_agent)'
        );
        $statement->execute([
            'description' => mb_substr(
                'Failed sign-in attempt for ' . normalize_email($email) . '.',
                0,
                500
            ),
            'ip_address'  => request_ip(),
            'user_agent'  => request_user_agent(),
        ]);
    } catch (Throwable $exception) {
        error_log('Failed login audit log failed: ' . $exception->getMessage());
    }
}

/**
 * Append-only activity logging.
 *
 * Logging failure must not break a successful user operation, so errors are
 * written to the protected application log instead of being displayed.
 */
function log_activity(
    PDO $pdo,
    ?int $userId,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    string $description = ''
): void {
    try {
        $statement = $pdo->prepare(
            'INSERT INTO activity_logs
                (user_id, action, entity_type, entity_id, description, ip_address, user_agent)
             VALUES
                (:user_id, :action, :entity_type, :entity_id, :description, :ip_address, :user_agent)'
        );

        $statement->execute([
            'user_id'     => $userId,
            'action'      => mb_substr($action, 0, 50),
            'entity_type' => $entityType === null ? null : mb_substr($entityType, 0, 40),
            'entity_id'   => $entityId,
            'description' => mb_substr($description, 0, 500),
            'ip_address'  => request_ip(),
            'user_agent'  => request_user_agent(),
        ]);
    } catch (Throwable $exception) {
        error_log('Activity log failed: ' . $exception->getMessage());
    }
}
