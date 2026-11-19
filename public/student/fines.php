<?php
/**
 * Student fine history: outstanding and settled fines.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_role($pdo, 'student');

$fines = fines_fetch_student($pdo, (int) $user['id'], [], 100);
$outstandingStats = fines_outstanding_stats($pdo, (int) $user['id']);

$pageTitle = 'My fines';
$activeNav = 'fines';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-intro"><div><h2>My fines</h2><p>Outstanding and settled fines for your account.</p></div></div>

<div class="stat-grid">
    <article class="stat stat--danger">
        <p class="stat__label">Outstanding fines</p>
        <p class="stat__value"><?= e((int) $outstandingStats['outstanding_count']) ?></p>
        <p class="stat__hint"><?= e(fines_format((float) $outstandingStats['outstanding_balance'], 'KES')) ?> outstanding</p>
    </article>
    <article class="stat">
        <p class="stat__label">Settled fines</p>
        <p class="stat__value"><?= e((int) $outstandingStats['settled_count']) ?></p>
        <p class="stat__hint">Historical records</p>
    </article>
</div>

<?php if ($fines === []): ?>
    <div class="empty-state"><h3>No fines</h3><p>You have no recorded fines. Fines are assessed when overdue books are returned.</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th>Fine</th><th>Title</th><th>Copy</th><th>Due date</th><th>Days overdue</th><th>Amount</th><th>Status</th>
            </tr></thead>
            <tbody>
                <?php foreach ($fines as $fine): ?>
                    <tr>
                        <td class="mono"><?= e((string) $fine['id']) ?></td>
                        <td><?= e((string) $fine['book_title'] ?? '—') ?></td>
                        <td><code><?= e((string) $fine['copy_code'] ?? '—') ?></code></td>
                        <td class="nowrap"><?= e(format_date((string) $fine['due_date'] ?? '')) ?></td>
                        <td class="nowrap">
                            <?php if ((string) $fine['borrowing_status'] === 'active' && $fine['due_date'] !== null): ?>
                                <?= e((string) fines_active_overdue_days($pdo, (int) $fine['borrowing_id'])) ?>
                            <?php elseif ($fine['return_date'] !== null): ?>
                                <?= e((string) fines_returned_days((string) $fine['return_date'], (string) $fine['due_date'] ?? '')) ?>
                            <?php else: ?> 0 <?php endif; ?>
                        </td>
                        <td class="nowrap"><?= e(fines_format((float) $fine['amount'], 'KES')) ?></td>
                        <td><span class="badge <?= e(badge_class((string) $fine['status'])) ?>"><?= e(status_label((string) $fine['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
