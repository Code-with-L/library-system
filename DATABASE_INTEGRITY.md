# Database Integrity — Phase 2.1

This document records the approved Phase 1 database invariants, the MariaDB
limitation that forced a deviation, the Phase 2.1 replacement strategy, the
exact Rules A–H implementation, and the residual risks.

---

## 1. Original approved Phase 1 invariants

From `PHASE1_PLAN.md` lines 451, 457, 459, 479 and 486:

### `borrow_requests`

- `reserved_copy_id` was intended to be a **generated** column:
  `GENERATED ALWAYS AS (CASE WHEN status = 'approved' THEN assigned_copy_id ELSE NULL END) STORED`.
- A check constraint should reject an approved request without an assigned copy:
  `chk_borrow_requests_approved_has_copy (status <> 'approved' OR assigned_copy_id IS NOT NULL)`.
- `(user_id, book_id, active_request_key)` — one active request per student/title.

### `borrowings`

- `active_copy_id` was intended to be a **generated** column:
  `GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN book_copy_id ELSE NULL END) STORED`.
- `(active_copy_id)` unique; `(request_id)` unique.

The actual implementation does **not** use those generated columns. See §2.

---

## 2. MariaDB limitation and why the schema changed

MariaDB 10.11 rejects generated columns and CHECK constraints that reference
foreign-key columns when the FK uses `ON UPDATE CASCADE`. The development
database uses MariaDB 10.11.19.

Observed errors during Phase 2 development (from `TESTING.md` lines 87–109 and
the closeout review):

```text
ERROR 1901: Function or expression 'assigned_copy_id' cannot be used in the
GENERATED ALWAYS AS clause of `reserved_copy_id`

ERROR 1901: Function or expression 'reserved_copy_id' cannot be used in the
CHECK clause of `chk_requests_approved_has_copy`
```

A generated column over `status` is allowed, so
`borrow_requests.active_request_key` remains. The reservation/active-loan
guards use plain nullable unique FK columns instead of generated columns.

Current implementation:

- `borrow_requests.reserved_copy_id INT UNSIGNED NULL` — nullable FK with
  `UNIQUE KEY uq_reserved_copy`.
- `borrowings.active_copy_id INT UNSIGNED NULL` — nullable FK with
  `UNIQUE KEY uq_active_borrowing_copy`.

## 3. Phase 2.1 replacement strategy

Database-enforced properties that remain:

- Unique active request per `(user_id, book_id, active_request_key)`.
- Unique non-NULL `reserved_copy_id`.
- Unique non-NULL `borrowings.active_copy_id`.
- Foreign keys reject missing users/books/copies.
- `chk_borrowing_dates`, `chk_returned_has_date`, and the other existing CHECK
  constraints remain unchanged and are still enforced.

Application-enforced state-machine properties that the nullable-key approach
cannot enforce:

- R1/R2: approved requests always have `assigned_copy_id = reserved_copy_id`.
- R4: reserved copy status is `reserved` while approved.
- B1/B2: active loans always have `active_copy_id = book_copy_id`.
- B4: active copy status is `issued`.
- State-machine timing rules: keys and statuses are set in the same SQL
  statement, and every expected `rowCount()` is checked.

Residual risk: a hostile or buggy writer that bypasses the Phase 3+ service
functions and issues direct SQL can still create the invalid states listed in
the closeout review §5.2. The audit queries in §6 are designed to run in the
test suite and periodically in production. The current schema does not add
triggers/checks to close this hole because those are prohibited by the MariaDB
limitation and would add complexity beyond the approved rules.

Supported database assumptions:

- Primary target: MariaDB 10.11+.
- Also compatible with MySQL 8.x; generated columns/CHECKs would be allowed by
  MySQL 8 but are intentionally not used to keep one code path for MySQL/MariaDB.
- CHECK constraints are assumed to be enforced (`STRICT_TRANS_TABLES`,
  `ERROR_FOR_DIVISION_BY_ZERO`, and related modes in `TESTING.md`).

---

## 4. Rules A–H implementation mapping

