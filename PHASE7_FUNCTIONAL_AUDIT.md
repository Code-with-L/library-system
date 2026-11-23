# Phase 7 — Functional Audit — Campus Library Management System

Date: 2026-11-21. Type: **read-only audit**. No application code, database, configuration, frontend or live system was modified. No commit, push or deployment was made.

## 0. Evidence basis and limitations

- Production site `https://mmust-library.site.je` was accessed **read-only** through a real browser (the site's JavaScript cookie challenge was passed). Guest pages, public assets, error pages and private-path URLs were inspected.
- **No production credentials were used, no account was created, no form was submitted, no fine or irreversible transaction was triggered.** Authenticated production functionality (catalogue search as a student, borrowing workflow, dashboards) is therefore **NOT TESTED** on the live site. The production catalogue is reported as intentionally empty by the operator; this audit did not verify that claim.
- Functional testing was performed against the **local Docker development stack** (PHP 8.2 / MariaDB 10.11, seeded test database), which is an isolated test environment. Fixture-creating suites clean up after themselves.
- Labels: **PASS** verified by execution or unambiguous code evidence; **FAIL** demonstrably broken; **PARTIAL** partly working; **NOT TESTED** insufficient evidence.

## 1. Catalogue functionality

### 1.1 Search implementation — PASS (local)
All searches read the **actual database**; there is no hardcoded or sample search data.

| Check | Result | Evidence |
|---|---|---|
| Query source | PASS | `includes/catalogue.php:41-68` `catalogue_books_fetch()` builds SQL with `WHERE` fragments and bound `:q_title`, `:q_author`, `:q_isbn`, `:category_id`; `catalogue_books_count()` at `:75-93`. PDO connection at `config/database.php:19-33` with native prepares (`ATTR_EMULATE_PREPARES => false`). |
| Complete title | PASS | local test `/catalogue.php?q=Introduction to Algorithms` → 1 result |
| Partial title | PASS | `q=Algorithms` → 1 result |
| Author | PASS | `q=Cormen` → 1 result |
| ISBN (complete/partial) | PASS | `q=9780262046305` and `q=9780262` → 1 result |
| Category filter | PASS | `q` + `category=3` → 2 results; separate `b.category_id = :category_id` predicate |
| Case-insensitivity | PASS (local runtime) | `q=algorithms` and `q=ALGORITHMS` both match, via `utf8mb4_unicode_ci` collation (`database/database.sql:126`). No explicit `LOWER()` in SQL. |
| Empty search | PASS | omitted/blank `q` lists all 13 active test titles |
| No results | PASS | unknown term → "No books found" empty state (`public/catalogue.php:55-59`) |
| Pagination | PARTIAL | 25 per page, links preserve `q`/`category` (`public/catalogue.php:15-24,90-97`). Generic helper `includes/pagination.php:10` exists but is unused. |
| Book details & availability | PASS | `public/catalogue/detail.php:15-24,65-97`; `catalogue_book_copy_summary()` (`includes/catalogue.php:150-158`) counts available/reserved/issued/maintenance copies from `book_copies`. |

### 1.2 Catalogue defects

| ID | Severity | Finding | Evidence |
|---|---|---|---|
| C-1 | Low | **Out-of-range page shows "No books found"**. Fetch happens at `public/catalogue.php:21` before `$page` is clamped at `:24`; `page=999` queries an oversized OFFSET and renders the empty state. Reproduced locally. |
| C-2 | Low | **LIKE wildcards unescaped** — `includes/catalogue.php:50,84` interpolate the raw search string into `%...%`; `q=%` matches all 13 books. Admin user search escapes wildcards (`public/admin/users.php:30`), so this is inconsistent. |
| C-3 | Low | **Misleading detail-page copy** — `public/catalogue/detail.php:70` says requests "may still be allowed while a copy is reserved or issued", but `catalogue_student_can_request()` requires an `available` copy (`includes/catalogue.php:248-250`). |
| C-4 | Info | Count query omits the `categories` JOIN used by the fetch (`includes/catalogue.php:90` vs `:61`); harmless while the FK holds. Archived books' detail pages remain reachable (`catalogue_book_get` has no active-status filter); request buttons are still blocked. Availability per row is N+1 (`public/catalogue.php:78`). |
| C-5 | Info | `public/catalogue/detail.php:26-39` contains a redundant POST branch that its own form never uses; it accepts `book_id` from POST, allowing a crafted POST to reference a different title than displayed. |

**Production status:** catalogue search **NOT TESTED** on the live site (no student credentials; catalogue reportedly empty). The implementation is DB-backed and locally functional.

## 2. Borrowing functionality

### 2.1 End-to-end workflow — PASS (local)
The full happy path was executed against the isolated local test database with fixtures:

1. Student login → 2. catalogue search → 3. book detail → 4. `POST /catalogue/request.php` → 5. request recorded (`borrow_requests`, status `pending`) → 6. librarian `staff/requests.php` → 7. `staff/request-action.php` approve → 8. `staff/issue-action.php` issue (copy becomes `issued`, `borrowings` row created, due date from settings) → 9. student `catalogue/history.php` + `student/dashboard.php` show status → 10. `staff/return-action.php` records return (copy back to `available`) → 11. automatic overdue fine assessment and `staff/fine-action.php` settlement.

Evidence: `phase4_http_tests.py` (18 checks), `phase4_smoke.py` (14 checks), `circulation-test.php` modes `basic`, `invalid_prevention`, `rollback`, `audit`, `concurrent`, `phase4_fines_engine_test.php`, `phase4_calc_matrix.php` (8/8 fine matrix), `duplicate_return_e2e.php`. All executed and passed in this phase.

State machine: `includes/circulation.php` Rule A approve `:100-185`, Rule B close `:190-275`, Rule C fulfil `:282-373`, Rule D return `:378-463`; transactions with `FOR UPDATE`, documented lock order at `:11-13`, `rowCount()` assertions and DB unique keys (`database/database.sql:167-172,209,248`).

### 2.2 Edge cases

| Check | Result | Evidence / notes |
|---|---|---|
| Unavailable / reserved / archived book | PASS (request blocked) | `catalogue_student_can_request()` (`includes/catalogue.php:243-250`) requires active book + available copy; rechecked in `circulation_request_book()` (`:284-287`). See C-3 for misleading text. |
| Duplicate requests | PASS (normal path) / PARTIAL (race) | Unique key `uq_active_request_per_title` (`database.sql:167-171`) plus pre-checks (`catalogue.php:225-234,260-262`). A concurrent duplicate can raise an uncaught `PDOException` → 500 rather than a friendly message (`includes/catalogue.php:274-304`). |
| Exceeding borrowing limit | **FAIL (end-to-end)** | Limit checked only when requesting (`includes/catalogue.php:252-258,276-282`). `public/staff/issue-action.php:30-41` and `circulation_fulfil_request()` (`:282-373`) never re-check. Queueing several approved requests while at zero loans allows issuance beyond `max_active_borrowings`. |
| Unauthorized borrowing attempts | PASS | All endpoints guard with `require_role`/`require_staff`; `user_id` always taken from session (`catalogue/request.php:20,26`), so requests cannot be made for another student. Local cross-role probes: 0 failures. |
| Invalid book/request IDs | PASS | `FILTER_VALIDATE_INT` + `<1` → 404 in `request.php:15-18`, `cancel.php:15`, `detail.php:9-18`, `request-action.php:15`, `return-action.php:15`; local invalid detail id → 404. |
| Concurrent borrowing | PARTIAL | Transactional locks and unique DB keys prevent double reservation/loan (`circulation-test.php concurrent` PASS). However approve/issue controllers do not catch `CirculationConflict` (`staff/request-action.php:29-48`, `issue-action.php:41`), so races produce a generic 500. `request-action.php:22` runs `SELECT ... FOR UPDATE` outside a transaction, so that lock is ineffective (service functions re-lock correctly). |
| Overdue and fines | PASS (on return) / PARTIAL (scope) | `fines_assess_return_for_borrowing()` (`includes/fines.php:200-240`) runs inside the return transaction, idempotent via `uq_fines_borrowing`; rate from `library_settings` (`functions.php:259-281`), timezone `+03:00`. Fine matrix 8/8 PASS. `fines_assess_active_overdue_for_borrowing()` (`:242-275`) has no UI caller, so active overdue loans are not assessed until return. |

### 2.3 Borrowing defects

| ID | Severity | Finding | Evidence |
|---|---|---|---|
| B-1 | Medium | Borrowing limit bypass at issuance | `includes/catalogue.php:252-258` only; no recheck in `staff/issue-action.php:30-41` / `circulation.php:282-373` |
| B-2 | Medium | Uncaught circulation conflicts → HTTP 500 on approve/issue races | `staff/request-action.php:29-48`, `staff/issue-action.php:41` |
| B-3 | Medium | Staff fine list "Days overdue" always 0 | `staff/fines.php:18-30`: `(int)'active' === 0` is true in PHP, so `phase4_fine_days()` returns 0 |
| B-4 | Low | Fine details shows 0 days for returned loans | `staff/fine-details.php:35` uses `fines_active_overdue_days()` (`fines.php:154-166`) instead of stored `days_overdue` |
| B-5 | Low | Copy-status guard bypassed | `circulation_change_copy_status()` (`circulation.php:481-536`) has no callers; `staff/copy-action.php:35-41` uses `catalogue_save_copy()` which can overwrite a status referenced by an approved request or active loan |
| B-6 | Info | Service-only features: lost/damaged return (Rule E, `:468-476`), active-overdue assessment, `circulation_record_fine()` dead code; no UI | `circulation.php`, `fines.php` as cited; `return-action.php:21` hardcodes today + `available` |

**Production status:** borrowing workflow **NOT TESTED** on the live site (would require creating real records / triggering transactions, explicitly prohibited).

## 3. Test results summary

Full details in `PHASE7_TEST_RESULTS.md`. All local suites passed: phase 2.1 regression, phase 4 smoke/fine HTTP, phase 5 reporting, upload tests, duplicate-return, phase 6 verification, fine calculation matrix, fines engine, circulation A–H/concurrent, admin guard, security headers, uploads. Additional read-only probes: catalogue search matrix (1 genuine FAIL: out-of-range page), authorization matrix (0 failures), live guest/header/private-path checks.

## 4. Outstanding issues and recommended fixes

1. Re-check active-loan count inside `circulation_fulfil_request()` and `staff/issue-action.php` before issuing (B-1).
2. Catch `CirculationConflict` in approve/issue controllers and render a validation error (B-2).
3. Fix fine day display to use the stored `days_overdue` value (B-3, B-4).
4. Fix `page` clamping order in `public/catalogue.php` (C-1) and escape LIKE wildcards (C-2).
5. Correct the misleading availability copy in `catalogue/detail.php:70` (C-3).
6. Route copy-status changes through the guarded service function (B-5).
7. Perform a production smoke test with an authorised test account before enrolling real students.
