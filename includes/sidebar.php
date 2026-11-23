<?php
/**
 * Authenticated navigation sidebar.
 * Expects $user, $activeNav and $libraryName from header.php.
 */
$role = (string) $user['role_name'];

// Small monochrome menu icons. Presentation only; routes and guards unchanged.
$icons = [
    'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'catalogue' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5z"/><path d="M4 18.5A2.5 2.5 0 0 1 6.5 16H20"/>',
    'requests'  => '<path d="M4 6h16M4 12h16M4 18h10"/>',
    'issue'     => '<path d="M4 7h12v10H4z"/><path d="m16 12 4-3v6z"/>',
    'returns'   => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
    'fines'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'reports'   => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    'users'     => '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M17 11a3 3 0 1 0-2-5.2"/><path d="M17 14a5 5 0 0 1 4 5"/>',
    'activity'  => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
    'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4v-.2a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.6-1H3v-4h.2a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.6V3h4v.2a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4h-.2a1.7 1.7 0 0 0-1.6 1z"/>',
    'profile'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
];

$navItems = [];

if ($role === 'admin') {
    $navItems = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'path' => 'admin/dashboard.php'],
        ['key' => 'catalogue', 'label' => 'Catalogue', 'path' => 'staff/catalogue.php'],
        ['key' => 'requests',  'label' => 'Requests', 'path' => 'staff/requests.php'],
        ['key' => 'issue',     'label' => 'Await issue', 'path' => 'staff/issue.php'],
        ['key' => 'returns',   'label' => 'Returns', 'path' => 'staff/returns.php'],
        ['key' => 'fines',     'label' => 'Fines', 'path' => 'staff/fines.php'],
        ['key' => 'reports',   'label' => 'Reports', 'path' => 'staff/reports/index.php'],
        ['key' => 'users',     'label' => 'User management', 'path' => 'admin/users.php'],
        ['key' => 'activity',  'label' => 'Activity log', 'path' => 'admin/activity-log.php'],
        ['key' => 'settings',  'label' => 'Settings', 'path' => 'admin/settings.php'],
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
        ['key' => 'reports',   'label' => 'Reports', 'path' => 'staff/reports/index.php'],
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
            <img class="brand__mark" src="<?= e(url('assets/img/logo-cream.png')) ?>" alt="" aria-hidden="true">
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
                        <span class="sidebar__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$item['key']] ?? $icons['requests'] ?></svg></span><?= e($item['label']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($role === 'admin'): ?>
            <p class="sidebar__label">Quick actions</p>
            <ul>
                <li><a class="sidebar__link sidebar__link--muted" href="<?= e(url('admin/user-form.php')) ?>"><span class="sidebar__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?= $icons['users'] ?></svg></span>Add a user</a></li>
                <li><a class="sidebar__link sidebar__link--muted" href="<?= e(url('admin/users.php?role=student')) ?>"><span class="sidebar__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?= $icons['profile'] ?></svg></span>View students</a></li>
            </ul>
        <?php endif; ?>
    </nav>

    <div class="sidebar__footer">
        <p><?= e($libraryName) ?></p>
        <small>Library management system</small>
    </div>
</aside>
