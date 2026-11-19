# Phase 2.1 Closeout Report

**Phase 2.1 remediation complete. Verdict: conditionally accepted for Phase 3 with explicit residual risks.**

## 1. Executive verdict

Phase 2.1 remediation is implemented and tested against the local Docker stack. The main Phase 3 blocker (unenforced database-level reservation/active-loan invariants) is now covered by application transaction logic from `PHASE2_CLOSEOUT_REVIEW.md` §5.4.

Conditional acceptance for Phase 3 is based on the following residual risks, which do not change the core verdict:

- HSTS is opt-in via `HSTS_MAX_AGE`; it was not exercised over real HTTPS.
- Only MariaDB 10.11 was available; MySQL 8 was not tested.
- Real browser/no-JS mobile navigation was not executed; CSS/HTML behavior was static-verified.
- InfinityFree/shared-hosting deployment was not tested; host assumptions are documented in `README.md`/`TESTING.md`.
- Server-side login throttling remains session-only (C-10) and is intentionally deferred.

No Phase 3 or Phase 4 functionality was implemented.

## 2. Initial worktree/branch state

- No Git repository exists under `C:\Users\leon2\Downloads\JAVASCRIPT`.
- All changes are workspace-only; there is no branch, commit, or push status to report.

## 3. Findings reviewed

- `PHASE2_CLOSEOUT_REVIEW.md` C-1 to C-9, D-1 to D-9, §4.1–4.10, §5.1–5.4, §7.
- Closeout verdict was “conditionally accepted”; Phase 2.1 remediation targets its accepted conditions.
- PHP lint across all application files passed after changes.

## 4. Exact implementation changes

### C-1 — database integrity, Rules A–H, tests

- Created `includes/circulation.php` implementing Rules A–F exactly from §5.4, including `book_copies → borrow_requests → borrowings → fines` lock order and `rowCount()` checks.
- Added `circulation_audit_queries()` and `circulation_audit()` for §5.4 Rule H.
- Created `database/migrations/0001_add_users_session_version.sql` and updated `database/database.sql`.
- Created `.dev/circulation-test.php` (basic, invalid_prevention, rollback, audit, concurrent).
- Updated `DATABASE_INTEGRITY.md` with formal amendment, strategy, rule mapping, audit queries, residual risks.

### C-2 — canonical public path

- Added `APP_PUBLIC_PATH`, `COVER_UPLOAD_DIR`, `COVER_ALLOWED_EXTENSIONS`, `HSTS_MAX_AGE`, `SESSION_COOKIE_SECURE_OVERRIDE` to `config/config.php`.
- `authorization.php` no longer requires `APP_ROOT/public/403.php` etc.; it calls `render_error_document()`.
- Added root `campus-library/.htaccess`, `campus-library/.dev/.htaccess`, and `public/uploads/covers/.htaccess` allowlist.
- Added `includes/uploads.php` for future Phase 3 upload validation.
- Updated README/TESTING deployment docs.

### C-3 — upload security

- `public/uploads/covers/.htaccess` is now allowlist-only; valid `.jpg/.jpeg/.png/.webp` are served with `nosniff`; SVG/HTML/XML/PHP/scripts are denied; directory listing denied.
- Added `.dev/uploads-test.php`; HTTP upload probes and helper tests passed.

### C-4 — development-file protection

- Added `campus-library/.htaccess` (root) and `campus-library/.dev/.htaccess`.
- Verified simulated XAMPP layout with temporary Apache on port 8081: `/.dev/.env`, `/.dev/admin-guard-test.php`, `/.dev/schema-test.sql`, `/.dev/docker-compose.yml`, `/config/config.php`, `/includes/functions.php`, `/database/database.sql`, `/storage/logs/app.log`, `/README.md`, `/TESTING.md`, `/public/.htaccess` → `403`; `/public/login.php` → `200`; directory listing → `403`.

### C-5 — session revocation on password reset

- Added `users.session_version` and migration.
- `auth.php` stores/compares session version in `current_user()`.
- `users.php` adds `update_user_password()`, `bump_user_session_version()`, `refresh_session_version_for_user()`.
- `profile.php`, `user-form.php`, `user-action.php` increment session version on password change/reset and deactivation.
- HTTP test passed: student old session → `302 /login.php`; admin session stays `200`; new password login works.

### C-6 — CSP/HSTS

- Added `security_headers()` and `strict_content_security_policy()` in `functions.php`.
- `handle_exception()` now links `style.css`; added error styles to `style.css`.
- `init.php` sends CSP/headers; HSTS only when HTTPS + `HSTS_MAX_AGE>0`.
- Unit header matrix and live header tests passed: CSP present; HSTS absent on HTTP; HSTS present when `HSTS_MAX_AGE=31536000` and HTTPS context enabled.

### C-7 — NUL-byte passwords

- `password_error()` rejects control characters.
- `login_user()` rejects control characters before `password_verify()`.
- Tests passed: registration with NUL returns `200` validation error and creates no user; login with NUL returns generic invalid-credentials page (`200`).

### C-8 — minor defects

