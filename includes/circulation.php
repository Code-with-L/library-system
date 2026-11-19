<?php
/**
 * Circulation transaction rules for the Phase 3 borrowing state machine.
 *
 * These functions implement the exact Rules A–F and the global lock order from
 * PHASE2_CLOSEOUT_REVIEW.md §5.4. They are application-level enforcement
 * because MariaDB 10.11 cannot use generated columns/CHECK constraints that
 * reference foreign-key columns. A separate schema amendment documents the
 * strategy.
 *
 * Global lock order: `book_copies → borrow_requests → borrowings → fines`.
 * No circulation operation should lock rows in the opposite order.
 */

class CirculationConflict extends RuntimeException
{
    private string $conflictCode;

    public function __construct(string $conflictCode, string $message)
    {
        parent::__construct($message);
        $this->conflictCode = $conflictCode;
    }

    public function getConflictCode(): string
    {
        return $this->conflictCode;
    }
}

function c_assert_row_count(PDOStatement $statement, int $expected, string $context): void
{
    $actual = $statement->rowCount();
    if ($actual !== $expected) {
        throw new CirculationConflict(
            'row_count_mismatch',
            sprintf('%s expected %d row(s), got %d; transaction rolled back.', $context, $expected, $actual)
        );
    }
}

function c_lock_copy_for_update(PDO $pdo, int $copyId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, book_id, copy_code, status
           FROM book_copies
          WHERE id = :id
            FOR UPDATE'
    );
    $statement->execute(['id' => $copyId]);
    $copy = $statement->fetch();
    return $copy ?: null;
}

function c_lock_request_for_update(PDO $pdo, int $requestId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, user_id, book_id, assigned_copy_id, reserved_copy_id, status
           FROM borrow_requests
          WHERE id = :id
            FOR UPDATE'
    );
    $statement->execute(['id' => $requestId]);
    $request = $statement->fetch();
    return $request ?: null;
}

function c_lock_borrowing_for_update(PDO $pdo, int $borrowingId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, request_id, book_copy_id, user_id, issue_date, due_date, status, active_copy_id
           FROM borrowings
          WHERE id = :id
            FOR UPDATE'
    );
    $statement->execute(['id' => $borrowingId]);
    $borrowing = $statement->fetch();
    return $borrowing ?: null;
}

function c_lock_request_without_id_for_update(PDO $pdo, int $requestId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, user_id, book_id, status
           FROM borrow_requests
          WHERE id = :id
            FOR UPDATE'
    );
    $statement->execute(['id' => $requestId]);
    $request = $statement->fetch();
    return $request ?: null;
}

/**
 * Rule A — approve a pending request.
 *
 * Locks the copy first, then the request. Sets `status`, `assigned_copy_id`
 * and `reserved_copy_id` in the same UPDATE.
 */
