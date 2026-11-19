<?php
/**
 * Authorization functions.
 *
 * These checks run on the server for every protected request. Hiding a link
 * in the interface is never treated as a security control.
 */

/**
 * Require a logged-in account and return the fresh user record.
 *
 * @return array<string, mixed>
 */
function require_login(PDO $pdo): array
{
    $user = current_user($pdo);

    if ($user === null) {
        flash('warning', 'Please log in to continue.');
        redirect('login.php');
    }

    return $user;
}

/**
 * Require one exact role.
 *
 * @return array<string, mixed>
 */
function require_role(PDO $pdo, string $role): array
{
    $user = require_login($pdo);

    if ($user['role_name'] !== $role) {
        abort_403('You do not have permission to access this page.');
    }

    return $user;
}

/**
 * Require an administrator or librarian.
 *
 * @return array<string, mixed>
 */
function require_staff(PDO $pdo): array
{
    $user = require_login($pdo);

    if (!in_array($user['role_name'], ['admin', 'librarian'], true)) {
        abort_403('This page is available to library staff only.');
    }

    return $user;
}

/**
 * Redirect an authenticated user away from login/registration pages.
 */
function require_guest(PDO $pdo): void
{
    redirect_authenticated_user($pdo);
}

/**
 * Verify that the current student owns the requested record.
 */
function require_student_owner(int $sessionUserId, int $recordUserId): void
{
    if ($sessionUserId !== $recordUserId) {
        abort_403('You do not have permission to view that record.');
    }
}

/**
 * Render the 403 page and stop.
 */
function abort_403(string $message = 'You do not have permission to access this page.'): void
{
    render_error_document(403, 'Access denied', $message);
    exit;
}

/**
 * Render the 404 page and stop.
 */
function abort_404(string $message = 'The requested page or record could not be found.'): void
{
    render_error_document(404, 'Page not found', $message);
    exit;
}

/**
 * Reject non-POST access to a state-changing endpoint.
 */
function abort_405(string $message = 'This action must be submitted using a POST form.'): void
{
    header('Allow: POST');
    render_error_document(405, 'Method not allowed', $message);
    exit;
}
