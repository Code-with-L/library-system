<?php
/**
 * Server-side account activation and deactivation.
 */
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/users.php';

$actor = require_role($pdo, 'admin');

if (!is_post()) {
    abort_405('Account status changes must be submitted using a POST form.');
}

verify_csrf();

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
$action = (string) ($_POST['action'] ?? '');

if ($id === false || $id === null || (int) $id < 1) {
    abort_404('The requested user account does not exist.');
}

if (!in_array($action, ['activate', 'deactivate'], true)) {
    abort_404('Unknown account action.');
}

$id = (int) $id;
$target = find_user_by_id($pdo, $id);

if ($target === null) {
    abort_404('The requested user account does not exist.');
}

if ($id === (int) $actor['id']) {
    flash('error', 'You cannot change the status of your own account.');
    redirect('admin/users.php');
}

$newStatus = $action === 'activate' ? 'active' : 'inactive';

if ((string) $target['status'] === $newStatus) {
    flash('info', 'That account already has the requested status.');
    redirect('admin/users.php');
}

try {
    $pdo->beginTransaction();

    lock_admin_role($pdo);
    $lockedTarget = lock_user_for_update($pdo, $id);

    if ($lockedTarget === null) {
        $pdo->rollBack();
        abort_404('The requested user account does not exist.');
    }

    if ((int) $lockedTarget['id'] === (int) $actor['id']) {
        $pdo->rollBack();
        flash('error', 'You cannot change the status of your own account.');
        redirect('admin/users.php');
    }

    if (removes_last_active_admin($pdo, $lockedTarget, (string) $lockedTarget['role_name'], $newStatus)) {
        $pdo->rollBack();
        flash('error', 'That change would deactivate the last active administrator.');
        redirect('admin/users.php');
    }

    $statement = $pdo->prepare(
        'UPDATE users SET status = :status, session_version = session_version + 1 WHERE id = :id'
    );
    $statement->execute(['status' => $newStatus, 'id' => $id]);

    $pdo->commit();

    log_activity(
        $pdo,
        (int) $actor['id'],
        $action === 'activate' ? 'user_activate' : 'user_deactivate',
        'user',
        $id,
        sprintf(
            '%s account %s.',
            $action === 'activate' ? 'Activated' : 'Deactivated',
            (string) $lockedTarget['email']
        )
    );

    flash('success', $action === 'activate' ? 'The account has been activated.' : 'The account has been deactivated.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

redirect('admin/users.php');
