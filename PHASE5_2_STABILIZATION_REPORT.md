# Phase 5.2 — Final Stabilization and Git Checkpoint Report

**Verdict: PASS.** All Phase 5.2 work is complete: the reported warnings were reproduced, root-caused and fixed with focused regression coverage; test-account residue was analysed without deletion; the full available regression suite passes; request #149 and all pre-existing records are intact; and one authorized local commit was created on `main` (**`c3cae95b6a696f7fdc3e65b06f32ce205957469e`**). No push, no Phase 6, no deployment.

Date: 2026-11-19.

---

## 1. Final review findings

Review scope: all Phase 5/5.1 changes (26 files committed below) plus the Phase 5.2 warning fixes.

| Check | Finding |
|---|---|
| SQL correctness / prepared statements | `includes/reporting.php` uses `PDO::prepare()` + bound params for every query containing user-supplied filters; the only `$pdo->query()` calls (lines 216, 217, 307) use fixed SQL with no external input. Pre-existing service queries inspected — all prepared. |
| Role-based authorization | Every new/changed page calls `require_staff($pdo)` or `require_role($pdo, 'admin')` at line 7: staff reports/export, admin reports/activity-log/settings/settings-action. Verified by HTTP 403 tests (student→staff/admin, librarian→admin). |
| CSRF on state-changing actions | `public/admin/settings-action.php` requires `is_post()` + `verify_csrf()`; `public/staff/fine-action.php` and other existing POST endpoints retain CSRF checks. Tests: POST without CSRF → 403. |
| CSV formula-injection protection | `reporting_csv_cell()` prefixes cells starting with `=`, `+`, `-`, `@` with `'`; `reporting_csv_build()` sanitizes newlines and quotes via `fputcsv`. Export headers `text/csv; charset=UTF-8` + `Content-Disposition: attachment`. |
| Date-range validation / Africa/Nairobi | `reporting_parse_range()` uses `DateTimeImmutable::createFromFormat('!Y-m-d', ...)` with strict round-trip check and rejects reversed ranges; presets resolve to concrete dates. `config.php:12` sets `APP_TIMEZONE='Africa/Nairobi'`; `config/database.php:26` sets session `time_zone='+03:00'`. Smoke tests: reversed range and invalid date handled safely. |
| Report totals / pagination | Fixed double-`fetchAll()` bugs (overdue total, fines total_rows); borrowings use real COUNT+pagination, activity/fines/overdue paginated. Tests confirm totals and exports. |
| Activity-log read-only | `public/admin/activity-log.php` only selects/joins/displays; no edit/delete endpoint exists. |
| Settings validation / audit logging | `reporting_validate_settings()` enforces name length, loan days 1–90, non-negative 2-decimal fine rate; `reporting_update_settings()` logs `library_settings_update`. Invalid-settings POST rejected by test. |
| No retroactive loan/fine changes | Settings changes only affect future assessments; no code rewrites `borrowings.due_date` or existing `fines.amount`. Verified by design review and the settings tests. |
| Dashboard / navigation links | Sidebar entries for Reports, Activity log, Settings; dashboards link to real report pages. Test `admin dashboard system reports link PASS`; link-crawl tests report zero errors. |

No new features were introduced in Phase 5.2. Fixes were limited to the verified warning defects below.

## 2. Warning investigation and resolution

### 2.1 Reproduction

Running `python C:\Users\leon2\AppData\Local\Temp\opencode\p52_repro_fine.py` (librarian session; `GET /staff/fine-details.php?id=1`) produced HTTP **200** with 14 warnings in `storage/logs/app.log` at 21:45:20:

```
Undefined array key "id" (line 25), "borrowing_id" (32,35), "student_name" (33),
"due_date" (34), "rate_per_day" (36), "amount" (37), "status" (38 x2),
"settled_by_id" (39), "settled_at" (40), "copy_code" (48),
"borrowing_status" (49), "return_date" (50)
```

The DB had 0 fines at the time. A second warning class appeared at 21:52:19 during the e2e: `Undefined array key "student_name" in public/staff/request-details.php on line 47`.

### 2.2 Exact causes

1. **`includes/fines.php::fines_get()`** used `return (array) $statement->fetch() ?: null;`. Verified live: `(array) false ?: null` evaluates to `[false]` (a non-empty, truthy array), so when no row exists the function returned `[false]` instead of `null`. `fine-details.php`'s `if ($fine === null) abort_404(...)` therefore never fired; the page rendered with `$fine = [false]`, producing every warning above. Additionally, even for an existing fine the query did not select `student_name` or `copy_code`, which the page reads (lines 33/48).
2. **`public/staff/request-details.php`** used the same `(array) $stmt->fetch()` pattern (missing request → `[false]`, abort never fires) and read `$request['student_name']` from `SELECT * FROM borrow_requests` — a column that does not exist on that table.

