<?php
/**
 * Student request history and cancel entry point.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_role($pdo, 'student');

$requests = catalogue_requests_fetch($pdo, ['user_id' => (int) $user['id']], 100);

$pageTitle = 'My requests';
$activeNav = 'catalogue';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro">
    <div>
        <h2>My borrow requests</h2>
        <p>Pending and approved requests, plus your borrowing history.</p>
    </div>
    <a class="btn btn--ghost btn--sm" href="<?= e(url('catalogue.php')) ?>">Browse catalogue</a>
</div>

<?php if ($requests === []): ?>
    <div class="empty-state"><h3>No requests yet</h3><p>Submit a request from a book’s details page.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table--history">
            <thead><tr>
                <th>Title</th><th>Status</th><th>Created</th><th><span class="sr-only">Actions</span></th>
            </tr></thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td class="hist-title" data-label="Title"><a href="<?= e(url('catalogue/detail.php?id=' . (int) $request['book_id'])) ?>"><?= e($request['book_title']) ?></a></td>
                        <td class="hist-status" data-label="Status"><span class="badge <?= e(badge_class((string) $request['status'])) ?>"><?= e(status_label((string) $request['status'])) ?></span></td>
                        <td class="hist-created" data-label="Created"><?= e(format_datetime((string) $request['created_at'])) ?></td>
                        <td class="hist-action">
                            <?php if (in_array($request['status'], ['pending', 'approved'], true)): ?>
                                <form method="post" action="<?= e(url('catalogue/cancel.php')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="request_id" value="<?= e((int) $request['id']) ?>">
                                    <button class="btn btn--ghost btn--sm" type="submit">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

