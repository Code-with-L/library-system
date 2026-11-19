<?php
/**
 * Librarian dashboard.
 */
require_once __DIR__ . '/../../includes/init.php';

$user = require_role($pdo, 'librarian');
$settings = get_settings($pdo);

$counts = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM books WHERE status = 'active') AS books,
        (SELECT COUNT(*) FROM book_copies) AS copies,
        (SELECT COUNT(*) FROM book_copies WHERE status = 'available') AS available_copies,
        (SELECT COUNT(*) FROM book_copies WHERE status = 'maintenance') AS maintenance_copies,
        (SELECT COUNT(*) FROM borrowings WHERE status = 'active') AS active_loans,
        (SELECT COUNT(*) FROM borrowings WHERE status = 'active' AND due_date < CURDATE()) AS overdue_loans,
        (SELECT COUNT(*) FROM borrow_requests WHERE status = 'pending') AS pending_requests,
        (SELECT COUNT(*) FROM borrow_requests WHERE status = 'approved') AS approved_requests"
)->fetch();

$fineStats = fines_outstanding_total_all($pdo);

$recentRequests = $pdo->query(
    "SELECT br.id, br.status, br.created_at, b.title, u.full_name
     FROM borrow_requests br
     INNER JOIN books b ON b.id = br.book_id
     INNER JOIN users u ON u.id = br.user_id
     ORDER BY br.id DESC
     LIMIT 6"
)->fetchAll();

$pageTitle = 'Librarian dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-intro">
    <div>
        <h2>Circulation overview</h2>
        <p>Catalogue availability and borrowing activity at a glance.</p>
    </div>
    <div class="page-actions">
        <span class="badge badge--success"><?= e($settings['library_name']) ?></span>
    </div>
</div>

<div class="stat-grid">
    <article class="stat">
        <p class="stat__label">Catalogue titles</p>
        <p class="stat__value"><?= e($counts['books']) ?></p>
        <p class="stat__hint"><?= e($counts['copies']) ?> copies recorded</p>
    </article>
    <article class="stat stat--gold">
        <p class="stat__label">Available copies</p>
        <p class="stat__value"><?= e($counts['available_copies']) ?></p>
        <p class="stat__hint"><?= e($counts['maintenance_copies']) ?> in maintenance</p>
    </article>
    <article class="stat stat--info">
        <p class="stat__label">Active loans</p>
        <p class="stat__value"><?= e($counts['active_loans']) ?></p>
        <p class="stat__hint"><?= e($counts['pending_requests']) ?> requests awaiting review</p>
    </article>
    <article class="stat stat--gold">
        <p class="stat__label">Approved awaiting issue</p>
        <p class="stat__value"><?= e($counts['approved_requests']) ?></p>
        <p class="stat__hint">Go to await issuance</p>
    </article>
    <article class="stat">
        <p class="stat__label">Pending requests</p>
        <p class="stat__value"><?= e($counts['pending_requests']) ?></p>
        <p class="stat__hint">Manage in request queue</p>
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
    <article class="stat stat--danger">
        <p class="stat__label">Overdue loans</p>
        <p class="stat__value"><?= e($counts['overdue_loans']) ?></p>
        <p class="stat__hint">Follow-up needed</p>
    </article>
</div>

<div class="panel-grid">
    <section class="card span-8" aria-labelledby="requests-title">
        <div class="card__header">
            <div>
                <h2 id="requests-title">Recent borrow requests</h2>
                <p>Request review and issuing arrive in Phase 3.</p>
            </div>
        </div>
        <?php if ($recentRequests === []): ?>
            <div class="empty-state">
                <h3>No requests yet</h3>
                <p>When students begin requesting titles, the newest requests will appear here.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Student</th>
                            <th scope="col">Title</th>
                            <th scope="col">Status</th>
                            <th scope="col">Requested</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentRequests as $request): ?>
                            <tr>
                                <td><?= e($request['full_name']) ?></td>
                                <td><?= e($request['title']) ?></td>
                                <td><span class="badge <?= e(badge_class((string) $request['status'])) ?>"><?= e(status_label((string) $request['status'])) ?></span></td>
                                <td class="nowrap"><?= e(format_datetime((string) $request['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card span-12" aria-labelledby="operational-reports-title">
        <div class="card__header"><h2 id="operational-reports-title">Operational reports</h2><p>Current lending and catalogue data.</p></div>
        <div class="card__body">
            <div class="panel-grid">
                <article class="stat"><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/reports/borrowing.php')) ?>">Borrowing report</a></article>
                <article class="stat stat--info"><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/reports/overdue.php')) ?>">Overdue report</a></article>
                <article class="stat stat--gold"><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/reports/catalogue.php')) ?>">Catalogue report</a></article>
                <article class="stat"><a class="btn btn--ghost btn--sm" href="<?= e(url('staff/reports/fines.php')) ?>">Fines report</a></article>
            </div>
        </div>
    </section>

    <section class="card span-4" aria-labelledby="policy-title">
        <div class="card__header">
            <div>
                <h2 id="policy-title">Loan policy</h2>
                <p>Active library settings.</p>
            </div>
        </div>
        <div class="card__body">
            <div class="stack">
                <div>
                    <p class="stat__label">Loan period</p>
                    <p class="mb-0"><?= e($settings['loan_period_days']) ?> days</p>
                </div>
                <div>
                    <p class="stat__label">Maximum active loans</p>
                    <p class="mb-0"><?= e($settings['max_active_borrowings']) ?> per student</p>
                </div>
                <div>
                    <p class="stat__label">Fine rate</p>
                    <p class="mb-0"><?= e(format_money($settings['fine_per_day'], (string) $settings['currency_code'])) ?> per day</p>
                </div>
                <div class="callout">
                    <p>Phase 3 will add catalogue management, request review, issuing and returns.</p>
                </div>
            </div>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