Same defect class was found on related execution paths: `public/staff/request-action.php`, `public/staff/issue-action.php`, `public/staff/category-form.php`, `includes/catalogue.php` (`catalogue_book_get`, `catalogue_copy_get`) and `includes/fines.php::fines_settle()` (missing fine fell through to `fine_already_settled` with a warning instead of `fine_not_found`).

### 2.3 Fixes (no calculation/settlement/authorization changes)

- `fines_get()`: `return $statement->fetch() ?: null;` and added `JOIN book_copies bc`, `LEFT JOIN users u`, selecting `bc.copy_code` and `u.full_name AS student_name`. Missing fine now yields `null` (→ 404); existing fine yields a complete row.
- `request-details.php`: query joins `users` to provide `student_name`; fetch no longer cast to `(array)` before the `=== false` check.
- Same-class corrections: `request-action.php`, `issue-action.php`, `category-form.php` (remove `(array)` cast before the missing-row check); `catalogue.php` (`return $statement->fetch() ?: null;` for both single-row getters); `fines_settle()` (`if ($fine === false)` → `FinesConflict('fine_not_found')`).
- No fine formula, rate, days calculation, status transition, authorization guard, or historical record was altered.

### 2.4 Focused regression tests

- `.dev/phase4_fines_engine_test.php` (ignored dev script) gained four assertions: `fines_get` returns the fine by id, includes `student_name`, includes `copy_code`, and returns `null` for a missing fine id.
- `C:\Users\leon2\AppData\Local\Temp\opencode\phase4_http_tests.py` gained two page assertions: `/staff/fine-details.php?id=<fine>` renders `Fine #<id>` with status 200; `/staff/request-details.php?id=<request>` renders `Borrow request #<id>` with status 200.

After the fixes: `GET /staff/fine-details.php?id=1` → **404** (no warnings); e2e with a real fine → **200, "Fine #17" shown**, and no new warnings in `app.log` during any Phase 5.2 test run. Lint clean on all touched files.

## 3. User 36 dependency analysis

`circ21-user-1b0894f13009-s@campus.test` (id 36, active, student role, created 2026-11-19 19:01:52):

- **Origin:** Phase 2.1 circulation test fixture — the email prefix `circ21-`, the copy code prefix `P21C-`, and book 49 titled `Phase 2.1 book ea32f8fdace9` identify it.
- `borrow_requests`: #140 — book 49, **fulfilled** (`active_request_key = NULL`).
- `borrowings`: #35 — copy 64 (`P21C-76243c267a0888cf33f5`, status **issued**), **active**, no return date, issued by user 1.
- `fines`: none via borrowing #35.
- `activity_logs`: none for user 36 — the e2e's pre-existing cleanup (`DELETE FROM activity_logs WHERE id > 3`) removed intermediate audit rows during earlier phases; current log ids are 1–3 and 307–309.
- **Decision:** cleanup is **not safe** — deleting user 36 would require also removing its active borrowing (which would orphan an issued copy) and fulfilled request. It remains as required historical test data. No deletion performed. If cleanup is later desired, propose a separate authorized change that closes the borrowing (returning copy 64), then removes the user/request/copy/book 49 together — awaiting explicit authorization.

## 4. Test results (all executed)

| # | Command (executed) | Result |
|---|---|---|
| 1 | `php -l` across `includes/` + `public/` (67 files) | **PASS** — no syntax errors |
| 2 | `python phase5_reporting_tests.py` | **PHASE5_REPORTING_TESTS PASS** |
| 3 | `php .dev/duplicate_return_e2e.php` | **PHASE5_DUPLICATE_RETURN_E2E PASS** (12 checks) |
| 4 | `php .dev/phase4_calc_matrix.php` | **PHASE4_CALC_MATRIX PASS** (8/8) |
| 5 | `php .dev/phase4_fines_engine_test.php` | **PHASE4_FINES_ENGINE_TESTS PASS** (incl. 4 new `fines_get` assertions) |
| 6–10 | `php .dev/circulation-test.php basic\|invalid_prevention\|rollback\|audit\|concurrent` | **PASS ×5** (EXIT:0 each; audit violations []) |
| 11–15 | `php .dev/admin-guard-test.php setup\|single\|race1\|race2\|cleanup` | **PASS ×5** (EXIT:0 each) |
| 16 | `python phase21_regression.py` | **PHASE21_REGRESSION PASS** |
| 17 | `python phase4_smoke.py` | **PHASE4_FINE_SMOKES PASS** |
| 18 | `python phase4_http_tests.py` | **PHASE4_FINE_HTTP_TESTS PASS** (incl. new fine-details and request-details page checks) |
| 19 | `python phase21_upload_tests.py` | **UPLOAD_HTTP_TESTS PASS** |
| 20 | `php .dev/security-headers-test.php` | **PASS** (EXIT 0; CSP matrix) |
| 21 | `php .dev/split-layout-test.php` | **PASS** (EXIT 0; guest pages) |
| 22 | `php .dev/uploads-test.php` | **PASS** (EXIT 0; all `pass:true`) |
| 23 | Dedicated Phase 3 script | **NOT AVAILABLE** — no `phase3*.py`/`phase3*.php` found in repo or temp dir. Phase 3 catalogue/circulation behaviour is covered indirectly by circulation Rules A–H and the catalogue/reporting suites. |

