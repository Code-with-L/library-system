<?php
/**
 * Administrator user list with search, filters and pagination.
 */
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/users.php';
require_once __DIR__ . '/../../includes/pagination.php';

$user = require_role($pdo, 'admin');

$search = trim((string) ($_GET['q'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));

if ($roleFilter !== '' && !array_key_exists($roleFilter, role_options())) {
    $roleFilter = '';
}

if (!in_array($statusFilter, ['active', 'inactive'], true)) {
    $statusFilter = '';
}

$perPage = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = [];
$params = [];

if ($search !== '') {
    // Escape LIKE wildcards so a literal "%" or "_" is searchable.
    $escapedSearch = str_replace(
        ['\\', '%', '_'],
        ['\\\\', '\\%', '\\_'],
        $search
    );
    // MySQL's default escape character is backslash, so escaped `\\`, `\%`
    // and `\_` are sufficient without an explicit ESCAPE clause.
    $where[] = '(u.full_name LIKE :q_name OR u.email LIKE :q_email OR u.student_number LIKE :q_number)';
    $like = '%' . $escapedSearch . '%';
    $params['q_name'] = $like;
    $params['q_email'] = $like;
    $params['q_number'] = $like;
}

if ($roleFilter !== '') {
    $where[] = 'r.name = :role_name';
    $params['role_name'] = $roleFilter;
}

if ($statusFilter !== '') {
    $where[] = 'u.status = :status';
    $params['status'] = $statusFilter;
}

$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

$countStatement = $pdo->prepare(
    'SELECT COUNT(*)
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id' . $whereSql
);
$countStatement->execute($params);
$totalUsers = (int) $countStatement->fetchColumn();

$totalPages = max(1, (int) ceil($totalUsers / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStatement = $pdo->prepare(
    'SELECT u.id, u.full_name, u.email, u.student_number, u.status,
            u.last_login_at, u.created_at, r.name AS role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id' . $whereSql . '
     ORDER BY u.id ASC
     LIMIT :limit OFFSET :offset'
);

foreach ($params as $name => $value) {
    $listStatement->bindValue(':' . $name, $value, PDO::PARAM_STR);
}
$listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStatement->execute();
$users = $listStatement->fetchAll();

$activeAdminCount = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE r.name = 'admin' AND u.status = 'active'"
)->fetchColumn();

$queryParams = array_filter([
    'q'      => $search,
    'role'   => $roleFilter,
    'status' => $statusFilter,
], static fn ($value) => $value !== '');

$pageTitle = 'User management';
$activeNav = 'users';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-intro">
    <div>
        <h2>Accounts</h2>
        <p><?= e($totalUsers) ?> account<?= $totalUsers === 1 ? '' : 's' ?> match the current view.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn--primary" href="<?= e(url('admin/user-form.php')) ?>">Add a user</a>
    </div>
</div>

<section class="card">
    <form class="filters" method="get" action="<?= e(url('admin/users.php')) ?>">
        <div class="field field--grow">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Name, email or student number" maxlength="120">
        </div>

        <div class="field">
            <label for="role">Role</label>
            <select id="role" name="role">
                <option value="">All roles</option>
                <?php foreach (role_options() as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $roleFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">Any status</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>

        <div class="filters__actions">
            <button class="btn btn--primary btn--sm" type="submit">Apply</button>
            <?php if ($queryParams !== []): ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/users.php')) ?>">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($users === []): ?>
        <div class="empty-state">
            <h3>No matching accounts</h3>
            <p>Try a different search term or clear the filters. You can also create a new account.</p>
            <a class="btn btn--primary btn--sm" href="<?= e(url('admin/user-form.php')) ?>">Add a user</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Account</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col">Last sign-in</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $row): ?>
                        <?php
                        $rowId = (int) $row['id'];
                        $isSelf = $rowId === (int) $user['id'];
                        $isLastActiveAdmin = $row['role_name'] === 'admin'
                            && $row['status'] === 'active'
                            && $activeAdminCount <= 1;
                        ?>
                        <tr>
                            <td>
                                <span class="table__primary"><?= e($row['full_name']) ?></span>
                                <span class="table__secondary"><?= e($row['email']) ?></span>
                                <?php if ($row['student_number'] !== null): ?>
                                    <span class="table__secondary"><?= e($row['student_number']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge--muted"><?= e(role_label((string) $row['role_name'])) ?></span></td>
                            <td>
                                <span class="badge <?= e(badge_class((string) $row['status'])) ?>"><?= e(status_label((string) $row['status'])) ?></span>
                                <?php if ($isSelf): ?>
                                    <span class="table__secondary">This is you</span>
                                <?php elseif ($isLastActiveAdmin): ?>
                                    <span class="table__secondary">Last active admin</span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap"><?= e(format_datetime($row['last_login_at'] !== null ? (string) $row['last_login_at'] : null)) ?></td>
                            <td class="table__actions">
                                <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/user-form.php?id=' . $rowId)) ?>">Edit</a>

                                <?php if (!$isSelf): ?>
                                    <?php if ($row['status'] === 'active'): ?>
                                        <form method="post" action="<?= e(url('admin/user-action.php')) ?>"
                                              data-confirm="Deactivate <?= e($row['full_name']) ?>? They will be signed out and unable to sign in.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e($rowId) ?>">
                                            <input type="hidden" name="action" value="deactivate">
                                            <button class="btn btn--ghost btn--sm" type="submit"
                                                    <?= $isLastActiveAdmin ? 'disabled' : '' ?>>Deactivate</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?= e(url('admin/user-action.php')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e($rowId) ?>">
                                            <input type="hidden" name="action" value="activate">
                                            <button class="btn btn--ghost btn--sm" type="submit">Activate</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php render_pagination($page, $totalPages, 'admin/users.php', $queryParams); ?>
    <?php endif; ?>
</section>

<div class="callout mt-2">
    <p>
        Accounts are deactivated rather than deleted so borrowing history and audit records stay intact.
        The last active administrator and your own account are protected by server-side checks.
    </p>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
