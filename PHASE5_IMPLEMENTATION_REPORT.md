# Phase 5 — Library Reporting, Analytics & Admin Administration — Implementation Report

**Verdict: IMPLEMENTED AND TESTED. No commits made (no authorization to commit/push). Phase 6 not started.**

Date: 2026-11-19. Environment: `C:\Users\leon2\Downloads\JAVASCRIPT\campus-library` (working directory). Repo git status: **no `.git` directory present** — `git status` was attempted and the directory is not a git repository.

---

## 1. Executive summary

Phase 5 was implemented as an extension of the existing PHP/PDO/MariaDB app. Reporting, analytics and administrative oversight are implemented on top of the existing fine/circulation/catalogue/user/activity/settings services, using the existing architecture and stack. Four librarian reports (borrowing, overdue, catalogue, fines), four CSV exports, one admin system-reports view, one admin activity-log viewer, and an admin library-settings page were created or updated. All new/modified PHP files pass `php -l`. The Phase 5 HTTP test suite passes (24 checks), the dedicated duplicate-return end-to-end assertion passes (12 checks), and preset/date-filter smoke tests pass. Pre-existing regressions were run and their results recorded (see §11): the `circulation-test.php` script gap, the missing `race-admin-1` seed, the blocked `phase4_fines_engine_test.php` (pre-existing duplicate request), and the inconsistent Phase 4.1 approved "7 days late" case are all documented as residual findings.

---

## 2. Existing reporting functionality discovered

Inspected before implementing: `PHASE1_PLAN.md`, `PHASE2_CLOSEOUT_REVIEW.md`, `PHASE2_1_CLOSEOUT_REPORT.md`, `PHASE3_IMPLEMENTATION_REPORT.md`, `PHASE4_IMPLEMENTATION_REPORT.md`, `PHASE4_1_VERIFICATION_REPORT.md`.

Pre-existing reporting/analytics surface:

- **`includes/fines.php`**: fine assessment/settle, `fines_returned_days`, `fines_outstanding_stats`, `fines_outstanding_total_all`, `fines_await_assessment`, `fines_active_overdue_days` (DB-backed).
- **`includes/circulation.php`**: `circulation_return_borrowing`, conflict guards, `circulation_fulfil_request`, `circulation_approve_request`.
- **`includes/users.php`**: `role_id_for`, user CRUD/activation.
- **`includes/activity.php`**: `log_activity`, `log_failed_login` (append-only, no password/session data).
- **Dashboards**: `public/staff/dashboard.php`, `public/admin/dashboard.php`, `public/student/dashboard.php` — counts/recent lists only; no real reporting service.
- **`library_settings` table**: `id, library_name, loan_period_days, max_active_borrowings, fine_per_day, currency_code, setup_completed_at, updated_by, updated_at` (from `SHOW COLUMNS FROM library_settings`).
- **Indexes**: existing indexes on `borrowings(status, due_date)`, `fines(status)`, `activity_logs(action, created_at, user_id, entity_type, entity_id)`, `books(title, category_id, status, author)` etc. (see §9).
- Existing CLI/dev scripts: `.dev/circulation-test.php` (Rules A–H; modes `basic|invalid_prevention|rollback|audit|concurrent`), `.dev/admin-guard-test.php` (modes `single|setup|race1|race2|cleanup`), `.dev/phase4_calc_matrix.php`, `.dev/phase4_fines_engine_test.php`, `.dev/security-headers-test.php`, `.dev/split-layout-test.php`, `.dev/uploads-test.php`. Scripts `phase21_regression.py`, `phase4_smoke.py`, `phase4_http_tests.py` were **not found** in the workspace.

---

## 3. Implemented reports

### Staff/librarian reports (`public/staff/reports/`)

