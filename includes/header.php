<?php
/**
 * Shared page header and layout shell.
 *
 * Pages set these variables before including this file:
 *   $pageTitle  string  Page heading.
 *   $activeNav  string  Sidebar key to highlight.
 *   $user       array   Authenticated user (normally from require_login()).
 *   $forceGuest bool    Render the guest layout even when signed in.
 */

if (!defined('APP_ROOT')) {
    require_once __DIR__ . '/init.php';
}

$pageTitle = $pageTitle ?? 'Campus Library';
$activeNav = $activeNav ?? '';
$user = $user ?? current_user($pdo);
$forceGuest = $forceGuest ?? false;
$settings = get_settings($pdo);
$libraryName = (string) $settings['library_name'];
$authenticatedLayout = $user !== null && !$forceGuest;
$bodyClass = $authenticatedLayout ? 'layout-app' : 'layout-guest';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Campus Library Management System">
    <title><?= e($pageTitle) ?> &middot; <?= e($libraryName) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/print.css')) ?>" media="print">
    <script src="<?= e(url('assets/js/boot.js')) ?>"></script>
</head>
<body class="<?= e($bodyClass) ?>">
<a class="skip-link" href="#main-content">Skip to main content</a>

<?php if ($authenticatedLayout): ?>
<div class="app-shell">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="main-column">
        <header class="topbar">
            <button class="nav-toggle" type="button" data-nav-toggle
                    aria-controls="primary-sidebar" aria-expanded="false">
                <span class="nav-toggle__bars" aria-hidden="true"></span>
                <span class="sr-only">Toggle navigation</span>
            </button>

            <div class="topbar__heading">
                <p class="topbar__eyebrow"><?= e($libraryName) ?></p>
                <h1><?= e($pageTitle) ?></h1>
            </div>

            <div class="topbar__user">
                <div class="user-chip">
                    <span class="user-chip__avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $user['full_name'], 0, 1))) ?></span>
                    <span class="user-chip__text">
                        <strong><?= e($user['full_name']) ?></strong>
                        <small><?= e(role_label($user['role_name'])) ?></small>
                    </span>
                </div>
                <form method="post" action="<?= e(url('logout.php')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button class="btn btn--ghost btn--sm" type="submit">Sign out</button>
                </form>
            </div>
        </header>

        <main id="main-content" class="content" tabindex="-1">
            <?php require __DIR__ . '/alerts.php'; ?>
<?php else: ?>
<div class="guest-shell">
    <header class="guest-header">
        <a class="brand brand--guest" href="<?= e(url('index.php')) ?>">
            <span class="brand__mark" aria-hidden="true">
                <svg viewBox="0 0 32 32" role="img" focusable="false">
                    <path d="M6 7.5c3.6-1.6 7-.2 10 1.7v16c-3-1.9-6.4-3.3-10-1.7z" fill="currentColor" opacity=".9"/>
                    <path d="M26 7.5c-3.6-1.6-7-.2-10 1.7v16c3-1.9 6.4-3.3 10-1.7z" fill="currentColor" opacity=".55"/>
                </svg>
            </span>
            <span class="brand__text">
                <strong><?= e($libraryName) ?></strong>
                <small>Campus Library System</small>
            </span>
        </a>

        <nav class="guest-nav" aria-label="Account">
            <?php if ($user !== null): ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(url(dashboard_path_for_role((string) $user['role_name']))) ?>">Dashboard</a>
            <?php else: ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(url('login.php')) ?>">Sign in</a>
                <a class="btn btn--primary btn--sm" href="<?= e(url('register.php')) ?>">Create account</a>
            <?php endif; ?>
        </nav>
    </header>

    <main id="main-content" class="guest-main" tabindex="-1">
        <?php require __DIR__ . '/alerts.php'; ?>
<?php endif; ?>
