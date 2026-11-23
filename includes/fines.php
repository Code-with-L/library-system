<?php
/**
 * Fine calculation, display and settlement for library borrowings.
 *
 * Approved policy: currency KES, standard loan period 14 days, daily overdue
 * rate KES 10, timezone Africa/Nairobi.
 *
 * MariaDB cannot use CHECK constraints referencing FK columns, so fine state
 * enforcement for settlement is application-level inside transactions.
 */

class FinesConflict extends RuntimeException
{
}

function fines_config(PDO $pdo): array
{
    $settings = get_settings($pdo);
    return [
        'rate_per_day' => (float) $settings['fine_per_day'],
        'currency'     => (string) $settings['currency_code'],
        'loan_days'    => (int) $settings['loan_period_days'],
    ];
}

function fines_format(float|int|string|null $amount, string $currency = 'KES'): string
{
    return $currency . ' ' . number_format((float) ($amount ?? 0), 2, '.', ',');
}

function fines_amount_string(int $daysOverdue, float $ratePerDay): string
{
    return number_format($daysOverdue * $ratePerDay, 2, '.', ',');
}

function fines_get(PDO $pdo, int $fineId): ?array
{
    $statement = $pdo->prepare(
        'SELECT f.*, b.user_id, b.book_copy_id, b.status AS borrowing_status, b.return_date, b.due_date,
                bc.copy_code, u.full_name AS student_name
           FROM fines f
           JOIN borrowings b ON b.id = f.borrowing_id
           JOIN book_copies bc ON bc.id = b.book_copy_id
           LEFT JOIN users u ON u.id = b.user_id
          WHERE f.id = :id'
    );
    $statement->execute(['id' => $fineId]);
    return $statement->fetch() ?: null;
}

function fines_fetch(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $where = [];
    $params = [];

    if (!empty($filters['status']) && in_array($filters['status'], ['outstanding', 'settled'], true)) {
        $where[] = 'f.status = ?';
        $params[] = (string) $filters['status'];
    }

    if (!empty($filters['search'])) {
        $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['search']);
        $like = '%' . $term . '%';
        $where[] = "(b.id LIKE ? ESCAPE '!' OR u.full_name LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!' OR bk.title LIKE ? ESCAPE '!')";
        $params = array_merge($params, [$like, $like, $like, $like]);
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $statement = $pdo->prepare(
        'SELECT f.*, b.id AS borrowing_id, bk.title AS book_title, b.due_date, b.return_date, b.status AS borrowing_status,
                bc.copy_code, u.full_name AS student_name, u.email AS student_email
           FROM fines f
           JOIN borrowings b ON b.id = f.borrowing_id
           JOIN book_copies bc ON bc.id = b.book_copy_id
           JOIN books bk ON bk.id = bc.book_id
           JOIN users u ON u.id = b.user_id' . $whereSql .
        ' ORDER BY f.id DESC LIMIT ?'
    );
    $statement->execute(array_merge($params, [$limit]));
    return (array) $statement->fetchAll();
}

function fines_fetch_student(PDO $pdo, int $studentId, array $filters = [], int $limit = 100): array
{
    $where = ['b.user_id = :user_id'];
    $params = ['user_id' => $studentId];

    if (!empty($filters['status']) && in_array($filters['status'], ['outstanding', 'settled'], true)) {
        $where[] = 'f.status = :status';
        $params['status'] = (string) $filters['status'];
    }

    $whereSql = ' WHERE ' . implode(' AND ', $where);
    $statement = $pdo->prepare(
        'SELECT f.*, b.id AS borrowing_id, bk.title AS book_title, b.due_date, b.return_date, b.status AS borrowing_status,
                bc.copy_code, u.full_name AS student_name
           FROM fines f
           JOIN borrowings b ON b.id = f.borrowing_id
           JOIN book_copies bc ON bc.id = b.book_copy_id
           JOIN books bk ON bk.id = bc.book_id
           JOIN users u ON u.id = b.user_id' . $whereSql .
        ' ORDER BY f.id DESC LIMIT :limit'
    );
    $statement->execute($params + ['limit' => $limit]);
    return (array) $statement->fetchAll();
}

