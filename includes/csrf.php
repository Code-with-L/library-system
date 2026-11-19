<?php
/**
 * Cross-Site Request Forgery protection.
 *
 * A random token is stored in the session and included in every POST form.
 * The token is rotated after login and after a password change.
 */

function rotate_csrf_token(): string
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_token(): string
{
    $token = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || $token === '') {
        return rotate_csrf_token();
    }

    return $token;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if (!is_string($submitted) || !is_string($expected) || $expected === '') {
        abort_403('Your form session expired. Please reload the page and try again.');
    }

    if (!hash_equals($expected, $submitted)) {
        abort_403('Your form session expired. Please reload the page and try again.');
    }
}
