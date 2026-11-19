<?php
/**
 * Librarian/staff operational reporting index.
 */
require_once __DIR__ . '/../../../includes/init.php';

$user = require_staff($pdo);

$pageTitle = 'Staff reports';
$activeNav = 'reports';
require __DIR__ . '/../../../includes/header.php';
?>

<div class="page-intro"><div><h2>Operational reports</h2><p>Useful library operations reporting built from real database data.</p></div></div>

<div class="panel-grid">
    <section class="card span-6" aria-labelledby="borrowing-report-title">
        <div class="card__header"><h2 id="borrowing-report-title">Borrowing report</h2><p>Current and completed borrowings.</p></div>
        <div class="card__body"><a class="btn btn--primary btn--sm" href="<?= e(url('staff/reports/borrowing.php')) ?>">Open borrowing report</a></div>
    </section>
    <section class="card span-6" aria-labelledby="overdue-report-title">
        <div class="card__header"><h2 id="overdue-report-title">Overdue report</h2><p>Overdue borrowings and outstanding fines.</p></div>
        <div class="card__body"><a class="btn btn--primary btn--sm" href="<?= e(url('staff/reports/overdue.php')) ?>">Open overdue report</a></div>
    </section>
    <section class="card span-6" aria-labelledby="catalogue-report-title">
        <div class="card__header"><h2 id="catalogue-report-title">Catalogue report</h2><p>Titles, copies, status and category availability.</p></div>
        <div class="card__body"><a class="btn btn--primary btn--sm" href="<?= e(url('staff/reports/catalogue.php')) ?>">Open catalogue report</a></div>
    </section>
    <section class="card span-6" aria-labelledby="fines-report-title">
        <div class="card__header"><h2 id="fines-report-title">Fines report</h2><p>Outstanding and settled fines with totals.</p></div>
        <div class="card__body"><a class="btn btn--primary btn--sm" href="<?= e(url('staff/reports/fines.php')) ?>">Open fines report</a></div>
    </section>
</div>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
