<?php
/**
 * Phase 5 reporting service helpers.
 *
 * Separates date/filter validation, aggregate reporting, CSV export and
 * settings validation from page presentation.
 */

/**
 * Validate a custom Y-m-d date range.
 *
 * @param array<string, mixed> $filters
 */
function reporting_parse_range(array $filters): array
{
    $from = trim((string) ($filters['from'] ?? ''));
    $to = trim((string) ($filters['to'] ?? ''));
    $errors = [];

    if ($from !== '') {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        if ($dt === false || $dt->format('Y-m-d') !== $from) {
            $errors['from'] = 'Enter a valid start date (YYYY-MM-DD).';
        }
    }

    if ($to !== '') {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($dt === false || $dt->format('Y-m-d') !== $to) {
            $errors['to'] = 'Enter a valid end date (YYYY-MM-DD).';
        }
    }

    if ($errors === [] && $from !== '' && $to !== '' && $from > $to) {
        $errors['range'] = 'End date must be after start date.';
    }

    return ['from' => $from, 'to' => $to, 'errors' => $errors];
}

/**
 * Whitelisted date presets with concrete boundaries resolved in Africa/Nairobi.
 *
 * @return array{from: string, to: string}
 */
function reporting_preset_range(string $scope): array
{
    $today = date('Y-m-d');
    return match ($scope) {
        'today'         => ['from' => $today, 'to' => $today],
        'last7'         => ['from' => date('Y-m-d', strtotime('-6 days')), 'to' => $today],
        'last30'        => ['from' => date('Y-m-d', strtotime('-29 days')), 'to' => $today],
        'current_month' => ['from' => date('Y-m-d', strtotime('first day of this month')), 'to' => $today],
        default         => ['from' => '', 'to' => ''],
    };
}

/**
 * Apply a whitelisted preset unless explicit from/to dates were given.
 *
 * @param array<string, mixed> $filters
 * @return array<string, mixed>
 */
function reporting_resolve_preset(array $filters): array
{
    $scope = trim((string) ($filters['preset'] ?? ''));
    if ($scope === '') {
        return $filters;
    }
    $preset = reporting_preset_range($scope);
    if ($preset['from'] === '' && $preset['to'] === '') {
        return $filters;
    }
    $resolved = $filters;
    if ((string) ($resolved['from'] ?? '') === '') {
        $resolved['from'] = $preset['from'];
    }
    if ((string) ($resolved['to'] ?? '') === '') {
        $resolved['to'] = $preset['to'];
    }
    unset($resolved['preset']);
    return $resolved;
}

/**
 * Whitelist the borrowing report filters.
 *
 * @param array<string, mixed> $filters
 * @return array{status: string, overdue: bool, studentId: ?int, from: string, to: string, errors: array<string, string>}
 */
function reporting_borrowing_filters(array $filters): array
{
    $status = (string) ($filters['status'] ?? 'all');
    $overdue = (bool) ($filters['overdue'] ?? false);
    $studentId = filter_var($filters['student_id'] ?? '', FILTER_VALIDATE_INT);
    if ($studentId === false || $studentId === null || $studentId < 1) {
        $studentId = null;
    }
    $parsed = reporting_parse_range($filters);
    $errors = $parsed['errors'];

    if (!in_array($status, ['all', 'active', 'returned'], true)) {
        $status = 'all';
    }

    return [
        'status' => $status,
        'overdue' => $overdue,
        'studentId' => $studentId,
        'from' => $parsed['from'],
        'to' => $parsed['to'],
        'errors' => $errors,
    ];
}

/**
 * Fetch paginated borrowing records with authoritative aggregate-safe joins.
 *
 * @param array<string, mixed> $filters
 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int}
 */