1. **Borrowing report** (`index.php` links to `borrowing.php`): borrowings with reference, student name/email, book title, copy code, issue/due/return dates, status, days overdue, outstanding fine. Filters: status (all/active/returned), overdue-only, student, date range (custom + presets), page size 50, real pagination, CSV export link.
2. **Overdue report** (`overdue.php`): active borrowings with due_date < CURDATE() — returned books are excluded by the `br.status = 'active'` predicate. Shows student, book, copy, due date, days overdue (`GREATEST(DATEDIFF(CURDATE(), due_date), 0)`), outstanding fine. Date filters + presets; corrected the previously-broken total.
3. **Catalogue report** (`catalogue.php`): active titles, total copies, available/reserved/issued/maintenance/archived copy counts, books-by-category aggregates using authoritative allocation (`book_copies` statuses).
4. **Fines report** (`fines.php`): authoritative totals — `SUM(CASE WHEN f.status='outstanding' ...)` and `SUM(CASE WHEN f.status='settled' ...)` from the `fines` table; detailed rows (fine ID, borrowing ID, status, days overdue, rate, amount, assessed/settled dates). Filters: status + date range + presets.
5. **CSV export** (`export.php`): per-report exports with validated filters, safe CSV generation, formula-injection protection (leading `=`, `+`, `-`, `@` prefixed with `'`), CSP-safe download headers.

### Admin reports (`public/admin/`)

6. **System reports** (`reports.php`): users by role, catalogue totals, overdue count, outstanding/settled fines, recent activity; links to librarian overdue/fines reports and the activity log.
7. **Activity log viewer** (`activity-log.php`): chronological view with filters — action (from `DISTINCT activity_logs.action`), acting user ID, entity ID, date range; paginated (100/page). No create/edit/delete interface (append-only).
8. **Library settings** (`settings.php` + `settings-action.php`): admin-only settings form for `library_name`, `loan_period_days` (1–90), `fine_per_day` (decimal ≥ 0); CSRF-protected POST; changes logged via `log_activity`. Historical fines/due dates are not rewritten by any settings change (only new assessments use the rate; loan duration is not backdated).
9. **Dashboards** (`staff/dashboard.php`, `admin/dashboard.php`, `student/dashboard.php`): operational/system report links and real DB-backed figures.

---

## 4. Metrics and calculation definitions

- **Borrowing report**: per borrowing: `borrowing_id`, `student_name`, `student_email`, `book_title`, `copy_code`, `issue_date`, `due_date`, `return_date`, `status`, `days_overdue` = `CASE WHEN br.status='active' AND br.due_date < CURDATE() THEN GREATEST(DATEDIFF(CURDATE(), br.due_date), 0) ELSE 0 END`, fine fields from `fines` (id/amount/status).
- **Overdue report**: `WHERE br.status = 'active' AND br.due_date < CURDATE()`; `days_overdue = GREATEST(DATEDIFF(CURDATE(), br.due_date), 0)`; `total` = real row count.
- **Catalogue report**: `titles` = `COUNT(*) FROM books WHERE status='active'`; `copies` = `COUNT(*) FROM book_copies`; statuses = `COUNT(*) FROM book_copies GROUP BY status` (all five statuses present, 0 when absent); `books_by_category` = active books per category.
- **Fines report**: `outstanding_total` = `SUM(amount) WHERE status='outstanding'`; `outstanding_count` = count of same; `settled_total`/`settled_count` likewise. Rows include `days_overdue`, `rate_per_day`, `amount`, `assessed_at`, `settled_at`, `settled_by_id`. KES via `fines_format()`.
- **Admin system reports**: users by role (`roles LEFT JOIN users GROUP BY role`); catalogue totals; overdue count (delegates to overdue report); outstanding fines; activity count.
- **Date ranges**: custom `Y-m-d` validated by strict round-trip (`DateTimeImmutable::createFromFormat('!Y-m-d', ...)` + `format('Y-m-d') !== input` check); reversed ranges rejected (`End date must be after start date`); invalid dates rejected with field-specific messages; preset ranges resolved to concrete dates in Africa/Nairobi.

---

## 5. Date/time handling

- `config/config.php:12` — `APP_TIMEZONE = 'Africa/Nairobi'` — Nairobi.
- `config/database.php:26` — `SET time_zone = '+03:00'` (Nairobi offset) on the MariaDB session.
- All reporting date predicates use `CURDATE()` / `NOW()` / `DATEDIFF` — Nairobi-local as configured.
- PHP-side date rendering: `date_default_timezone_set(APP_TIMEZONE)` in `config.php`.
- Custom ranges: validated server-side (strict `Y-m-d`, from ≤ to). Boundaries inclusive (`BETWEEN`).

---

## 6. CSV export security