function circulation_approve_request(
    PDO $pdo,
    int $requestId,
    int $copyId,
    int $staffId,
    float $testHoldSeconds = 0.0
): void {
    $pdo->beginTransaction();

    try {
        // Lock order: book_copies → borrow_requests.
        $copy = c_lock_copy_for_update($pdo, $copyId);
        if ($copy === null) {
            throw new CirculationConflict('copy_not_found', 'Requested copy does not exist.');
        }

        $request = c_lock_request_for_update($pdo, $requestId);
        if ($request === null) {
            throw new CirculationConflict('request_not_found', 'Requested borrow request does not exist.');
        }

        if ($request['status'] !== 'pending') {
            throw new CirculationConflict('request_not_pending', 'Only a pending request can be approved.');
        }

        if ($copy['book_id'] !== $request['book_id']) {
            throw new CirculationConflict('copy_does_not_match_book', 'The copy does not belong to the requested title.');
        }

        if ($copy['status'] !== 'available') {
            throw new CirculationConflict('copy_not_available', 'The copy is not available.');
        }

        if ($request['reserved_copy_id'] !== null && (int) $request['reserved_copy_id'] !== $copyId) {
            throw new CirculationConflict('request_already_reserved', 'The request is already reserved to another copy.');
        }

        $existing = $pdo->prepare('SELECT id FROM borrow_requests WHERE reserved_copy_id = :copy_id LIMIT 1');
        $existing->execute(['copy_id' => $copyId]);
        if ($existing->fetch() !== false) {
            throw new CirculationConflict('copy_already_reserved', 'The copy already has an approved reservation.');
        }

        if ($testHoldSeconds > 0.0) {
            usleep((int) ($testHoldSeconds * 1_000_000));
        }

        $setCopy = $pdo->prepare(
            "UPDATE book_copies
                SET status = :status
              WHERE id = :id AND status = :expected_status"
        );
        $setCopy->execute([
            'status'          => 'reserved',
            'id'              => $copyId,
            'expected_status' => 'available',
        ]);
        c_assert_row_count($setCopy, 1, 'Approve: copy status update');

        $setRequest = $pdo->prepare(
            'UPDATE borrow_requests
                SET status = :status,
                    assigned_copy_id = :assigned_copy_id,
                    reserved_copy_id = :reserved_copy_id,
                    reviewed_by = :staff_id,
                    reviewed_at = NOW()
              WHERE id = :id AND status = :expected_status'
        );
        $setRequest->execute([
            'status'           => 'approved',
            'assigned_copy_id' => $copyId,
            'reserved_copy_id' => $copyId,
            'staff_id'         => $staffId,
            'id'               => $requestId,
            'expected_status'  => 'pending',
        ]);
        c_assert_row_count($setRequest, 1, 'Approve: request status update');

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Rule B — reject or cancel a request.
 */
function circulation_close_request(PDO $pdo, int $requestId, string $newStatus, int $staffId, ?string $decisionNote = null): void
{
    if (!in_array($newStatus, ['rejected', 'cancelled'], true)) {
        throw new CirculationConflict('invalid_new_status', 'Request can only be closed as rejected or cancelled.');
    }

    $pdo->beginTransaction();

    try {
        // Discover the reservation before locking so the expected copy can be
        // locked first under the global lock order.
        $before = $pdo->prepare('SELECT status, reserved_copy_id FROM borrow_requests WHERE id = :id');
        $before->execute(['id' => $requestId]);
        $beforeRow = $before->fetch();

        if ($beforeRow === false) {
            throw new CirculationConflict('request_not_found', 'Requested borrow request does not exist.');
        }

        if (!in_array($beforeRow['status'], ['pending', 'approved'], true)) {
            throw new CirculationConflict('request_not_closeable', 'Only pending or approved requests can be closed.');
        }

        $reservedCopyId = $beforeRow['reserved_copy_id'] === null ? null : (int) $beforeRow['reserved_copy_id'];

        if ($reservedCopyId !== null) {
            $copy = c_lock_copy_for_update($pdo, $reservedCopyId);
            if ($copy === null) {
                throw new CirculationConflict('reserved_copy_not_found', 'The reserved copy does not exist.');
            }
            if ($copy['status'] === 'reserved') {
                $setCopy = $pdo->prepare(
                    'UPDATE book_copies
                        SET status = :status
                      WHERE id = :id AND status = :expected_status'
                );
                $setCopy->execute([
                    'status'          => 'available',
                    'id'              => $reservedCopyId,
                    'expected_status' => 'reserved',
                ]);
                c_assert_row_count($setCopy, 1, 'Close: copy status update');
            } else {
                throw new CirculationConflict('reserved_copy_not_reserved', 'The reserved copy is no longer reserved.');
            }
        }

        $request = c_lock_request_for_update($pdo, $requestId);
        if ($request === null) {
            throw new CirculationConflict('request_not_found', 'Requested borrow request does not exist.');
        }

        if (!in_array($request['status'], ['pending', 'approved'], true)) {
            throw new CirculationConflict('request_not_closeable', 'Only pending or approved requests can be closed.');
        }

        if ($reservedCopyId !== null && (int) $request['reserved_copy_id'] !== $reservedCopyId) {
            throw new CirculationConflict('request_reservation_changed', 'The request reservation changed while closing.');
        }

        $setRequest = $pdo->prepare(
            'UPDATE borrow_requests
                SET status = :status,
                    reserved_copy_id = NULL,
                    decision_note = :decision_note,
                    reviewed_by = :reviewer_id,
                    reviewed_at = NOW()
              WHERE id = :id
                AND status IN (\'pending\', \'approved\')'
        );
        $setRequest->execute([
            'status'        => $newStatus,
            'decision_note' => $decisionNote,
            'reviewer_id'   => $staffId,
            'id'            => $requestId,
        ]);
        c_assert_row_count($setRequest, 1, 'Close: request status update');

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Rule C — fulfil an approved request by issuing the copy.
 *
 * Returns the new borrowings.id.
 */
function circulation_fulfil_request(PDO $pdo, int $requestId, int $copyId, int $staffId, int $loanDays = 14): int
{
    if ($loanDays < 1) {
        throw new CirculationConflict('invalid_loan_days', 'Loan days must be at least 1.');
    }

    $pdo->beginTransaction();

    try {
        // Lock order: book_copies → borrow_requests.
        $copy = c_lock_copy_for_update($pdo, $copyId);
        if ($copy === null) {
            throw new CirculationConflict('copy_not_found', 'Requested copy does not exist.');
        }
        if ($copy['status'] !== 'reserved') {
            throw new CirculationConflict('copy_not_reserved', 'The copy must be reserved before fulfilment.');
        }

        $request = c_lock_request_for_update($pdo, $requestId);
        if ($request === null) {
            throw new CirculationConflict('request_not_found', 'Requested borrow request does not exist.');
        }
        if ($request['status'] !== 'approved') {
            throw new CirculationConflict('request_not_approved', 'Only an approved request can be fulfilled.');
        }
        if ((int) $request['reserved_copy_id'] !== $copyId) {
            throw new CirculationConflict('request_reservation_mismatch', 'The request is not reserved to this copy.');
        }
        if ((int) $request['assigned_copy_id'] !== $copyId) {
            throw new CirculationConflict('request_assignment_mismatch', 'The request is not assigned to this copy.');
        }

        $activeLoan = $pdo->prepare('SELECT id FROM borrowings WHERE active_copy_id = :copy_id LIMIT 1');
        $activeLoan->execute(['copy_id' => $copyId]);
        if ($activeLoan->fetch() !== false) {
            throw new CirculationConflict('copy_already_active_loan', 'The copy already has an active loan.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO borrowings
                (request_id, user_id, book_copy_id, issued_by, issue_date, due_date, status, active_copy_id)
             VALUES
                (:request_id, :user_id, :book_copy_id, :staff_id, CURDATE(),
                 DATE_ADD(CURDATE(), INTERVAL :loan_days DAY), \'active\', :active_copy_id)'
        );
        $insert->execute([
            'request_id'    => $requestId,
            'user_id'       => (int) $request['user_id'],
            'book_copy_id'  => $copyId,
            'staff_id'      => $staffId,
            'active_copy_id' => $copyId,
            'loan_days'     => $loanDays,
        ]);
        $borrowingId = (int) $pdo->lastInsertId();
        if ($borrowingId === 0) {
            throw new CirculationConflict('fulfil_insert_failed', 'Could not insert the borrowing.');
        }

        $setRequest = $pdo->prepare(
            'UPDATE borrow_requests
                SET status = :status, reserved_copy_id = NULL
              WHERE id = :id AND status = :expected_status'
        );
        $setRequest->execute([
            'status'          => 'fulfilled',
            'id'              => $requestId,
            'expected_status' => 'approved',
        ]);
        c_assert_row_count($setRequest, 1, 'Fulfil: request status update');

        $setCopy = $pdo->prepare(
            'UPDATE book_copies
                SET status = :status
              WHERE id = :id AND status = :expected_status'
        );
        $setCopy->execute([
            'status'          => 'issued',
            'id'              => $copyId,
            'expected_status' => 'reserved',
        ]);
        c_assert_row_count($setCopy, 1, 'Fulfil: copy status update');

        $pdo->commit();

        return $borrowingId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Rule D — return an active borrowing.
 */
function circulation_return_borrowing(
    PDO $pdo,
    int $borrowingId,
    int $staffId,
    ?string $returnDate = null,
    string $copyStatus = 'available',
    ?string $notes = null
): void {
    $pdo->beginTransaction();

    try {
        $borrowingSelect = $pdo->prepare('SELECT id, request_id, book_copy_id, user_id, status, active_copy_id FROM borrowings WHERE id = :id');
        $borrowingSelect->execute(['id' => $borrowingId]);
        $borrowing = $borrowingSelect->fetch();
        if ($borrowing === false) {
            throw new CirculationConflict('borrowing_not_found', 'Requested borrowing does not exist.');
        }

        $copyId = (int) $borrowing['book_copy_id'];
        $copy = c_lock_copy_for_update($pdo, $copyId);
        if ($copy === null) {
            throw new CirculationConflict('copy_not_found', 'The borrowing copy does not exist.');
        }
        if ($copy['status'] !== 'issued') {
            throw new CirculationConflict('copy_not_issued', 'The copy must be issued while the borrowing is active.');
        }

        if ($borrowing['request_id'] !== null) {
            c_lock_request_without_id_for_update($pdo, (int) $borrowing['request_id']);
        }

        $borrowingLocked = c_lock_borrowing_for_update($pdo, $borrowingId);
        if ($borrowingLocked === null) {
            throw new CirculationConflict('borrowing_not_found', 'Requested borrowing does not exist.');
        }
        if ($borrowingLocked['status'] !== 'active') {
            throw new CirculationConflict('borrowing_not_active', 'Only an active borrowing can be returned.');
        }
        if ((int) $borrowingLocked['active_copy_id'] !== $copyId || (int) $borrowingLocked['book_copy_id'] !== $copyId) {
            throw new CirculationConflict('active_copy_mismatch', 'The active copy does not match the borrowing copy.');
        }

        $effectiveReturnDate = $returnDate ?? $borrowingLocked['return_date'] ?? date('Y-m-d');

        $setBorrowing = $pdo->prepare(
            'UPDATE borrowings
                SET status = \'returned\',
                    return_date = :return_date,
                    returned_by = :returned_by,
                    active_copy_id = NULL,
                    notes = :notes
              WHERE id = :id AND status = \'active\''
        );
        $setBorrowing->execute([
            'return_date' => $effectiveReturnDate,
            'returned_by' => $staffId,
            'notes'       => $notes,
            'id'          => $borrowingId,
        ]);
        c_assert_row_count($setBorrowing, 1, 'Return: borrowing status update');

        $setCopy = $pdo->prepare(
            'UPDATE book_copies
                SET status = :status
              WHERE id = :id AND status = \'issued\''
        );
        $setCopy->execute([
            'status' => $copyStatus,
            'id'     => $copyId,
        ]);
        c_assert_row_count($setCopy, 1, 'Return: copy status update');

        $fineId = fines_assess_return_for_borrowing($pdo, $borrowingId, $effectiveReturnDate);

        $pdo->commit();

        if ($fineId > 0 && function_exists('log_activity')) {
            log_activity($pdo, (int) $borrowingLocked['user_id'], 'fine_assessed', 'fine', $fineId, 'Automatically assessed fine for overdue returned copy.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Rule E — lost, damaged or withdrawn copy.
 */
function circulation_report_lost_copy(
    PDO $pdo,
    int $borrowingId,
    int $staffId,
    string $copyStatus = 'maintenance',
    ?string $notes = null
): void {
    circulation_return_borrowing($pdo, $borrowingId, $staffId, date('Y-m-d'), $copyStatus, $notes);
}

/**
 * Rule F — change a copy status while protecting active circulation state.
 */
function circulation_change_copy_status(PDO $pdo, int $copyId, string $newStatus): void
{
    $allowedStatuses = ['available', 'reserved', 'issued', 'maintenance', 'archived'];
    if (!in_array($newStatus, $allowedStatuses, true)) {
        throw new CirculationConflict('invalid_copy_status', 'Invalid copy status.');
    }

    $pdo->beginTransaction();

    try {
        $copy = c_lock_copy_for_update($pdo, $copyId);
        if ($copy === null) {
            throw new CirculationConflict('copy_not_found', 'Requested copy does not exist.');
        }

        if ($copy['status'] === $newStatus) {
            $pdo->commit();
            return;
        }

        $activeRequest = $pdo->prepare(
            "SELECT id
               FROM borrow_requests
              WHERE (reserved_copy_id = :copy_id OR assigned_copy_id = :copy_id)
                AND status = 'approved'
               LIMIT 1"
        );
        $activeRequest->execute(['copy_id' => $copyId]);
        if ($activeRequest->fetch() !== false) {
            throw new CirculationConflict('copy_has_approved_request', 'An approved request references this copy.');
        }

        $activeBorrowing = $pdo->prepare(
            'SELECT id
               FROM borrowings
              WHERE status = \'active\'
                AND (active_copy_id = :copy_id OR book_copy_id = :copy_id)
               LIMIT 1'
        );
        $activeBorrowing->execute(['copy_id' => $copyId]);
        if ($activeBorrowing->fetch() !== false) {
            throw new CirculationConflict('copy_has_active_borrowing', 'An active borrowing references this copy.');
        }

        $setCopy = $pdo->prepare('UPDATE book_copies SET status = :status WHERE id = :id');
        $setCopy->execute(['status' => $newStatus, 'id' => $copyId]);
        c_assert_row_count($setCopy, 1, 'Change copy status: copy update');

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Rule H — integrity audit queries.
 *
 * @return array<int, array{name: string, sql: string}>
 */
function circulation_audit_queries(): array
{
    return [
        [
            'name' => 'approved_request_without_copy',
            'sql'  => "SELECT id FROM borrow_requests WHERE status = 'approved' AND (reserved_copy_id IS NULL OR assigned_copy_id IS NULL)",
        ],
        [
            'name' => 'non_approved_request_with_reservation',
            'sql'  => "SELECT id FROM borrow_requests WHERE status <> 'approved' AND reserved_copy_id IS NOT NULL",
        ],
        [
            'name' => 'reservation_copy_status_disagreement',
            'sql'  => "SELECT br.id, br.reserved_copy_id, bc.status
                         FROM borrow_requests br
                         JOIN book_copies bc ON bc.id = br.reserved_copy_id
                        WHERE br.status = 'approved' AND bc.status <> 'reserved'",
        ],
        [
            'name' => 'active_borrowing_without_key_or_mismatch',
            'sql'  => "SELECT id FROM borrowings WHERE status = 'active' AND (active_copy_id IS NULL OR active_copy_id <> book_copy_id)",
        ],
        [
            'name' => 'returned_borrowing_with_active_key',
            'sql'  => "SELECT id FROM borrowings WHERE status = 'returned' AND active_copy_id IS NOT NULL",
        ],
        [
            'name' => 'active_borrowing_copy_status_disagreement',
            'sql'  => "SELECT b.id, b.book_copy_id, bc.status
                         FROM borrowings b
                         JOIN book_copies bc ON bc.id = b.book_copy_id
                        WHERE b.status = 'active' AND bc.status <> 'issued'",
        ],
        [
            'name' => 'duplicate_active_loans_by_copy',
            'sql'  => "SELECT book_copy_id, COUNT(*) AS n FROM borrowings WHERE status = 'active' GROUP BY book_copy_id HAVING n > 1",
        ],
    ];
}

/**
 * Execute all §5.4 audit queries and return violations.
 *
 * @return array<int, array<string, mixed>>
 */
function circulation_audit(PDO $pdo): array
{
    $violations = [];
    foreach (circulation_audit_queries() as $query) {
        $statement = $pdo->prepare($query['sql']);
        $statement->execute();
        while (($row = $statement->fetch()) !== false) {
            $violations[] = [
                'audit' => $query['name'],
                'row'   => $row,
            ];
        }
    }
    return $violations;
}

/**
 * Record an overdue fine in the same transaction as a return.
 *
 * @param array<string, mixed> $fine
 */
function circulation_record_fine(PDO $pdo, int $borrowingId, array $fine): void
{
    $days = (int) $fine['days_overdue'];
    $rate = (float) $fine['rate_per_day'];
    $amount = (float) $fine['amount'];
    if ($days < 1) {
        throw new CirculationConflict('invalid_fine_days', 'Fine days must be at least 1.');
    }
    if ($rate < 0) {
        throw new CirculationConflict('invalid_fine_rate', 'Fine rate cannot be negative.');
    }
    if ($amount < 0) {
        throw new CirculationConflict('invalid_fine_amount', 'Fine amount cannot be negative.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO fines (borrowing_id, days_overdue, rate_per_day, amount)
         VALUES (:borrowing_id, :days_overdue, :rate_per_day, :amount)'
    );
    $statement->execute([
        'borrowing_id' => $borrowingId,
        'days_overdue' => $days,
        'rate_per_day' => $rate,
        'amount'       => $amount,
    ]);
    $fineId = (int) $pdo->lastInsertId();
    if ($fineId === 0) {
        throw new CirculationConflict('fine_insert_failed', 'Could not insert the fine.');
    }
}