function reporting_borrowings(PDO $pdo, array $filters, int $page = 1, int $perPage = 50): array
{
    $params = [];
    $where = ['1 = 1'];
    if ((string) $filters['status'] !== 'all') {
        $where[] = 'br.status = :status';
        $params['status'] = (string) $filters['status'];
    }
    if ($filters['overdue']) {
        $where[] = "br.status = 'active' AND br.due_date < CURDATE()";
    }
    if ($filters['studentId'] !== null) {
        $where[] = 'br.user_id = :student_id';
        $params['student_id'] = (int) $filters['studentId'];
    }
    if ($filters['from'] !== '' && $filters['to'] !== '') {
        $where[] = '(br.due_date BETWEEN :from AND :to)';
        $params['from'] = (string) $filters['from'];
        $params['to'] = (string) $filters['to'];
    }
    $whereSql = implode(' AND ', $where);

    $count = $pdo->prepare('SELECT COUNT(*) FROM borrowings br WHERE ' . $whereSql);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    $offset = ($page - 1) * $perPage;

    $rows = $pdo->prepare(
        'SELECT br.id AS borrowing_id, br.user_id, br.user_id AS student_id,
                u.full_name AS student_name, u.email AS student_email,
                b.title AS book_title, bc.copy_code AS copy_code,
                br.issue_date, br.due_date, br.return_date, br.status AS status,
                CASE WHEN br.due_date < CURDATE() AND br.status = \'active\'
                     THEN GREATEST(DATEDIFF(CURDATE(), br.due_date), 0) ELSE 0 END AS days_overdue,
                f.id AS fine_id, f.days_overdue AS fine_days_overdue, f.amount AS fine_amount,
                f.status AS fine_status
           FROM borrowings br
           INNER JOIN book_copies bc ON bc.id = br.book_copy_id
           INNER JOIN books b ON b.id = bc.book_id
           LEFT JOIN users u ON u.id = br.user_id
           LEFT JOIN fines f ON f.borrowing_id = br.id AND f.status = \'outstanding\'
          WHERE ' . $whereSql . '
          ORDER BY br.id DESC
          LIMIT :limit OFFSET :offset'
    );
    $rows->execute($params + ['limit' => $perPage, 'offset' => $offset]);
    return ['rows' => (array) $rows->fetchAll(), 'total' => $total, 'pages' => $pages];
}

/**
 * Fetch overdue borrowing records with actual due dates and outstanding fines.
 *
 * @return array{rows: array<int, array<string, mixed>>, total: int}
 */
function reporting_overdue(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $parsed = reporting_parse_range($filters);
    $params = [];
    $where = ['br.status = \'active\' AND br.due_date < CURDATE()'];
    if ($parsed['from'] !== '' && $parsed['to'] !== '') {
        $where[] = '(br.due_date BETWEEN :from AND :to)';
        $params['from'] = (string) $parsed['from'];
        $params['to'] = (string) $parsed['to'];
    }
    $rows = $pdo->prepare(
        'SELECT br.id AS borrowing_id, br.user_id AS student_id,
                u.full_name AS student_name, u.email AS student_email,
                b.title AS book_title, bc.copy_code AS copy_code,
                br.due_date, br.return_date, br.status AS status,
                GREATEST(DATEDIFF(CURDATE(), br.due_date), 0) AS days_overdue,
                f.id AS fine_id, f.amount AS fine_amount, f.status AS fine_status
           FROM borrowings br
           INNER JOIN book_copies bc ON bc.id = br.book_copy_id
           INNER JOIN books b ON b.id = bc.book_id
           LEFT JOIN users u ON u.id = br.user_id
           LEFT JOIN fines f ON f.borrowing_id = br.id AND f.status = \'outstanding\'
          WHERE ' . implode(' AND ', $where) .
        ' ORDER BY br.due_date ASC, br.id DESC
          LIMIT :limit'
    );
    $rows->execute($params + ['limit' => $limit]);
    $fetched = (array) $rows->fetchAll();
    return ['rows' => $fetched, 'total' => count($fetched)];
}

/**
 * Catalogue report aggregates using the authoritative copy allocation model.
 *
 * @return array<string, mixed>
 */
function reporting_catalogue(PDO $pdo): array
{
    $titles = (int) $pdo->query("SELECT COUNT(*) FROM books WHERE status = 'active'")->fetchColumn();
    $copies = (int) $pdo->query('SELECT COUNT(*) FROM book_copies')->fetchColumn();
    $statuses = [];
    foreach ($pdo->prepare('SELECT status, COUNT(*) AS n FROM book_copies GROUP BY status')->fetchAll() as $row) {
        $statuses[(string) $row['status']] = (int) $row['n'];
    }
    foreach (['available', 'reserved', 'issued', 'maintenance', 'archived'] as $status) {
        $statuses[$status] = (int) ($statuses[$status] ?? 0);
    }

    $booksByCategory = [];
    foreach ($pdo->prepare('SELECT c.name AS category_name, COUNT(b.id) AS books FROM books b JOIN categories c ON c.id = b.category_id WHERE b.status = \'active\' GROUP BY c.id, c.name ORDER BY c.name')->fetchAll() as $row) {
        $booksByCategory[] = ['category_name' => (string) $row['category_name'], 'books' => (int) $row['books']];
    }

    return [
        'titles' => $titles,
        'copies' => $copies,
        'statuses' => $statuses,
        'books_by_category' => $booksByCategory,
    ];
}

/**
 * Fines report with authoritative totals and detailed rows.
 *
 * @param array<string, mixed> $filters
 * @return array{rows: array<int, array<string, mixed>>, total_rows: int, outstanding_total: float, outstanding_count: int, settled_total: float, settled_count: int}
 */
