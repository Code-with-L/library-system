<?php
/**
 * Authenticated navigation sidebar.
 * Expects $user, $activeNav and $libraryName from header.php.
 */
$role = (string) $user['role_name'];

$navItems = [];

if ($role === 'admin') {
    $navItems = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'path' => 'admin/dashboard.php'],
        ['key' => 'catalogue', 'label' => 'Catalogue', 'path' => 'staff/catalogue.php'],
        ['key' => 'requests',  'label' => 'Requests', 'path' => 'staff/requests.php'],
        ['key' => 'issue',     'label' => 'Await issue', 'path' => 'staff/issue.php'],
        ['key' => 'returns',   'label' => 'Returns', 'path' => 'staff/returns.php'],
        ['key' => 'fines',     'label' => 'Fines', 'path' => 'staff/fines.php'],
        ['key' => 'users',     'label' => 'User management', 'path' => 'admin/users.php'],
        ['key' => 'profile',   'label' => 'My profile', 'path' => 'account/profile.php'],
    ];
} elseif ($role === 'librarian') {
    $navItems = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'path' => 'staff/dashboard.php'],
        ['key' => 'catalogue', 'label' => 'Catalogue', 'path' => 'staff/catalogue.php'],
        ['key' => 'requests',  'label' => 'Requests', 'path' => 'staff/requests.php'],
        ['key' => 'issue',     'label' => 'Await issue', 'path' => 'staff/issue.php'],
        ['key' => 'returns',   'label' => 'Returns', 'path' => 'staff/returns.php'],
        ['key' => 'fines',     'label' => 'Fines', 'path' => 'staff/fines.php'],
        ['key' => 'profile',   'label' => 'My profile', 'path' => 'account/profile.php'],
    ];
} else {
    $navItems = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'path' => 'student/dashboard.php'],
        ['key' => 'catalogue', 'label' => 'Catalogue', 'path' => 'catalogue.php'],
        ['key' => 'fines',     'label' => 'Fines', 'path' => 'student/fines.php'],
        ['key' => 'profile',   'label' => 'My profile', 'path' => 'account/profile.php'],
    ];
}
?>
<aside class="sidebar" id="primary-sidebar">
    <div class="sidebar__top">
        <a class="brand" href="<?= e(url(dashboard_path_for_role($role))) ?>">
            <span class="brand__mark" aria-hidden="true">
                <svg viewBox="0 0 32 32" role="img" focusable="false">
                    <path d="M6 7.5c3.6-1.6 7-.2 10 1.7v16c-3-1.9-6.4-3.3-10-1.7z" fill="currentColor" opacity=".9"/>
                    <path d="M26 7.5c-3.6-1.6-7-.2-10 1.7v16c3-1.9 6.4-3.3 10-1.7z" fill="currentColor" opacity=".55"/>
                </svg>
            </span>
            <span class="brand__text">
                <strong><?= e($libraryName) ?></strong>
                <small>Library system</small>
            </span>
        </a>
    </div>

    <nav class="sidebar__nav" aria-label="Main navigation">
        <p class="sidebar__label"><?= e(role_label($role)) ?> workspace</p>
        <ul>
            <?php foreach ($navItems as $item): ?>
                <li>
                    <a class="sidebar__link<?= nav_is_active($item['key'], $activeNav) ? ' is-active' : '' ?>"
                       href="<?= e(url($item['path'])) ?>"
                       <?= nav_is_active($item['key'], $activeNav) ? 'aria-current="page"' : '' ?>>
                        <?= e($item['label']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($role === 'admin'): ?>
            <p class="sidebar__label">Quick actions</p>
            <ul>
                <li><a class="sidebar__link sidebar__link--muted" href="<?= e(url('admin/user-form.php')) ?>">Add a user</a></li>
                <li><a class="sidebar__link sidebar__link--muted" href="<?= e(url('admin/users.php?role=student')) ?>">View students</a></li>
            </ul>
        <?php endif; ?>
    </nav>

    <div class="sidebar__footer">
        <p><?= e($libraryName) ?></p>
        <small>Phase 2 &middot; Foundation</small>
    </div>
</aside>
