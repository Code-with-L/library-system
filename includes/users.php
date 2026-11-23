<?php
/**
 * Shared administrator user-management helpers.
 *
 * The locking helpers must be called inside an open transaction. Lock order is
 * always: administrator role row, then the target user row. Keeping one lock
 * order avoids deadlocks between concurrent administrative requests.
 */

/**
 * Role identifiers keyed by role name.
 *
 * @return array<string, string>
 */
function role_options(): array
{
    return [
        'admin'     => 'Administrator',
        'librarian' => 'Librarian',
        'student'   => 'Student',
    ];
}

/**
 * Fetch one user with role information.
 *
 * @return array<string, mixed>|null
 */
function find_user_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT u.id, u.role_id, u.full_name, u.email, u.student_number,
                u.phone, u.department, u.status, u.last_login_at, u.created_at,
                r.name AS role_name
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $user = $statement->fetch();

    return $user ?: null;
}

/**
 * Lock the administrator role row for the remainder of the transaction.
 *
 * Serializing every operation that can create, demote or deactivate an
 * administrator through this row prevents the last-admin race condition.
 */
function lock_admin_role(PDO $pdo): void
{
    $statement = $pdo->prepare('SELECT id FROM roles WHERE name = :name FOR UPDATE');
    $statement->execute(['name' => 'admin']);

    if ($statement->fetchColumn() === false) {
        throw new RuntimeException('The administrator role is missing from the database.');
    }
}

/**
 * Lock and return one user row.
 *
 * @return array<string, mixed>|null
 */
function lock_user_for_update(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT u.id, u.role_id, u.full_name, u.email, u.student_number,
                u.phone, u.department, u.status, u.last_login_at, u.created_at,
                r.name AS role_name
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.id = :id
         FOR UPDATE'
    );
    $statement->execute(['id' => $id]);
    $user = $statement->fetch();

    return $user ?: null;
}

/**
 * Count active administrators using a locking read.
 *
 * A locking read sees the latest committed rows, so a transaction that waited
 * on the role lock still observes an administrator created by the winner.
 */
function count_active_admins_for_update(PDO $pdo): int
{
    $statement = $pdo->query(
        "SELECT u.id
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE r.name = 'admin' AND u.status = 'active'
         FOR UPDATE"
    );

    return count($statement->fetchAll());
}

/**
 * True when applying the proposed role/status would remove the last active
 * administrator. Must be called inside a transaction after the role lock.
 *
 * @param array<string, mixed> $target
 */
function removes_last_active_admin(
    PDO $pdo,
    array $target,
    string $newRole,
    string $newStatus
): bool {
    $targetIsActiveAdmin = $target['role_name'] === 'admin' && $target['status'] === 'active';
    $staysActiveAdmin = $newRole === 'admin' && $newStatus === 'active';

    if (!$targetIsActiveAdmin || $staysActiveAdmin) {
        return false;
    }

    return count_active_admins_for_update($pdo) <= 1;
}

/**
 * Validate and normalize administrator user-management input.
 *
 * @param array<string, mixed> $input
 * @return array{
 *   errors: array<string, string>,
 *   values: array<string, mixed>,
 *   password: string|null
 * }
 */