function reporting_fines(PDO $pdo, array $filters, int $page = 1, int $perPage = 50): array
{
    $status = (string) ($filters['status'] ?? 'all');
    $parsed = reporting_parse_range($filters);
    $params = [];
    $where = ['1 = 1'];
    if ($status !== 'all') {
        $where[] = 'f.status = :status';
        $params['status'] = $status;
    }
    if ($parsed['from'] !== '' && $parsed['to'] !== '') {
        $where[] = '(br.due_date BETWEEN :from AND :to)';
        $params['from'] = (string) $parsed['from'];
        $params['to'] = (string) $parsed['to'];
    }
    $whereSql = implode(' AND ', $where);

    $totalsStmt = $pdo->prepare(
        'SELECT
             COALESCE(SUM(CASE WHEN f.status = \'outstanding\' THEN f.amount ELSE 0 END), 0) AS outstanding_total,
             COALESCE(SUM(CASE WHEN f.status = \'outstanding\' THEN 1 ELSE 0 END), 0) AS outstanding_count,
             COALESCE(SUM(CASE WHEN f.status = \'settled\' THEN f.amount ELSE 0 END), 0) AS settled_total,
             COALESCE(SUM(CASE WHEN f.status = \'settled\' THEN 1 ELSE 0 END), 0) AS settled_count
         FROM fines f
         INNER JOIN borrowings br ON br.id = f.borrowing_id
         WHERE ' . $whereSql
    );
    $totalsStmt->execute($params);
    $totals = (array) $totalsStmt->fetch() ?: ['outstanding_total' => 0, 'outstanding_count' => 0, 'settled_total' => 0, 'settled_count' => 0];

    $rows = $pdo->prepare(
        'SELECT f.id AS fine_id, f.borrowing_id, f.status AS status, f.days_overdue, f.rate_per_day, f.amount,
                f.assessed_at, f.settled_at, f.settled_by_id,
                u.full_name AS student_name, u.email AS student_email,
                b.title AS book_title, bc.copy_code AS copy_code, br.due_date AS due_date,
                br.status AS borrowing_status
           FROM fines f
           INNER JOIN borrowings br ON br.id = f.borrowing_id
           INNER JOIN book_copies bc ON bc.id = br.book_copy_id
           INNER JOIN books b ON b.id = bc.book_id
           LEFT JOIN users u ON u.id = br.user_id' . ($whereSql !== '' ? ' WHERE ' . $whereSql : '') .
        ' ORDER BY f.id DESC LIMIT :limit OFFSET :offset'
    );
    $rows->execute($params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]);
    $fetched = (array) $rows->fetchAll();
    return [
        'rows' => $fetched,
        'total_rows' => count($fetched),
        'outstanding_total' => (float) ($totals['outstanding_total'] ?? 0),
        'outstanding_count' => (int) ($totals['outstanding_count'] ?? 0),
        'settled_total' => (float) ($totals['settled_total'] ?? 0),
        'settled_count' => (int) ($totals['settled_count'] ?? 0),
    ];
}

/**
 * Admin system report: role totals.
 *
 * @return array<int, array<string, mixed>>
 */
function reporting_admin_users(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT r.name AS role_name, COUNT(u.id) AS total, SUM(u.status = \'active\') AS active_count
           FROM roles r
           LEFT JOIN users u ON u.role_id = r.id
          GROUP BY r.id, r.name
          ORDER BY r.id'
    );
    return (array) $rows->fetchAll();
}

/**
 * Activity log report with explicit whitelists and pagination.
 *
 * @param array<string, mixed> $filters
 * @return array{rows: array<int, array<string, mixed>>, total: int}
 */
function reporting_activity(PDO $pdo, array $filters, int $page = 1, int $perPage = 100): array
{
    $action = (string) ($filters['action'] ?? '');
    $userId = filter_var($filters['user_id'] ?? '', FILTER_VALIDATE_INT);
    $entityId = filter_var($filters['entity_id'] ?? '', FILTER_VALIDATE_INT);
    if ($userId === false || $userId === null || $userId < 1) { $userId = null; }
    if ($entityId === false || $entityId === null || $entityId < 1) { $entityId = null; }

    $where = ['1 = 1'];
    $params = [];
    if ($action !== '') {
        $where[] = 'a.action = :action';
        $params['action'] = $action;
    }
    if ($userId !== null) {
        $where[] = 'a.user_id = :user_id';
        $params['user_id'] = $userId;
    }
    if ($entityId !== null) {
        $where[] = 'a.entity_id = :entity_id';
        $params['entity_id'] = $entityId;
    }
    $parsed = reporting_parse_range($filters);
    if ($parsed['from'] !== '' && $parsed['to'] !== '') {
        $where[] = 'a.created_at BETWEEN :from AND :to';
        $params['from'] = (string) $parsed['from'] . ' 00:00:00';
        $params['to'] = (string) $parsed['to'] . ' 23:59:59';
    }
    $whereSql = implode(' AND ', $where);

    $count = $pdo->prepare('SELECT COUNT(*) FROM activity_logs a WHERE ' . $whereSql);
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $rows = $pdo->prepare(
        'SELECT a.id AS log_id, a.action, a.entity_type, a.entity_id, a.description, a.created_at, u.full_name AS actor_name
           FROM activity_logs a
           LEFT JOIN users u ON u.id = a.user_id' . ($whereSql !== '' ? ' WHERE ' . $whereSql : '') .
        ' ORDER BY a.id DESC LIMIT :limit OFFSET :offset'
    );
    $rows->execute($params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]);
    return ['rows' => (array) $rows->fetchAll(), 'total' => $total];
}

/**
 * Safe CSV cell with formula-injection protection.
 */
function reporting_csv_cell(mixed $value): string
{
    $cell = $value === null ? '' : (string) $value;
    if ($cell !== '' && strpos('=+-@', $cell[0]) !== false) {
        $cell = "'" . $cell;
    }
    return $cell;
}

/**
 * CSV builder using RFC-4180-ish quoting and newline sanitization.
 *
 * @param array<int, string> $header
 * @param array<int, array<string, mixed>> $rows
 */
function reporting_csv_build(array $header, array $rows): string
{
    $output = fopen('php://temp', 'r+');
    foreach (array_merge([array_map(fn ($h) => reporting_csv_cell($h), $header)], array_map(
        fn (array $row) => array_map(fn ($v) => reporting_csv_cell($v), $row),
        $rows
    )) as $line) {
        fputcsv($output, $line, ',', '"', '');
    }
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    return (string) str_replace(["\r\n", "\n", "\r"], PHP_EOL, $csv);
}

/**
 * Export CSV with safe download headers.
 */
function reporting_export_csv(string $filename, array $header, array $rows): void
{
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . e($filename) . '"');
        header('X-Content-Type-Options: nosniff');
    }
    echo reporting_csv_build($header, $rows);
    exit;
}

/**
 * Validate settings updates against the approved schema.
 *
 * @param array<string, mixed> $input
 * @return array{errors: array<string, string>, values: array<string, mixed>}
 */
function reporting_validate_settings(array $input): array
{
    $errors = [];
    $libraryName = trim((string) ($input['library_name'] ?? ''));
    $loanPeriodDays = filter_var($input['loan_period_days'] ?? '', FILTER_VALIDATE_INT);
    $finePerDay = $input['fine_per_day'] ?? '';

    if (mb_strlen($libraryName) < 2 || mb_strlen($libraryName) > 150) {
        $errors['library_name'] = 'Library name must be 2-150 characters.';
    }
    if ($loanPeriodDays === false || $loanPeriodDays < 1 || $loanPeriodDays > 90) {
        $errors['loan_period_days'] = 'Loan period days must be between 1 and 90.';
    } else {
        $finePerDay = trim((string) $finePerDay);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $finePerDay) || (float) $finePerDay < 0) {
            $errors['fine_per_day'] = 'Daily fine rate must be a non-negative decimal.';
        }
    }

    return [
        'errors' => $errors,
        'values' => [
            'library_name' => $libraryName,
            'loan_period_days' => $errors === [] ? (int) $loanPeriodDays : (int) ($input['loan_period_days'] ?? 0),
            'fine_per_day' => $errors === [] ? $finePerDay : null,
        ],
    ];
}

/**
 * Update approved settings and log the change.
 *
 * @param array{errors: array<string, string>, values: array<string, mixed>} $validation
 */
function reporting_update_settings(PDO $pdo, array $validation, int $staffId): bool
{
    if ($validation['errors'] !== []) {
        return false;
    }
    $statement = $pdo->prepare('UPDATE library_settings SET library_name = :library_name, loan_period_days = :loan_period_days, fine_per_day = :fine_per_day, updated_by = :updated_by WHERE id = 1');
    $statement->execute([
        'library_name' => $validation['values']['library_name'],
        'loan_period_days' => $validation['values']['loan_period_days'],
        'fine_per_day' => $validation['values']['fine_per_day'],
        'updated_by' => $staffId,
    ]);
    log_activity($pdo, $staffId, 'library_settings_update', 'settings', 1, 'Updated library settings.');
    return true;
}