function fines_outstanding_stats(PDO $pdo, int $studentId): array
{
    $statement = $pdo->prepare(
        'SELECT
                COALESCE(SUM(CASE WHEN f.status = \'outstanding\' THEN f.amount ELSE 0 END), 0) AS outstanding_balance,
                COALESCE(SUM(CASE WHEN f.status = \'outstanding\' THEN 1 ELSE 0 END), 0) AS outstanding_count,
                COALESCE(SUM(CASE WHEN f.status = \'settled\' THEN 1 ELSE 0 END), 0) AS settled_count
           FROM fines f
           JOIN borrowings b ON b.id = f.borrowing_id
          WHERE b.user_id = :user_id'
    );
    $statement->execute(['user_id' => $studentId]);
    return (array) $statement->fetch() ?: [
        'outstanding_balance' => 0, 'outstanding_count' => 0, 'settled_count' => 0,
    ];
}

function fines_outstanding_total_all(PDO $pdo): array
{
    $statement = $pdo->prepare(
        'SELECT
                COALESCE(SUM(CASE WHEN status = \'outstanding\' THEN amount ELSE 0 END), 0) AS outstanding_balance,
                COALESCE(SUM(CASE WHEN status = \'outstanding\' THEN 1 ELSE 0 END), 0) AS outstanding_count,
                COALESCE(SUM(CASE WHEN status = \'settled\' THEN 1 ELSE 0 END), 0) AS settled_count
           FROM fines'
    );
    $statement->execute();
    return (array) $statement->fetch() ?: [
        'outstanding_balance' => 0, 'outstanding_count' => 0, 'settled_count' => 0,
    ];
}

function fines_active_overdue_days(PDO $pdo, int $borrowingId): int
{
    $statement = $pdo->prepare(
        'SELECT DATEDIFF(CURDATE(), due_date) AS days
           FROM borrowings
          WHERE id = :id
            AND status = \'active\'
            AND due_date < CURDATE()'
    );
    $statement->execute(['id' => $borrowingId]);
    $days = (int) $statement->fetchColumn();
    return max(0, $days);
}

function fines_returned_days(string $returnDate, string $dueDate): int
{
    try {
        $days = (new DateTimeImmutable($dueDate))
            ->diff(new DateTimeImmutable($returnDate))
            ->format('%r%a');
    } catch (Throwable $exception) {
        $days = 0;
    }
    return max(0, (int) $days);
}

function fines_existing_for_borrowing(PDO $pdo, int $borrowingId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM fines WHERE borrowing_id = :id LIMIT 1');
    $statement->execute(['id' => $borrowingId]);
    $row = $statement->fetch();
    return $row === false ? null : (array) $row;
}

function fines_lock_borrowing(PDO $pdo, int $borrowingId): ?array
{
    $statement = $pdo->prepare(
        'SELECT b.*
           FROM borrowings b
          WHERE b.id = :id
            FOR UPDATE'
    );
    $statement->execute(['id' => $borrowingId]);
    return (array) $statement->fetch() ?: null;
}

function fines_assess_return_for_borrowing(PDO $pdo, int $borrowingId, ?string $returnDate = null): int
{
    $borrowing = fines_lock_borrowing($pdo, $borrowingId);
    if ($borrowing === null || (string) $borrowing['status'] !== 'returned') {
        return 0;
    }

    $effectiveReturnDate = $returnDate ?? $borrowing['return_date'];
    if ($effectiveReturnDate === null || $effectiveReturnDate === '') {
        return 0;
    }

    $existing = fines_existing_for_borrowing($pdo, $borrowingId);
    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $daysOverdue = fines_returned_days($effectiveReturnDate, (string) $borrowing['due_date']);
    if ($daysOverdue <= 0) {
        return 0;
    }

    $config = fines_config($pdo);
    $amount = (float) fines_amount_string($daysOverdue, $config['rate_per_day']);

    $insert = $pdo->prepare(
        'INSERT INTO fines
            (borrowing_id, days_overdue, rate_per_day, amount, status, assessed_at, calculated_at)
         VALUES
            (:borrowing_id, :days_overdue, :rate_per_day, :amount, \'outstanding\', NOW(), NOW())'
    );
    $insert->execute([
        'borrowing_id' => $borrowingId,
        'days_overdue' => $daysOverdue,
        'rate_per_day' => $config['rate_per_day'],
        'amount'       => $amount,
    ]);

    $fineId = (int) $pdo->lastInsertId();
    return $fineId;
}

function fines_assess_active_overdue_for_borrowing(PDO $pdo, int $borrowingId): int
{
    $borrowing = fines_lock_borrowing($pdo, $borrowingId);
    if ($borrowing === null || (string) $borrowing['status'] !== 'active') {
        return 0;
    }

    $daysOverdue = fines_active_overdue_days($pdo, $borrowingId);
    if ($daysOverdue <= 0) {
        return 0;
    }

    $existing = fines_existing_for_borrowing($pdo, $borrowingId);
    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $config = fines_config($pdo);
    $amount = (float) fines_amount_string($daysOverdue, $config['rate_per_day']);
    $insert = $pdo->prepare(
        'INSERT INTO fines
            (borrowing_id, days_overdue, rate_per_day, amount, status, assessed_at, calculated_at)
         VALUES
            (:borrowing_id, :days_overdue, :rate_per_day, :amount, \'outstanding\', NOW(), NOW())'
    );
    $insert->execute([
        'borrowing_id' => $borrowingId,
        'days_overdue' => $daysOverdue,
        'rate_per_day' => $config['rate_per_day'],
        'amount'       => $amount,
    ]);

    return (int) $pdo->lastInsertId();
}

function fines_settle(PDO $pdo, int $fineId, int $staffId): int
{
    $pdo->beginTransaction();
    try {
        $borrowingStatement = $pdo->prepare('SELECT id FROM borrowings WHERE id = (SELECT borrowing_id FROM fines WHERE id = :id) FOR UPDATE');
        $borrowingStatement->execute(['id' => $fineId]);
        if ($borrowingStatement->fetch() === false) {
            $pdo->rollBack();
            throw new FinesConflict('fine_not_found');
        }

        $statement = $pdo->prepare(
            'SELECT id, status, amount
               FROM fines
              WHERE id = :id
                FOR UPDATE'
        );
        $statement->execute(['id' => $fineId]);
        $fine = $statement->fetch();
        if ($fine === false) {
            $pdo->rollBack();
            throw new FinesConflict('fine_not_found');
        }
        if ((string) $fine['status'] !== 'outstanding') {
            $pdo->rollBack();
            throw new FinesConflict('fine_already_settled');
        }
        if ((float) $fine['amount'] <= 0) {
            $pdo->rollBack();
            throw new FinesConflict('fine_amount_invalid');
        }

        $update = $pdo->prepare(
            'UPDATE fines
                SET status = \'settled\', settled_by_id = :settled_by, settled_at = NOW()
              WHERE id = :id
                AND status = \'outstanding\''
        );
        $update->execute(['id' => $fineId, 'settled_by' => $staffId]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            throw new FinesConflict('settlement_conflict');
        }

        $pdo->commit();
        return $fineId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
