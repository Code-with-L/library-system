<?php
/**
 * Phase 5 CSV export endpoint.
 *
 * Exports the currently authorized and filtered report only. Uses safe CSV
 * generation and formula-injection protection.
 */
require_once __DIR__ . '/../../../includes/init.php';

$user = require_staff($pdo);

$report = (string) ($_GET['report'] ?? '');
if (!in_array($report, ['borrowing', 'overdue', 'catalogue', 'fines'], true)) {
    abort_404('Unknown report export.');
}

$from = reporting_parse_range($_GET);
$errors = $from['errors'];
if ($errors !== []) {
    foreach ($errors as $error) { flash('error', $error); }
}

switch ($report) {
    case 'borrowing':
        $filters = reporting_borrowing_filters($_GET);
        if ($filters['errors'] !== []) { abort_404('Invalid borrowing report filters.'); }
        $data = reporting_borrowings($pdo, $filters, 1, 100000);
        reporting_export_csv('borrowing_report.csv', ['Borrowing', 'Student', 'Book', 'Copy', 'Issue Date', 'Due Date', 'Return Date', 'Status', 'Days Overdue', 'Fine Amount'], array_map(
            fn(array $r): array => [
                (string) $r['borrowing_id'],
                (string) $r['student_name'],
                (string) $r['book_title'],
                (string) $r['copy_code'],
                format_date((string) $r['issue_date']),
                format_date((string) $r['due_date']),
                $r['return_date'] !== null ? format_date((string) $r['return_date']) : '',
                (string) $r['status'],
                (string) $r['days_overdue'],
                $r['fine_id'] ? fines_format((float) $r['fine_amount'], 'KES') : '',
            ],
            $data['rows']
        ));
        break;
    case 'overdue':
        $exportFilters = reporting_resolve_preset($_GET);
        $exportParsed = reporting_parse_range($exportFilters);
        $data = reporting_overdue($pdo, $exportFilters, 100000);
        reporting_export_csv('overdue_report.csv', ['Borrowing', 'Student', 'Book', 'Copy', 'Due Date', 'Days Overdue', 'Fine'], array_map(
            fn(array $r): array => [
                (string) $r['borrowing_id'],
                (string) $r['student_name'],
                (string) $r['book_title'],
                (string) $r['copy_code'],
                format_date((string) $r['due_date']),
                (string) $r['days_overdue'],
                $r['fine_id'] ? fines_format((float) $r['fine_amount'], 'KES') . ($r['fine_status'] === 'settled' ? ' (settled)' : ' (outstanding)') : '',
            ],
            $data['rows']
        ));
        break;
    case 'catalogue':
        $data = reporting_catalogue($pdo);
        reporting_export_csv('catalogue_report.csv', ['Category', 'Active Books'], array_map(
            fn(array $r): array => [(string) $r['category_name'], (string) $r['books']],
            $data['books_by_category']
        ));
        break;
    case 'fines':
        $data = reporting_fines($pdo, $_GET, 1, 100000);
        reporting_export_csv('fines_report.csv', ['Fine ID', 'Borrowing ID', 'Status', 'Days Overdue', 'Rate/Day', 'Amount', 'Assessed At', 'Settled By', 'Settled At'], array_map(
            fn(array $r): array => [
                (string) $r['fine_id'],
                (string) $r['borrowing_id'],
                (string) $r['status'],
                (string) $r['days_overdue'],
                fines_format((float) $r['rate_per_day'], 'KES'),
                fines_format((float) $r['amount'], 'KES'),
                $r['assessed_at'] !== null ? format_datetime((string) $r['assessed_at']) : '',
                (string) ($r['settled_by_id'] ?? ''),
                $r['settled_at'] !== null ? format_datetime((string) $r['settled_at']) : '',
            ],
            $data['rows']
        ));
        break;
}