| Rule | Implemented in | Lock order | Row-count checks |
| --- | --- | --- | --- |
| A — approve | `circulation_approve_request()` | `book_copies → borrow_requests` | Copy update and request update both require `rowCount() = 1`; failure rolls back. |
| B — reject/cancel | `circulation_close_request()` | `book_copies → borrow_requests` | Copy and request updates require `rowCount() = 1`. |
| C — fulfil | `circulation_fulfil_request()` | `book_copies → borrow_requests` | Request and copy updates require `rowCount() = 1`; insert requires non-zero `lastInsertId()`. |
| D — return | `circulation_return_borrowing()` | `book_copies → borrow_requests → borrowings` | Borrowing and copy updates require `rowCount() = 1`. |
| E — lost/damaged | `circulation_report_lost_copy()` | Uses Rule D ordering. | Same as Rule D. |
| F — copy status change | `circulation_change_copy_status()` | `book_copies` | Copy update requires `rowCount() = 1`. |
| G — lock order | Embedded in every rule above. | Global order `book_copies → borrow_requests → borrowings → fines`. | All expected writes assert `rowCount()`. |
| H — audit | `circulation_audit_queries()`, `circulation_audit()` | READ-only. | Used in tests/periodic audits. |

Lock rules from `PHASE2_CLOSEOUT_REVIEW.md` §5.4 are implemented verbatim:

- `FOR UPDATE` is used on `book_copies`, `borrow_requests` and `borrowings`
  where a row is read and then written.
- Non-locking pre-reads discover objects before locking (e.g. `Rule B`,
  `Rule D`) but are never used to update state.
- `circulation_approve_request()` has an optional `testHoldSeconds` delay after
  both locks are acquired; default `0` leaves production behavior unchanged.

---

## 5. State matrix

Same as §5.4 of the closeout review. See `PHASE2_CLOSEOUT_REVIEW.md` §5.4.

---

## 6. Rule H integrity audit queries

```sql
-- Approved request without a reservation or assignment
SELECT id FROM borrow_requests
WHERE status = 'approved'
  AND (reserved_copy_id IS NULL OR assigned_copy_id IS NULL);

-- Non-approved request holding a reservation
SELECT id FROM borrow_requests
WHERE status <> 'approved' AND reserved_copy_id IS NOT NULL;

-- Reservation and copy status disagree
SELECT br.id, br.reserved_copy_id, bc.status
FROM borrow_requests br
JOIN book_copies bc ON bc.id = br.reserved_copy_id
WHERE br.status = 'approved' AND bc.status <> 'reserved';

-- Active borrowing without the key, or key differs from the copy
SELECT id FROM borrowings
WHERE status = 'active'
  AND (active_copy_id IS NULL OR active_copy_id <> book_copy_id);

-- Returned borrowing still holding the key
SELECT id FROM borrowings
WHERE status = 'returned' AND active_copy_id IS NOT NULL;

-- Active borrowing and copy status disagree
SELECT b.id, b.book_copy_id, bc.status
FROM borrowings b
JOIN book_copies bc ON bc.id = b.book_copy_id
WHERE b.status = 'active' AND bc.status <> 'issued';

-- Duplicate active loans by physical copy (defence in depth)
SELECT book_copy_id, COUNT(*) AS n
FROM borrowings
WHERE status = 'active'
GROUP BY book_copy_id
HAVING n > 1;
```

`circulation_audit(PDO $pdo)` executes all of them and returns a list of
violations. Phase 3 and Phase 4 circulation endpoints must call
`circulation_audit_queries()`/`circulation_audit()` after every successful
circulation write transaction, not just in the dedicated Phase 3.4 test suite.

---

## 7. Circulation service-function contracts

- All functions return `void` except `circulation_fulfil_request()`, which
  returns `borrowings.id`.
- All functions throw `CirculationConflict` on unexpected state/row counts and
  roll back their transaction before throwing.
- Existing `ForeignKey`, unique-index, and CHECK protections are not weakened.
- Circulation functions do not hide actions: they perform visible SQL changes
  inside a normal PDO transaction.

---

## 8. Migration for existing installations

Fresh installs import `database/database.sql`, which includes `users.session_version`.
Existing installs run `database/migrations/0001_add_users_session_version.sql` once.
The migration is guarded with `information_schema.COLUMNS` and uses a stored
procedure so re-running it is safe on MariaDB and MySQL 8.

---

## 9. Supported-host assumptions and residual risks

- Generated-column/CHECK enforcement remains unavailable on MariaDB; see §2.
- Direct SQL writers bypassing the service layer can create invalid states; §6
  audit queries are required.
- Multi-node deployments require the same database and lock order; no
  distributed-lock protocol is introduced.
- Database upgrade windows should run the migration when no requests are in flight.
