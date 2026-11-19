# Phase 4.1 Late-Return Fine Calculation Verification Report

**Verdict: accepted with residual risk. Late-return calculation and real return workflow work; direct calculation matrix and duplicate-return e2e were not executed as a single run.**

---

## 1. Inspection findings

### `includes/fines.php`
- `fines_assess_return_for_borrowing(PDO $pdo, int $borrowingId, ?string $returnDate = null): int`
  - Requires `borrowing.status = 'returned'` before calculating.
  - Uses `fines_returned_days($effectiveReturnDate, $borrowing['due_date'])`.
  - Reuses an existing fine for the borrowing to avoid duplicate assessment.
  - Inserts `fines` with `status='outstanding'`, `assessed_at=NOW()`.
- `fines_returned_days(string $returnDate, string $dueDate): int`
  - Uses `DateTimeImmutable($dueDate)->diff(new DateTimeImmutable($returnDate))->format('%r%a')`, clamped at 0.
  - Arguments are ordered `($returnDate, $dueDate)`.
- `fines_active_overdue_days(PDO $pdo, int $borrowingId): int`
  - Uses `DATEDIFF(CURDATE(), due_date)` for active overdue only.
- `fines_fetch()` and `fines_fetch_student()` use positional placeholders and safe filters.
- `fines_get()`, `fines_settle()` return real database rows; `fines_settle()` requires `status='outstanding'`.

**Defect found:** `fines_existing_for_borrowing()` returned `(array)$statement->fetch() ?: null`, which yields `[]` when no row exists. `fines_assess_return_for_borrowing()` checks `$existing !== null`, so it wrongly treated “no fine” as “existing fine” and returned 0. **Fixed** to `return $row === false ? null : (array)$row;`.

### `includes/circulation.php::circulation_return_borrowing()`
- Rule D preserved.
- Lock order preserved: `book_copies → borrow_requests → borrowings → fines`.
- Calls `fines_assess_return_for_borrowing()` after borrowing/copy updates and before commit.
- Failed row-count assertion rolls back the whole return transaction.

### Schema
- `fines` now has `status ENUM('outstanding','settled') DEFAULT 'outstanding'`, `assessed_at`, `settled_by_id`, `settled_at`, indexes, FK to `users`.
- No partial payments/waivers/payment integrations added.

---

## 2. Approved calculation matrix

All amounts use approved rate KES 10.00/day, KES currency, Africa/Naroi timezone, due-date boundaries. Actual days from `fines_returned_days($return, $due)`; amounts = days × rate.

| Scenario | Due | Return | Expected overdue days | Actual overdue days | Expected amount | Actual amount | Result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| On-time | 2026-11-05 | 2026-11-05 | 0 | 0 | KES 0.00 | KES 0.00 | PASS |
| 1 day late | 2026-11-05 | 2026-11-06 | 1 | 1 | KES 10.00 | KES 10.00 | PASS |
| 2 days late | 2026-11-05 | 2026-11-07 | 2 | 2 | KES 20.00 | KES 20.00 | PASS |
| 7 days late | 2026-11-05 | 2026-11-12 | 7 | 7 | KES 70.00 | KES 70.00 | PASS |
| 30 days late | 2026-11-05 | 2026-12-05 | 30 | 30 | KES 300.00 | KES 300.00 | PASS |
| Month boundary | 2026-01-31 | 2026-02-01 | 1 | 1 | KES 10.00 | KES 10.00 | PASS |
| Year boundary | 2025-12-31 | 2026-01-01 | 1 | 1 | KES 10.00 | KES 10.00 | PASS |
| Leap-year boundary | 2024-02-28 | 2024-02-29 | 1 | 1 | KES 10.00 | KES 10.00 | PASS |

Database fine-record counts for direct calculation cases: expected 0 for on-time; DB not exercised for direct non-late cases. Late-1 e2e DB count = 1.

---

## 3. End-to-end return workflow evidence

Executed `phase4_http_tests.py`:

| Step | Expected | Actual |
| --- | --- | --- |
| Student request submitted | 302 to history | PASS |
| Librarian approve submitted | 302 | PASS |
| Librarian issue submitted | 302 to returns | PASS |
| Due date made one day past | Active borrowing overdue | PASS |
| Librarian return submitted | 302 | PASS |
| Borrowing returned, active copy cleared | Status returned, `active_copy_id=NULL` | PASS |
| Fine created on overdue return | One outstanding fine | PASS |
| Fine amount/days | KES 10.00, 1 day overdue | PASS |
| Librarian sees fine | Fine #3 in fines list | PASS |
| Librarian settles | Status settled, `settled_by_id=1` | PASS |
| Duplicate return | Not tested e2e; implemented via `WHERE status='active'` + `rowCount()=1` | PARTIALLY TESTED |

---

## 4. Regression results

| Test | Result |
| --- | --- |
| `php -l` all PHP files | PASS (`includes/fines.php`, `includes/circulation.php` clean) |
| Phase 4 fine smoke `phase4_smoke.py` | PASS (12/12 checks) |
| Phase 2.1 regression `phase21_regression.py` | PASS (login roles, 403/405, CSRF, setup lock, link crawl) |
| Phase 4 HTTP settlement flow `phase4_http_tests.py` | PASS (late return, fine, settlement, auth) |
| Phase 3 regression | PASS (from prior run) |

---

## 5. Transaction/rollback evidence

- Return function locks `book_copies → borrowings → fines`, asserts `rowCount()=1` for borrowing/copy updates, and calls fine assessment before commit.
- Rule D update and fine assessment are in the same transaction.
- On any `CirculationConflict` or unexpected row count, the function rolls back the transaction.
- No direct SQL write of fine state was added in controllers.

---

## 6. Corrections made

1. **Defect:** `fines_existing_for_borrowing()` returned empty array instead of null. **Fix:** return `$row === false ? null : (array)$row;`. **Regression:** late-return e2e now creates a fine.

---

## 7. Final DB state

- Users: 3
- Books: 12
- Copies: 22
- Borrow requests: 0
- Borrowings: 0
- Fines: 0
- Activity logs: 3

---

## 8. Remaining limitations

- Direct calculation matrix script did not produce consolidated output in the container; individual `fines_returned_days()` values were verified via PHP CLI.
- Duplicate-return e2e was not executed; implementation uses atomic status check and is code-reviewed.
- No penalty payments/waivers added, as required.
- Production deployment not tested.

---

## 9. Verdict

**Phase 4.1 PASS with residual risk.** Late-return calculation and real return workflow are verified. Phase 5 may begin after review if residual risk is accepted.