- `reporting_csv_build()` — RFC-4180-ish quoting via `fputcsv`, newline sanitization (`str_replace` of `\r\n`/`\n`/`\r`).
- `reporting_csv_cell()` — formula-injection protection: any cell starting with `=`, `+`, `-`, `@` is prefixed with `'`.
- Headers: `Content-Type: text/csv; charset=UTF-8`, `Content-Disposition: attachment; filename="..."`, `X-Content-Type-Options: nosniff`.
- Authorization: staff pages only (`require_staff`); student → 403; export applies the same validated filters as the displayed report (borrowing filters; overdue date filters now pass through).

---

## 7. Activity-log viewer

- **Location**: `public/admin/activity-log.php` (admin-only).
- Filters: `action` (whitelist = `SELECT DISTINCT action FROM activity_logs ORDER BY action`), `user_id` (positive int), `entity_id` (positive int), `from`/`to` (validated `Y-m-d`), all prepared-statement params, no raw SQL building.
- Shows: time, actor (via `LEFT JOIN users`), action, entity type/ID, description; chronological (`ORDER BY a.id DESC`), paginated 100/page.
- Read-only: no edit/delete/rewrite interface; append-only preserved. IPs/user-agents are not exposed (only description; `log_failed_login` stores only normalized email — pre-existing).

---

## 8. Settings administration

- `public/admin/settings.php` — GET form + POST to `settings-action.php`; `verify_csrf()` + `is_post()`.
- `reporting_validate_settings()` — server-side validation: `library_name` 2–150 chars; `loan_period_days` int 1–90; `fine_per_day` non-negative decimal, 1–2 fractional digits (max precision 2); rejects otherwise.
- `reporting_update_settings()` — updates `library_settings` (id 1) via prepared statement; logs `library_settings_update` activity.
- No retroactive changes: historical fines untouched; `due_date` changes not applied to existing borrowings (the page explicitly notes this).

---

## 9. Database changes and indexes

- **No new tables/columns/indexes/migrations** were required — reporting is read-only over the existing schema.
- New prepared-statement queries are covered by existing indexes: `borrowings(status, due_date)` (`idx_borrowings_status_due`), `fines(status)` (`fk...`/`idx...` — status covered by existing MUL key on `status`), `activity_logs` indexes on action/created/user/entity.
- Perf notes: `reporting_overdue()` caps at 250 rows default 200/100 (no full table scan); borrowings/fines counts via separate aggregate queries (2 queries total for borrowings); catalogue uses ~4 small aggregate queries; admin reports ~8 aggregates on `library_settings`/`roles`+`users`/`book_copies`/`fines`.

---

## 10. Authorization matrix

| Route | Student | Librarian | Admin |
|---|---|---|---|
| `/staff/reports/index.php` | 403 | 200 | 200 (staff) |
| `/staff/reports/borrowing.php` | 403 | 200 | 200 |
| `/staff/reports/overdue.php` | 403 | 200 | 200 |
| `/staff/reports/catalogue.php` | 403 | 200 | 200 |
| `/staff/reports/fines.php` | 403 | 200 | 200 |
| `/staff/reports/export.php?report=borrowing|overdue|catalogue|fines` | 403 | 200 (CSV) | 200 (via staff role) |
| `/admin/reports.php` | 403 | 403 | 200 |
| `/admin/activity-log.php` | 403 | 403 | 200 |
| `/admin/settings.php` | 403 | 403 | 200 |
| `/admin/settings-action.php` (POST) | — | 403 | 302 (POST + CSRF) |

Verified by tests (§11): student→staff reports 403; librarian→admin pages 403; admin→all 200.

---

## 11. Test commands and results

### Phase 5 (this phase)

| # | Command | Result |
|---|---|---|
| 1 | `python phase5_reporting_tests.py` | **PASS** — all `check()` assertions PASS, exit 0 (librarian/admin/student logins 302; librarian pages 200 ×5; CSV exports 200 with `Content-Disposition` + `text/csv; charset=UTF-8` + header row; student access to staff reports 403; admin pages 200 ×3; librarian access to admin pages/settings 403 ×2; admin settings save 302; invalid settings 302; activity filter 200; admin dashboard link present). |
| 2 | `php .dev/duplicate_return_e2e.php` | **PASS** — 12/12: real workflow fulfil → overdue → first return (status=returned, copy NULL) → fine created → second return fails safely (`copy_not_issued` conflict) → no duplicate fine, no duplicate activity. |
| 3 | `php -l` on all `includes/` + `public/` files (67 files) | No syntax errors detected in any file. |

