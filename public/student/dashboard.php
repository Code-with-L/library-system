<?php
/**
 * Student dashboard.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_login($pdo);

if ((string) $user['role_name'] !== 'student') {
    abort_403('This dashboard is for student accounts.');
}

$settings = get_settings($pdo);

$requestStats = [
    'pending'  => 0,
    'approved' => 0,
    'total'    => 0,
];

$requestStatement = $pdo->prepare(
    'SELECT status, COUNT(*) AS total
     FROM borrow_requests
     WHERE user_id = :user_id
     GROUP BY status'
);
$requestStatement->execute(['user_id' => (int) $user['id']]);
foreach ($requestStatement->fetchAll() as $row) {
    $requestStats['total'] += (int) $row['total'];
    if (isset($requestStats[$row['status']])) {
        $requestStats[$row['status']] = (int) $row['total'];
    }
}

$loanStatement = $pdo->prepare(
    "SELECT
        COUNT(*) AS active_loans,
        COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_loans
     FROM borrowings
     WHERE user_id = :user_id AND status = 'active'"
);
$loanStatement->execute(['user_id' => (int) $user['id']]);
$loanCounts = $loanStatement->fetch() ?: ['active_loans' => 0, 'overdue_loans' => 0];

$fineStatement = $pdo->prepare(
    'SELECT COALESCE(SUM(f.amount), 0) AS total_fines
     FROM fines f
     INNER JOIN borrowings b ON b.id = f.borrowing_id
     WHERE b.user_id = :user_id'
);
$fineStatement->execute(['user_id' => (int) $user['id']]);
$totalFines = (float) $fineStatement->fetchColumn();

$fineStats = fines_outstanding_stats($pdo, (int) $user['id']);

$recentRequests = $pdo->prepare(
    'SELECT br.status, br.created_at, b.title, b.author
     FROM borrow_requests br
     INNER JOIN books b ON b.id = br.book_id
     WHERE br.user_id = :user_id
     ORDER BY br.id DESC
     LIMIT 5'
);
$recentRequests->execute(['user_id' => (int) $user['id']]);
$requests = $recentRequests->fetchAll();

$recentLoans = $pdo->prepare(
    'SELECT b.title, br.issue_date, br.due_date, br.return_date, br.status
     FROM borrowings br
     INNER JOIN book_copies bc ON bc.id = br.book_copy_id
     INNER JOIN books b ON b.id = bc.book_id
     WHERE br.user_id = :user_id
     ORDER BY br.id DESC
     LIMIT 5'
);
$recentLoans->execute(['user_id' => (int) $user['id']]);
$loans = $recentLoans->fetchAll();

$approvedAwaitingStatement = $pdo->prepare(
    "SELECT COUNT(*) AS n FROM borrow_requests WHERE user_id = :user_id AND status = 'approved'"
);
$approvedAwaitingStatement->execute(['user_id' => (int) $user['id']]);
$approvedAwaiting = (int) $approvedAwaitingStatement->fetchColumn();

$upcomingDueStatement = $pdo->prepare(
    "SELECT COUNT(*) AS n FROM borrowings
      WHERE user_id = :user_id AND status = 'active'
        AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
);
$upcomingDueStatement->execute(['user_id' => (int) $user['id']]);
$upcomingDue = (int) $upcomingDueStatement->fetchColumn();

$pageTitle = 'Student dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-intro">
    <div>
        <h2>Welcome, <?= e($user['full_name']) ?></h2>
        <p>Your borrowing summary and library policy at a glance.</p>
    </div>
    <div class="page-actions">
        <?php if ($user['student_number'] !== null): ?>
            <span class="badge badge--info"><?= e($user['student_number']) ?></span>
        <?php endif; ?>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('account/profile.php')) ?>">Update profile</a>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('catalogue.php')) ?>">Browse catalogue</a>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('student/fines.php')) ?>">My fines</a>
    </div>
</div>

<div class="stat-grid">
    <article class="stat">
        <p class="stat__label">Active loans</p>
        <p class="stat__value"><?= e($loanCounts['active_loans']) ?></p>
        <p class="stat__hint">of <?= e($settings['max_active_borrowings']) ?> allowed</p>
    </article>
    <article class="stat stat--gold">
        <p class="stat__label">Pending requests</p>
        <p class="stat__value"><?= e($requestStats['pending']) ?></p>
        <p class="stat__hint"><?= e($requestStats['approved']) ?> approved</p>
    </article>
    <article class="stat stat--info">
        <p class="stat__label">Total requests</p>
        <p class="stat__value"><?= e($requestStats['total']) ?></p>
        <p class="stat__hint">All time</p>
    </article>
    <article class="stat <?= (int) $loanCounts['overdue_loans'] > 0 ? 'stat--danger' : '' ?>">
        <p class="stat__label">Overdue</p>
        <p class="stat__value"><?= e($loanCounts['overdue_loans']) ?></p>
        <p class="stat__hint"><?= e(format_money($totalFines, (string) $settings['currency_code'])) ?> recorded fines</p>
    </article>
    <article class="stat stat--danger">
        <p class="stat__label">Outstanding fines</p>
        <p class="stat__value"><?= e((int) $fineStats['outstanding_count']) ?></p>
        <p class="stat__hint"><?= e(fines_format((float) $fineStats['outstanding_balance'], 'KES')) ?> outstanding</p>
    </article>
    <article class="stat">
        <p class="stat__label">Settled fines</p>
        <p class="stat__value"><?= e((int) $fineStats['settled_count']) ?></p>
        <p class="stat__hint">Historical records</p>
    </article>
    <article class="stat stat--gold">
        <p class="stat__label">Approved awaiting collection</p>
        <p class="stat__value"><?= e($approvedAwaiting) ?></p>
        <p class="stat__hint">Staff has selected a copy</p>
    </article>
    <article class="stat stat--info">
        <p class="stat__label">Due within 7 days</p>
        <p class="stat__value"><?= e($upcomingDue) ?></p>
        <p class="stat__hint">Active loans</p>
    </article>
</div>

<div class="panel-grid">
    <section class="card span-7" aria-labelledby="my-requests-title">
        <div class="card__header">
            <div>
                <h2 id="my-requests-title">My borrow requests</h2>
                <p>Requesting titles opens in Phase 3.</p>
            </div>
        </div>
        <?php if ($requests === []): ?>
            <div class="empty-state">
                <h3>No requests yet</h3>
                <p>You will be able to browse the catalogue and request a title in the next phase.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Title</th>
                            <th scope="col">Status</th>
                            <th scope="col">Requested</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <td>
                                    <span class="table__primary"><?= e($request['title']) ?></span>
                                    <span class="table__secondary"><?= e($request['author']) ?></span>
                                </td>
                                <td><span class="badge <?= e(badge_class((string) $request['status'])) ?>"><?= e(status_label((string) $request['status'])) ?></span></td>
                                <td class="nowrap"><?= e(format_datetime((string) $request['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card span-5" aria-labelledby="my-loans-title">
        <div class="card__header">
            <div>
                <h2 id="my-loans-title">My borrowing history</h2>
                <p>Most recent loans first.</p>
            </div>
        </div>
        <?php if ($loans === []): ?>
            <div class="empty-state">
                <h3>No loans yet</h3>
                <p>Issued and returned books will be listed here.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--compact">
                    <thead>
                        <tr>
                            <th scope="col">Title</th>
                            <th scope="col">Due</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loans as $loan): ?>
                            <tr>
                                <td><?= e($loan['title']) ?></td>
                                <td class="nowrap"><?= e(format_date((string) $loan['due_date'])) ?></td>
                                <td><span class="badge <?= e(badge_class((string) $loan['status'])) ?>"><?= e(status_label((string) $loan['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card span-12" aria-labelledby="student-policy-title">
        <div class="card__header">
            <div>
                <h2 id="student-policy-title">Borrowing policy</h2>
                <p>Set by the library administrator.</p>
            </div>
        </div>
        <div class="card__body">
            <div class="panel-grid">
                <div class="span-4">
                    <p class="stat__label">Loan period</p>
                    <p class="mb-0"><?= e($settings['loan_period_days']) ?> days per item</p>
                </div>
                <div class="span-4">
                    <p class="stat__label">Borrowing limit</p>
                    <p class="mb-0"><?= e($settings['max_active_borrowings']) ?> active items</p>
                </div>
                <div class="span-4">
                    <p class="stat__label">Overdue fine</p>
                    <p class="mb-0"><?= e(format_money($settings['fine_per_day'], (string) $settings['currency_code'])) ?> per day</p>
                </div>
            </div>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
