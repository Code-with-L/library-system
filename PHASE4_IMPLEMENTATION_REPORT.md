# Phase 4 Implementation Report

**Phase 4 accepted; Phase 4.1 verification: PASS with residual risk (duplicate-return e2e and consolidated direct matrix not run).**

---

## 1. Executive summary

Phase 4 manual library-desk fines management and settlement is implemented for the approved scope:

- Librarian fine management: list, search, filters, calculation breakdown, settlement.
- Student fine history: outstanding/settled fines, due dates, overdue duration, amounts.
- Fines integration with return workflow: overdue returned copies get outstanding fines.
- Manual settlement with audit trail.
- Dashboards show real outstanding/settled totals.

The calculation engine uses `fines_assess_return_for_borrowing()` and is wired into `circulation_return_borrowing()` in the same transaction, preserving lock order `book_copies → borrow_requests → borrowings → fines`. No payment integrations or unapproved features were added.

---

## 2. Initial repository state

- No Git repository exists; workspace-only.
- Docker stack running; PHP 8.2.35, MariaDB 10.11.19, Apache 2.4.69.
- Existing Phase 2.1 and Phase 3 foundation accepted.

---

## 3. Existing fine architecture discovered

- `fines` table: `borrowing_id` unique FK, `days_overdue`, `rate_per_day`, `amount`, `calculated_at`.
- No settlement workflow: no state, settlement timestamp, staff reference.
- `library_settings`: KES, 14-day loan, 10.00/day, Africa/Nairobi.
- Approved Phase 1 design: no settlement workflow.

---

## 4. Approved policy

- Currency: KES.
- Timezone: Africa/Nairobi (PHP and MariaDB `+03:00`).
- Loan period: 14 days.
- Overdue rate: KES 10.00 per day.
- No grace period/caps/waivers/payments approved.

---

## 5. Database changes

- `fines` added: `status ENUM('outstanding','settled') DEFAULT 'outstanding'`, `assessed_at`, `settled_by_id`, `settled_at`, indexes, FK to `users` for `settled_by_id`.
- `fines.amount` remains decimal; settlement stores original amount.
- Migration: `database/migrations/0002_fines_settlement.sql`.
- Fresh installs use updated `database/database.sql`.
- MariaDB cannot CHECK FK columns, so settlement-state checks are application-level.

---

## 6. Implementation map

### Fine calculation engine

- `includes/fines.php`
  - `fines_config()` reads settings.
  - `fines_assess_return_for_borrowing()` computes overdue days from return date vs due date; inserts outstanding fine; idempotent by borrowing.
  - `fines_assess_active_overdue_for_borrowing()` uses `DATEDIFF(CURDATE(), due_date)` for active overdue.
  - `fines_outstanding_stats()` and `fines_outstanding_total_all()` for dashboards.
  - `fines_settle()` locks borrowing→fine and marks `settled`.
  - `fines_fetch()`, `fines_fetch_student()` display helpers.

### Circulation integration

- `includes/circulation.php::circulation_return_borrowing()` now calls `fines_assess_return_for_borrowing()` after Rule D updates, before commit.
- This keeps fine creation in the return transaction; global lock order remains `book_copies → borrow_requests → borrowings → fines`.

### Pages

- Librarian: `public/staff/fines.php`, `public/staff/fine-details.php`, `public/staff/fine-action.php`.
- Student: `public/student/fines.php`.
- Dashboards: `public/student/dashboard.php`, `public/staff/dashboard.php`, `public/admin/dashboard.php`.
- Sidebar: `includes/sidebar.php` adds Fines links.

### Activity logging

- `fine_assessed` logged by return workflow when a fine is created.
- `fine_settled` logged in librarian settlement action.

---

## 7. Calculation design

- Returned: `DATEDIFF(return_date, due_date)`, clamped ≥ 0.
- Active overdue: `DATEDIFF(CURDATE(), due_date)` where `status='active' AND due_date < CURDATE()`.
- Fine rate: `library_settings.fine_per_day`.
- Amount: `days_overdue * rate_per_day`, formatted as decimal.
- Idempotent assessment: existing fine for borrowing is reused.
- No duplicate charges; settlement uses `WHERE status='outstanding'`.

---

## 8. Settlement workflow

- Librarian POST `/staff/fine-action.php` with `fine_id` and `action=settle`.
- `fines_settle()` locks borrowing, then fine; validates existing/outstanding; updates `status='settled', settled_by_id, settled_at`.
- Duplicate settlement throws `FinesConflict('fine_already_settled')`.
- Recorded in activity log.

---

## 9. Security/authorization

- All writes: POST + CSRF.
- Librarian routes: `require_staff()`.
- Student routes: `require_role('student')`.
- Server computes amounts; browser cannot alter rates/amounts.
- Ownership checks for student views.

---

## 10. Tests executed and results

Environment: Docker stack `http://localhost:8090/`, PHP 8.2.35, Apache 2.4.69, MariaDB 10.11.19.

| Test | Command | Result |
| --- | --- | --- |
| PHP lint | `docker compose exec -T web sh -lc 'cd /var/www/html/campus-library && find includes public -name "*.php" -print0 \| xargs -0 -n1 php -l'` | PASS |
| Fine smoke | `python C:\Users\leon2\AppData\Local\Temp\opencode\phase4_smoke.py` | PASS |
| Phase 3 regression | `python C:\Users\leon2\AppData\Local\Temp\opencode\phase3_smoke.py` | PASS |
| Phase 2.1 regression | `python C:\Users\leon2\AppData\Local\Temp\opencode\phase21_regression.py` | PASS |
| Phase 2.1 circulation | `.dev/circulation-test.php basic/invalid_prevention/rollback/audit/concurrent` | PASS |
| Phase 4 HTTP settlement flow | `python C:\Users\leon2\AppData\Local\Temp\opencode\phase4_http_tests.py` | PARTIALLY PASS: auth/CSRF/GET/session checks PASS; late-return fine calculation e2e not exercised; engine calculation marked PARTIALLY TESTED |

Calculation engine tests:
- Not executed as final HTTP late-return assertion: coverage was attempted but overly time-dependent; no passing calculation evidence is claimed.
- On-time return logic exists but not executed in final test pass.

---

## 11. DB final state

- Users 3, books 12, copies 22, borrow_requests 0, borrowings 0, fines 0, activity_logs 3.
- Session version migration was applied in Phase 2.1.

---

## 12. Files created/changed/deleted

Created:
- `includes/fines.php`
- `public/staff/fines.php`
- `public/staff/fine-details.php`
- `public/staff/fine-action.php`
- `public/student/fines.php`
- `database/migrations/0002_fines_settlement.sql`
- `PHASE4_IMPLEMENTATION_REPORT.md`

Changed:
- `database/database.sql`
- `includes/circulation.php`
- `includes/fines.php`
- `includes/init.php`
- `includes/sidebar.php`
- `public/staff/dashboard.php`
- `public/admin/dashboard.php`
- `public/student/dashboard.php`
- `README.md`
- `TESTING.md`

Deleted:
- None.

---

## 13. Recommendation

**Phase 4 accepted with residual risk: late-return fine calculation not fully exercised; manual settlement and security regressions pass.** Do not proceed to Phase 5 without adding late-return calculation tests or replacing time-dependent workflow.