function validate_user_management_input(
    PDO $pdo,
    array $input,
    ?int $excludeUserId,
    bool $passwordRequired
): array {
    $errors = [];

    $fullName = trim((string) ($input['full_name'] ?? ''));
    $email = normalize_email((string) ($input['email'] ?? ''));
    $role = (string) ($input['role'] ?? 'student');
    $status = (string) ($input['status'] ?? 'active');
    $studentNumber = normalize_student_number((string) ($input['student_number'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $department = trim((string) ($input['department'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $passwordConfirm = (string) ($input['password_confirm'] ?? '');

    if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120) {
        $errors['full_name'] = 'Enter a full name between 2 and 120 characters.';
    }

    if ($email === '' || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if (!array_key_exists($role, role_options())) {
        $errors['role'] = 'Choose a valid role.';
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $errors['status'] = 'Choose a valid account status.';
    }

    if ($role === 'student') {
        if ($studentNumber === '' || mb_strlen($studentNumber) > 40) {
            $errors['student_number'] = 'A registration number is required for student accounts.';
        }
    } else {
        $studentNumber = '';
    }

    if (mb_strlen($phone) > 30) {
        $errors['phone'] = 'Phone number must not exceed 30 characters.';
    }

    if (mb_strlen($department) > 100) {
        $errors['department'] = 'Department must not exceed 100 characters.';
    }

    $needsPassword = $passwordRequired || $password !== '' || $passwordConfirm !== '';
    if ($needsPassword) {
        if ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'The password confirmation does not match.';
        } else {
            $passwordError = password_error($password);
            if ($passwordError !== null) {
                $errors['password'] = $passwordError;
            }
        }
    }

    if (!isset($errors['email']) && $email !== '') {
        $sql = 'SELECT id FROM users WHERE email = :email';
        $params = ['email' => $email];

        if ($excludeUserId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $excludeUserId;
        }

        $statement = $pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);

        if ($statement->fetchColumn() !== false) {
            $errors['email'] = 'That email address is already registered.';
        }
    }

    if (!isset($errors['student_number']) && $studentNumber !== '') {
        $sql = 'SELECT id FROM users WHERE student_number = :number';
        $params = ['number' => $studentNumber];

        if ($excludeUserId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $excludeUserId;
        }

        $statement = $pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);

        if ($statement->fetchColumn() !== false) {
            $errors['student_number'] = 'That registration number is already registered.';
        }
    }

    return [
        'errors' => $errors,
        'values' => [
            'full_name'      => $fullName,
            'email'          => $email,
            'role'           => $role,
            'status'         => $status,
            'student_number' => $studentNumber === '' ? null : $studentNumber,
            'phone'          => $phone === '' ? null : $phone,
            'department'     => $department === '' ? null : $department,
        ],
        'password' => $needsPassword ? $password : null,
    ];
}

/**
 * Resolve a role name to its identifier.
 */
function role_id_for(PDO $pdo, string $roleName): int
{
    $statement = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
    $statement->execute(['name' => $roleName]);
    $id = $statement->fetchColumn();

    if ($id === false) {
        throw new RuntimeException('Requested role does not exist: ' . $roleName);
    }

    return (int) $id;
}

/**
 * Increment the account's session version.
 *
 * Every password change, administrator reset and deactivation bumps this so
 * current_user() can reject stale sessions server-side.
 */
function bump_user_session_version(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = :id')
        ->execute(['id' => $userId]);
}

/**
 * Set a password and increment the session version, revoking other sessions.
 *
 * @return int The account's new session version.
 */
function update_user_password(PDO $pdo, int $userId, string $password): int
{
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare(
        'UPDATE users SET password_hash = :hash, session_version = session_version + 1 WHERE id = :id'
    )->execute(['hash' => $newHash, 'id' => $userId]);

    $statement = $pdo->prepare('SELECT session_version FROM users WHERE id = :id');
    $statement->execute(['id' => $userId]);

    return (int) $statement->fetchColumn();
}

/**
 * Refresh the current session's version after changing our own account.
 */
function remember_current_session_version(int $sessionUserId, int $userId, int $newVersion): void
{
    if ((int) $sessionUserId === (int) $userId) {
        $_SESSION['session_version'] = $newVersion;
    }
}

/**
 * Read the account's current session version and update the current session
 * if it belongs to that account. Used after password resets on other accounts
 * so we can stay signed in when we reset our own password.
 */
function refresh_session_version_for_user(PDO $pdo, int $sessionUserId, int $userId): void
{
    if ($sessionUserId !== $userId) {
        return;
    }
    $statement = $pdo->prepare('SELECT session_version FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $userId]);
    $version = $statement->fetchColumn();
    if ($version !== false) {
        $_SESSION['session_version'] = (int) $version;
    }
}