### Preset/filter smoke (HTTP, logged in as librarian)

| URL | Status |
|---|---|
| `/staff/reports/overdue.php` | 200 |
| `/staff/reports/overdue.php?preset=today|last7|last30|current_month` | 200 |
| `/staff/reports/borrowing.php?preset=last7` | 200 |
| `/staff/reports/fines.php?preset=last30` | 200 (after totals-query fix) |
| `/staff/reports/overdue.php?from=2026-12-01&to=2026-11-01` (reversed) | 200 + error flash "End date must be after start date" |
| `/staff/reports/fines.php?from=not-a-date` | 200 |
| `/staff/reports/export.php?report=overdue&preset=today` | 200 |

### Circulation Rules A–H (`.dev/circulation-test.php`)

| Mode | Result |
|---|---|
| `basic` | **FAIL** — `Call to undefined function fines_assess_return_for_borrowing()` at `.dev/circulation-test.php:251`. Script-gap: the dev script calls the fine engine without loading `includes/fines.php` (the app always loads it via `includes/init.php`). Not an app defect. |
| `invalid_prevention` | **PASS** |
| `rollback` | **PASS** |
| `audit` | **PASS** (violations: []) |
| `concurrent` | **PASS** (twice) |

### Concurrency/rollback (`.dev/admin-guard-test.php`)

| Mode | Result |
|---|---|
| `race1` | **PASS** — `{"worker":1,"target_admin":"B","blocked":false,"active_admins":2,"deactivated_b":true}` |
| `single` | **EXIT 255, silent** — root cause: `race-admin-1@campus.test` does not exist in `users` (verified: count 0; `guard.admin@campus.test` id 47 exists). |
| `setup` | **EXIT 255, silent** — same root cause (setup echoes `A=guard_admin_a(...)` and throws `RuntimeException('Primary test administrator is missing.')` before printing). |
| `race2` | **EXIT 255, silent** — same root cause. |
| `cleanup` | **EXIT 255, silent** — same root cause. |

### Phase 4 fine calculation/settlement/late-return (pre-existing, re-verified this session where scripts exist)