No test was reported PASS without being executed. No FAIL or BLOCKED items remain among available tests.

## 5. Database preservation checks

Verified live via PDO after the full sweep:

- **Request #149 intact:** `id=149, user_id=2, book_id=1, status='pending', active_request_key=1` — unchanged throughout Phase 5.2.
- Users: 4 — ids 1 (librarian@campus.test), 2 (student@campus.test), 3 (admin@campus.test), 36 (circ21-user…). No user deleted by this task.
- Borrowings: 2; Fines: 0 (fixtures created by the tests were removed by their scripts).
- Phase 5.1 fine-engine fixtures (user/book/copy `P5F…`): **0 left**. Phase 4.1 e2e fixture user: deleted at cleanup. Admin-guard fixtures created during this run (A=69, B=68): deleted at cleanup.
- No existing database record was modified or deleted by Phase 5.2 work outside the test scripts' own create/cleanup cycles.

## 6. Files included in the commit

Commit: **`c3cae95b6a696f7fdc3e65b06f32ce205957469e`** — message: `Phase 5/5.1: add library reporting, analytics and admin oversight with regression reconciliation` (26 files, 1705 insertions, 16 deletions).

| File | Nature |
|---|---|
| `includes/reporting.php` | New Phase 5 reporting service (468 lines) |
| `includes/fines.php` | Modified: Phase 5.1 `fines_fetch` join fix + Phase 5.2 `fines_get` / `fines_settle` fixes |
| `includes/catalogue.php` | Modified: single-row getters return null correctly |
| `includes/init.php`, `includes/sidebar.php` | Modified: reporting service load, nav links |
| `public/staff/reports/{index,borrowing,overdue,catalogue,fines,export}.php` | New librarian reports + CSV export |
| `public/admin/{reports,activity-log,settings,settings-action}.php` | New admin system reports, audit viewer, settings |
| `public/{staff,admin,student}/dashboard.php` | Modified: report links |
| `public/staff/{category-form,issue-action,request-action,request-details}.php` | Modified: Phase 5.2 missing-row fixes; request-details now shows student name |
| `PHASE4_1_VERIFICATION_REPORT.md`, `PHASE5_IMPLEMENTATION_REPORT.md`, `PHASE5_1_CLOSEOUT_REPORT.md` | New phase reports |
| `PHASE4_IMPLEMENTATION_REPORT.md` | Modified: pre-existing Phase 4 verdict update (Phase 4.1 status), preserved and included |

**Not committed (by design):** `.dev/*` test harnesses remain ignored via `.gitignore` (project policy: dev/test file protection) — this includes the Phase 5.1 test-harness repairs and Phase 5.2 focused assertions, which are documented here and in `PHASE5_1_CLOSEOUT_REPORT.md`. No `.env`, credentials, logs (`storage/logs/`), upload artifacts, or temp PHP files were staged. `PHASE5_2_STABILIZATION_REPORT.md` was created **after** the commit so that it could record the commit hash; it remains untracked and is the only post-commit working-tree change.

## 7. Final Git status

- Branch `main`; HEAD `c3cae95b…`; `origin/main` still at `2b6f8ce` — **local commit is ahead by 1, not pushed** (no push authorized).
- `git status --porcelain` after the commit: only `?? PHASE5_2_STABILIZATION_REPORT.md` (the deliverable written with the hash). No modified tracked files; no staged changes.
- No worktrees created; no earlier commits amended or rewritten.

## 8. Remaining risks

1. `.dev/` test scripts are git-ignored by project policy — their fixes are local only and will not be transferred by pushing the commit. If CI needs them, a separate policy decision is required.
2. User 36 remains with an active borrowing (documented in §3); its cleanup needs explicit authorization and a controlled workflow.
3. The historical `phase4_http_tests.py` e2e script deletes `activity_logs WHERE id > 3` at cleanup (pre-existing behaviour in the temp script, not application code) — audit rows created during e2e runs are not retained.
4. Dedicated Phase 3 script not available (§4, item 23) — Phase 3 covered indirectly.
5. The local commit has not been pushed or deployed.

## 9. Verdict and next step

**PASS.** Stabilization complete, warnings resolved with regression coverage, all available suites pass, data preserved, and one clean local commit created.

**Next recommended development step:** review commit `c3cae95` locally; when you explicitly authorize it, push `main` to `origin` (single `git push origin main`). **Phase 6 must not start without explicit authorization.**
