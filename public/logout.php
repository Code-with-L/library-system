<?php
/**
 * Sign out. State-changing, therefore POST-only with CSRF verification.
 */
require_once __DIR__ . '/../includes/init.php';

if (!is_post()) {
    abort_405('Signing out must be submitted using the sign-out button.');
}

verify_csrf();
logout_user($pdo);

flash('success', 'You have been signed out.');
redirect('login.php');