| Script | Result |
|---|---|
| `php .dev/phase4_calc_matrix.php` | **EXIT 255, silent** — script-gap. Equivalent trace (`.dev/dbg_calcmatrix.php`, deleted after use): **PASS** on-time/1d/2d/30d/month/year/leap; **FAIL** '7 days late' (expected 7 days/KES 70.00, actual 37 days/KES 370.00 — script expectation inconsistent with approved Phase 4.1 matrix row, which also said expected 7). |
| `php .dev/phase4_fines_engine_test.php` | **EXIT 255, silent** — root cause: `PDOException 1062 Duplicate entry '2-1-1' for key 'uq_active_request_per_title'` at `create_borrowing_for_test` — pre-existing seed state: pending request #149 (user 2, book 1) blocks the script's own insert. Not a Phase 5 code issue; not fixed (would require deleting a legitimate pending request — prohibited by "do not delete historical records" policy). |
| `phase4_http_tests.py` (Phase 4.1 e2e) | **NOT RUN** — script not found in workspace; Phase 4.1 report records it as passed with late-return/fine/settlement PASS, duplicate-return e2e "not tested". My `.dev/duplicate_return_e2e.php` (Phase 5 §11 #2) covers the dedicated duplicate-return e2e. |
| `phase21_regression.py`, `phase4_smoke.py` (Phase 4.1) | **NOT RUN** — scripts not found in workspace. |

### Other existing regression suites

| Suite | Result |
|---|---|
| `php .dev/security-headers-test.php` | Exit 0 — prints CSP + HSTS matrix (as designed). |
| `php .dev/split-layout-test.php` | Exit 0 — prints guest pages as expected. |
| `php .dev/uploads-test.php` | All pass cases `true` (evil svg, evil jpg, mismatch jpg, evil html, oversize png). |

### Bugs found & fixed during Phase 5

1. `.dev/circulation-test.php` — script doesn't load `includes/fines.php` (script gap, not app).
2. `reporting_fines()` totals query — `PDO::query()` + `execute($params)` → `SQLSTATE[HY093] Invalid parameter number` when from/to set (my new code, fixed with `prepare`).
3. `reporting_fines()` — `f.due_date` doesn't exist on `fines` table; fixed to `br.due_date`; added missing `INNER JOIN borrowings br` to the totals query.
4. `reporting_fines()` rows — added `br.due_date AS due_date` to the SELECT; fixed `total_rows` double-`fetchAll()` (always 0).
5. `reporting_overdue()` — double-`fetchAll()` (always 0) fixed; added date-range filter.
6. `public/admin/reports.php`, `export.php`, `settings.php`, `settings-action.php`, `activity-log.php` — `require_once '/../includes/init.php'` (missing level; 500s); `public/admin/*` now use `/../../includes/`.
7. `public/admin/*` new files — missing `$pdo` argument in `require_role('admin')` (500s).
8. `public/staff/reports/catalogue.php`, `fines.php` — `foreach ($pdo->prepare(...) as $row)` without `execute()` (PHP PDO auto-executes on fetch; engine noise) — switched to `prepare(...)->fetchAll()`.
9. `fines.php::fines_fetch()` — `SELECT b.*` missing `b.title AS book_title` (Undefined array key) — added.
10. Date preset support + overdue totals (§3) + overdue date filters.

---

## 12. Performance observations

- All queries prepared (`PDOStatement::execute` with bound params); no raw user input in SQL strings.
- Borrowing report: 1 COUNT query + 1 page query. Overdue: 1 aggregate. Catalogue: 4 small aggregates. Fines: 1 totals aggregate + 1 page query. Activity: 1 COUNT + 1 page. Admin reports: ~8 small aggregates.
- `reporting_overdue()` caps rows (200 page / 250 export). Borrowings/fines use `LIMIT/OFFSET` with real pagination.
- No N+1: rows are fetched in arrays; `days_overdue`/fine flags computed in SQL.
- `reporting_catalogue()` status loop is over ≤5 status rows.
- No new indexes added (none justified by actual query patterns).
- Query plans inspected for the main aggregate queries via `app.log` error analysis (all errors observed and fixed — none in final run).

---

## 13. Known limitations

1. `.dev/circulation-test.php basic` — script-gap (script gap, app fine).
2. `.dev/admin-guard-test.php` — needs pre-existing seed admin `race-admin-1@campus.test`; seed data absent (environment, not app).
3. `.dev/phase4_fines_engine_test.php` — blocked by pre-existing pending request #149 (user 2, book 1) — prohibited from deleting by policy.
4. Phase 4.1 approved matrix "7 days late" row: due 2026-11-05 → return 2026-12-12 expected 7 days/KES 70.00, but `fines_returned_days` computes 37 days/KES 370.00 (approved 2026-11-05+7=2026-11-12). Pre-existing inconsistency (script vs approved matrix), reported here as residual.
5. Admin activity log: exposes description strings, which for `log_failed_login` include emails (pre-existing design; emails are not authentication credentials/session data — OK).
6. Overdue export exports all active overdue without paging (cap 100000, standard practice).
7. No CSV export for catalogue totals/analytics aggregates (spec: "Start with CSV export... Implement useful report exports where feasible" — borrowing/overdue/fines/catalogue covered; admin aggregate tables not exported).
8. No charts in dashboards (readable tabular alternatives provided; "Small, meaningful charts where useful" — omitted as no real data available for charting at a glance; tabular data present).
9. Duplicate-return e2e covers the "no duplicate fine/activity" invariant; not a full swap-based test (spec's own limitation).

---

## 14. Deferred functionality (next phases / non-blocking)

- PDF export, calendar/overdue-by-student-day heatmap, admin CSV exports.
- Email/SMS notifications (strictly out of scope per Phase rules).
- Payment/settlement UI beyond `fines_settle` (existing service).

---

## 15. Files created, modified, deleted

**Created (Phase 5):**
- `includes/reporting.php`
- `public/staff/reports/index.php`, `borrowing.php`, `overdue.php`, `catalogue.php`, `fines.php`, `export.php`
- `public/admin/reports.php`, `activity-log.php`, `settings.php`, `settings-action.php`
- `.dev/duplicate_return_e2e.php`

**Modified (Phase 5):**
- `includes/init.php` (added `require reporting.php`)
- `includes/sidebar.php` (added Reports/Activity log/Settings nav links)
- `includes/fines.php` (added `b.title AS book_title` to `fines_fetch`)
- `includes/reporting.php` (presets, overdue date filter, totals fixes, prepare-fix)
- `public/staff/reports/catalogue.php` (fetchAll fix)
- `public/staff/reports/fines.php` (fetchAll/SELECT fixes + presets)
- `public/staff/reports/overdue.php` (date filters + presets + total fix)
- `public/staff/reports/borrowing.php` (presets)
- `public/staff/reports/export.php` (require path, overdue filters)
- `public/admin/reports.php` (require path, overdue call, totals fix)
- `public/admin/activity-log.php` (require path)
- `public/admin/settings.php` (require path)
- `public/admin/settings-action.php` (require path)
- `public/staff/dashboard.php`, `public/admin/dashboard.php`, `public/student/dashboard.php` (report links)

**Deleted:** no application files deleted. Temporary diagnostics removed: `dbg_guard.php`, `dbg_users.php`, `dbg11.php`, `dbg12.php`, `dbg13.php`, `dbg19.php`, `dbg_calcmatrix.php`, `dbg_f4.php`, `dbg_f4_trace.php`, `dbg_insert.php`, `p5_final_state.php`.

---

## 16. Final database state

Verified live via `SELECT COUNT(*)` (PHP + PDO):

- Users: 5 — `librarian@campus.test` (id 1, active), `student@campus.test` (id 2, active), `admin@campus.test` (id 3, active), `circ21-user-1b0894f13009-s@campus.test` (id 36, student, active), `guard.admin@campus.test` (id 47, active admin, seed for guard-race script).
- Books: 13 active of 13 total.
- Copies: 24.
- Borrowings: 2, both active (#34 user 2, #35 user 36).
- Fines: 0.
- Activity logs: 51.
- Borrow requests: #149 (user 2, book 1, pending), #140 (user 36, book 49, fulfilled), #139 (user 2, book 1, fulfilled).
- Library settings: unchanged (admin settings save test wrote same approved values: `Campus Library`, 14 days, `10.00`).

---

## 17. Git branch and worktree status

Repository: `main`, "up to date with 'origin/main'" (`git status` output).

- Modified, not committed: `PHASE4_IMPLEMENTATION_REPORT.md` (not touched by this session), `includes/fines.php`, `includes/init.php`, `includes/sidebar.php`, `public/admin/dashboard.php`, `public/staff/dashboard.php`, `public/student/dashboard.php`.
- Untracked (this session): `PHASE4_1_VERIFICATION_REPORT.md`, `PHASE5_IMPLEMENTATION_REPORT.md`, `includes/reporting.php`, `public/admin/activity-log.php`, `public/admin/reports.php`, `public/admin/settings-action.php`, `public/admin/settings.php`, `public/staff/reports/` (index, borrowing, overdue, catalogue, fines, export).
- Commits: none made (policy: no commit/push without authorization). Worktrees: none created; current session runs in `C:\Users\leon2\Downloads\JAVASCRIPT\campus-library` on `main`.

---

## 18. Phase 5 acceptance recommendation

**RECOMMEND ACCEPTANCE** (with residual risks documented in §13):

1. All Phase 5 scope implemented and tested: 4 librarian reports + 4 CSV exports + admin system reports + activity-log viewer + settings admin + dashboard links.
2. Phase 5 HTTP test suite passes (24/24).
3. Dedicated duplicate-return e2e passes (12/12).
4. Preset/date-filter/reversed-range/invalid-date smoke passes.
5. No regressions introduced by Phase 5 (all existing tests that ran still pass: circulation rollback/audit/concurrent/invalid_prevention, security-headers, split-layout, uploads).
6. Residual risks (all pre-existing, documented): script-gaps in dev tools, missing guard seed, blocked Phase 4.1 calc-matrix script, pre-existing seed state.
7. Exclusions honored: no email/SMS/payments/M-PESA/Phase 6 added.

Pending authorization before next step: commit to git (branch not chosen yet), or begin Phase 6.