- Student dashboard overdue uses `COALESCE(SUM(...), 0)`; real zero displayed.
- Admin overdue card is conditional (`stat--danger` only when overdue > 0).
- `admin/users.php` search escapes `%`/`_`.
- Setup locked POST now returns `403`.
- Tests passed: `phase21_c8.py`.

### C-9 — mobile navigation

- Added `public/assets/js/boot.js` (`js` class).
- `header.php` references `boot.js`.
- CSS now has `html:not(.js)` stacked sidebar and `html.js` off-canvas sidebar with `visibility: hidden` while closed.
- Static tests passed; browser/no-JS execution not run.

### Additional findings

- C-10: implemented `login_failed` audit logging; session-only login throttle remains (deferred).
- C-11: documented base-aware `ErrorDocument` behavior and `APP_BASE_PATH`.
- C-12: fixed and tested.
- C-13: documented pre-production checklist; not enforced.

## 5. Schema amendment and rationale

Full rationale and exact strategy: `DATABASE_INTEGRITY.md` §1–3.

- Original approved invariants: `PHASE1_PLAN.md` lines 451, 457, 459, 479, 486.
- MariaDB `ERROR 1901` was reproduced: generated columns/CHECKs referencing FK columns fail when FK uses `ON UPDATE CASCADE`.
- Replacement strategy: keep nullable unique FKs `reserved_copy_id` and `active_copy_id`; enforce state machine with Rules A–H transactions; audit queries detect residual direct-SQL violations.

## 6. Rules A–H implementation mapping

| Rule | File/function | Lock order | Row-count checks |
| --- | --- | --- | --- |
| A | `circulation.php::circulation_approve_request()` | `book_copies → borrow_requests` | Copy and request updates require `rowCount() = 1`. |
| B | `circulation_close_request()` | `book_copies → borrow_requests` | Copy and request updates require `rowCount() = 1`. |
| C | `circulation_fulfil_request()` | `book_copies → borrow_requests` | Request and copy updates require `rowCount() = 1`; insert requires non-zero `lastInsertId()`. |
| D | `circulation_return_borrowing()` | `book_copies → borrow_requests → borrowings` | Borrowing and copy updates require `rowCount() = 1`. |
| E | `circulation_report_lost_copy()` | Uses Rule D ordering. | Same as Rule D. |
| F | `circulation_change_copy_status()` | `book_copies` | Copy update requires `rowCount() = 1`. |
| G | Embedded in all rules | Global `book_copies → borrow_requests → borrowings → fines`. | All expected writes assert `rowCount()`. |
| H | `circulation_audit_queries()`/`circulation_audit()` | READ-only. | Used in tests/periodic audits. |

State matrix: `DATABASE_INTEGRITY.md` §5 (same as closeout review).

## 7. §5.4 audit query results

Executed via `circulation_audit($pdo)` and `.dev/circulation-test.php audit`:

- Result: **PASS — violations: []** on the final restored database.

Schema integrity regression: `.dev/schema-test.sql` executed through MariaDB `--force`:

- Expected intentional errors: `ERROR 1062` on `uq_active_request_per_title`, `uq_reserved_copy`, `uq_active_borrowing_copy`, and `ERROR 4025` on `chk_returned_has_date`, plus `ERROR 1452`.
- Observed rollback state: `schema_test_rollback_complete 0 0`.

Direct residual probes used inside transactions and rolled back:

- Approved request without copy → audit `approved_request_without_copy` flags it.
- Rejected request with reservation → audit `non_approved_request_with_reservation` flags it.
- Active borrowing with `active_copy_id <> book_copy_id` → audit `active_borrowing_copy_status_disagreement` flags it.
- Returned borrowing retaining `active_copy_id` → audit `returned_borrowing_with_active_key` flags it.

## 8. Tests actually executed and results

| Test | Command/environment | Result |
| --- | --- | --- |
| PHP lint | `docker compose exec -T web sh -lc 'cd /var/www/html/campus-library && find . -name "*.php" -print0 \| xargs -0 -n1 php -l'` | PASS; no syntax errors in all PHP files. |
| Schema integrity | `Get-Content -Raw -Path .dev/schema-test.sql \| docker compose exec -T db sh -c 'mariadb ... campus_library --force'` | PASS; rollback state `0 0`. |
| Circulation basic | `docker compose exec -T web php -f .dev/circulation-test.php basic` | PASS. |
| Circulation invalid prevention | `.dev/circulation-test.php invalid_prevention` | PASS. |
| Circulation rollback | `.dev/circulation-test.php rollback` | PASS. |
| Circulation audit | `.dev/circulation-test.php audit` | PASS; `violations: []`. |
| Concurrent approve | `.dev/circulation-test.php concurrent` | PASS; exactly one success, at least one conflict, copy reserved. |
| Upload HTTP | `python C:\Users\leon2\AppData\Local\Temp\opencode\phase21_upload_tests.py` | PASS; valid PNG/JPEG/webp-not-tested, SVG/PHP/mismatch denied, directory listing 403. |
| Upload helper | `.dev/uploads-test.php` | PASS; valid PNG ok; evil.svg/jpg/html, mismatch, oversize errors. |
| Admin password reset session revocation | `python C:\Users\leon2\AppData\Local\Temp\opencode\phase21_session_revocation.py` | PASS; old session `302 /login.php`; admin `200`; new login works. |
| Security headers unit | `.dev/security-headers-test.php`, `HSTS_MAX_AGE=31536000` variant | PASS. |
| Security headers HTTP | `python C:\Users\leon2\AppData\Local\Temp\opencode\phase21_http_headers.py` | PASS; CSP present; HSTS absent over HTTP. |
| Split layout | `.dev/split-layout-test.php` and `APP_PUBLIC_PATH=/tmp/opencode/split-public` | PASS; `COVER_UPLOAD_DIR` follows env; `abort_403()` renders without `APP_ROOT/public`. |
| C-4 Docker/XAMPP | `docker compose ...` + temporary Apache 8081 | PASS; protected paths 403, `/public/login.php` 200, listing 403. |
| C-8 dashboard/search/setup | `python ...phase21_c8.py` | PASS. |
| C-9 mobile static | `python ...phase21_c9_static.py` | PASS. |
| C-7 NUL password | `python ...phase21_nul_password.py` | PASS. |
| HTTP regression | `python ...phase21_regression.py` | PASS; login roles, 403/405, CSRF, setup, link crawl errors none. |
| Failed-login audit | Implemented; no dedicated assertion (smoke login checks passed). |
| Browser no-JS | Not executed; CSS/HTML static checks only. |
| InfinityFree | Not tested. |
| MySQL 8 | Not tested; MariaDB 10.11 only. |

## 9. Security and deployment verification

- Role guards and 403/404/405 behavior: regression script passed.
- CSRF: missing token POST to admin/user-action returns 403; GET logout/user-action returns 405.
- Setup locked GET/POST returns 403.
- ErrorDocument paths: root layout works; subdirectory documented and tested only by static/README review.
- Cookie flags: `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS detected; `SESSION_COOKIE_SECURE_OVERRIDE` added.
- Headers: CSP, nosniff, XFO, Referrer-Policy, Permissions-Policy present; HSTS opt-in when HTTPS and `HSTS_MAX_AGE>0`.

## 10. Deferred issues and residual risks

| ID | Status | Reason/evidence |
| --- | --- | --- |
| C-10 | DEFERRED | Session-only login throttle remains. Failed-login audit logging is implemented. |
| C-11 | DOCUMENTED | Root-absolute ErrorDocument paths; subdirectory instructions added. |
| C-13 | DOCUMENTED | Pre-production checklist: `APP_ENV=production`, demo accounts, clear `SETUP_TOKEN`, confirm private paths not downloadable. |
| HSTS/HTTPS | RESIDUAL | Opt-in via `HSTS_MAX_AGE`; not exercised over real HTTPS. |
| MySQL 8 | NOT TESTED | Only MariaDB 10.11 available; MySQL 8 CHECK enforcement untested. |
| Browser no-JS | NOT TESTED | CSS/HTML static verification only; real browser not available/executed. |
| InfinityFree | NOT TESTED | Production deployment not attempted. |
| Traceability | RESIDUAL | `include_once`/`include` paths are not call-path audited for every future function. |

## 11. Files created, changed, deleted

### Created

- `DATABASE_INTEGRITY.md`
- `PHASE2_1_CHECKLIST.md`
- `PHASE2_1_CLOSEOUT_REPORT.md`
- `includes/circulation.php`
- `includes/uploads.php`
- `database/migrations/0001_add_users_session_version.sql`
- `.dev/.htaccess`
- `campus-library/.htaccess`
- `public/uploads/covers/.htaccess`
- `.dev/circulation-test.php`
- `.dev/uploads-test.php`
- `.dev/split-layout-test.php`
- `.dev/security-headers-test.php`

### Changed

- `config/config.php`, `database/database.sql`
- `includes/functions.php`, `includes/auth.php`, `includes/activity.php`, `includes/authorization.php`, `includes/init.php`, `includes/header.php`, `includes/users.php`
- `public/login.php`, `public/register.php`, `public/index.php`
- `public/admin/dashboard.php`, `public/admin/users.php`, `public/admin/user-form.php`, `public/admin/user-action.php`
- `public/account/profile.php`, `public/student/dashboard.php`
- `public/setup-admin.php`, `public/assets/css/style.css`, `public/assets/js/app.js`
- `README.md`, `TESTING.md`
- `public/.htaccess`

### Deleted

- None.

## 12. Git status and commit information

- No Git repository exists; no branch/commit/push information is available.
- Database was restored to final state: 3 users, 0 borrow requests, 0 borrowings, 12 books, 22 copies, `activity_logs` 3, `session_version=1` for all 3 users.

## 13. Phase 3 readiness

Phase 3 is **conditionally ready**: catalogue and routing foundations are unaffected; circulation Rules A–H are implemented, audited and tested, and the explicit residual risks above were accepted for entry. Begin Phase 3 only after explicit user approval, and address C-10 if server-side login throttling is required before public deployment.
