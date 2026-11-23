# Phase 7 — Security Audit — Campus Library Management System

Date: 2026-11-21. Type: **read-only audit**. No secrets, password hashes, credentials or personal data are reproduced. No live data was modified.

## 0. Scope and evidence

- Source inspected: `public/`, `includes/`, `config/`, `.htaccess` files, `database/database.sql`, Git tracked files and history (`git ls-files`, `git log --all`), deployment/package reports, plus read-only live HTTP/browser checks on `https://mmust-library.site.je`.
- Live checks performed: guest pages, public assets, error pages, private `_private/` URLs, response security headers, error-page content. **No login, registration, form submission or production transaction was performed.**
- Severity scale: Critical / High / Medium / Low / Informational. PASS means verified by code evidence and/or execution.

## 1. Password and account security — PASS

| Requirement | Result | Evidence |
|---|---|---|
| `password_hash()` used | PASS | `public/register.php:60`, `public/setup-admin.php:106`, `includes/users.php:281-284`, `public/admin/user-form.php:153,181` — all `PASSWORD_DEFAULT` |
| `password_verify()` on login | PASS | `includes/auth.php:61`; rehash on algorithm change `:78-82`; dummy hash for timing equalisation `:9,49-58` |
| No plaintext storage / logging / response / frontend | PASS | All writes target `password_hash`; grep found no password values in logs, responses, JS or source. `AUTH_DUMMY_HASH` (`auth.php:9`) is a timing dummy, not a credential. Demo-credential banner is gated by `show_demo_credentials()` (`includes/functions.php:213-223`): `APP_ENV === 'development'` **and** localhost host (`:196-205`); live homepage confirmed no banner. |
| Password policy | PASS | `password_error()` (`functions.php:228-252`): ≥8 chars, ≤72 **bytes**, one letter, one digit, no control chars; login rejects NUL bytes (`auth.php:40-45`) |
| Email validation / duplicates | PASS | `FILTER_VALIDATE_EMAIL`, ≤190 chars (`includes/users.php:157-159`); unique indexes `uq_users_email`, `uq_users_student_number` (`database.sql:73-74`); duplicate INSERT handled as 23000/1062 (`register.php:79-86`) |
| Session ID regeneration | PASS | `auth.php:84` (login), `:226` (logout), `:192,200` (inactive / version mismatch), `init.php:56` (idle expiry) |
| Cookie flags | PASS (code + local) | `init.php:18-42`: HttpOnly, SameSite=Lax, Secure auto-detected from HTTPS with override (`config.php:140-141`), strict mode, cookies-only, session-only |
| Logout invalidation | PASS | `logout.php:7-12` POST+CSRF → `logout_user()` (`auth.php:210-227`) clears session and regenerates ID |
| Password column | PASS | `database.sql:62`: `password_hash VARCHAR(255) NOT NULL`; also `session_version INT UNSIGNED NOT NULL DEFAULT 1` (`:63`) |
| Server-side auth enforcement | PASS | 43 guard call sites; `require_login`/`require_role`/`require_staff` (`authorization.php:14-56`); local cross-role matrix: 0 failures |
| Login abuse protection | PASS | Durable DB throttle (`login_throttles`, `database.sql:310-317`): 5 failures / 900 s, SHA-256 of normalised email + IP (`auth.php:107-156`) |

Live cookie flags could not be confirmed because no production login was performed (browser network capture of the login page returned no `Set-Cookie`). Code and local simulation confirm the flags.

## 2. Configuration and sensitive data — PASS with one mitigated concern

- `config.local.php` is loaded from `__DIR__ . '/config.local.php'` (`config/config.php:17-25`), is Git-ignored (`.gitignore:5`), has never been tracked, and does not exist in this workspace. Environment variables take precedence (`:27-41`).
- Database credentials default to empty and fail closed: `config/database.php:10-17` returns a generic 500 page with no credentials, SQL or paths; connection uses native prepares (`:27-33`).
- No credential-like value was ever tracked in `config.php`/`database.php` history; no `.env`, `.dev/` or `config.local.php` in `git ls-files` or added-file history.
- **Email / SMTP:** no email functionality exists. No `mail()`, SMTP library, sendmail or API key anywhere in PHP; no SMTP credentials in source, docs or history. Documented as out of scope (`README.md:59-65`, `PHASE6_DEPLOYMENT_AUDIT.md:36,55`).
- Live verification: `/_private/config/config.php`, `/_private/config/config.local.php`, `/_private/includes/init.php`, `/_private/database/migrations/0003_durable_login_throttle.sql`, `/_private/storage/logs/.htaccess` all returned **404** with no config content; CSP, `nosniff`, `X-Frame-Options: SAMEORIGIN`, Referrer-Policy and Permissions-Policy present; no HSTS (consistent with `HSTS_MAX_AGE=0`). Error pages contained no SQL, paths or credentials.

| ID | Severity | Finding | Evidence / mitigation |
|---|---|---|---|
| S-1 | **Medium (mitigated)** | In the InfinityFree layout, `config.local.php` lives physically inside the document root at `htdocs/_private/config/`. Protection depends on `.htaccess` being honoured. | `PHASE6_3_...:12-23,41`; `PHASE6_4_...:52-54,76-84`. **Live check passed** (all private URLs 404, no content leaked). Keep deny rules and re-verify after every upload. |
| S-2 | Low | `APP_DEBUG=true` renders the escaped exception message to users, which could contain DB host/db name/SQL fragments or paths. | `config/config.php:44,151-152`; `functions.php:371-373`. Default is false; live error pages showed no leakage. Never enable in production. |
| S-3 | Low | Full exception message, file/line and stack trace are written to `storage/logs/app.log` (may include DB host/paths, not the password). | `functions.php:351-361`; logs HTTP-denied by `storage/logs/.htaccess`. Restrict filesystem permissions. |
| S-4 | Low | No rate limit on self-registration or setup-token submission (login is throttled). | `register.php:21-75`, `setup-admin.php:33-57`. Setup is single-use and token-gated; consider registration throttling. |
| S-5 | Informational | Upload controls (extension/MIME/content allowlist, randomised names, PHP execution denial) are implemented, but **no upload endpoint exists** (`$_FILES` absent). | `includes/uploads.php:5-133`, `public/uploads/covers/.htaccess:9-51`. Preventive only. |
| S-6 | Informational | The local-config loader and fail-closed DB check are uncommitted working-tree changes; committed HEAD uses env vars only. | `git diff --stat`; `git show HEAD:config/config.php`. Commit when authorised so deployment source matches reviewed code. |
| S-7 | Low (operational) | Live homepage advertises "Set up the first administrator" while `/setup-admin.php` returns HTTP 403 "setup already completed" — inconsistent state, likely stale page cache; the public CTA should be removed regardless. | Live fetch of `/index.php` (CTA links present twice) and `/setup-admin.php` (403, "already been completed"). Root cause NOT TESTED. |

## 3. Authorization and application security — PASS

- **Role separation:** local matrix covered 16 protected URLs unauthenticated (all 302/403), admin+staff URLs as student (all 403), admin URLs as librarian (all 403), and student URLs as staff/admin (all 403). `AUTHZ_TESTS PASS`, 0 failures.
- **Direct URL access:** no bypass found; every protected controller calls a guard at the top (e.g. `admin/dashboard.php:7`, `staff/issue-action.php:7`, `catalogue/request.php:7`).
- **Object ownership:** student records are scoped by session user (`catalogue/history.php:9`, `catalogue.php:183-186`); profile shows only the logged-in user's own email — locally verified. `require_student_owner()` exists (`authorization.php:69-74`) but is not called from `public/`; no object-id-based student page exists that would require it.
- **CSRF:** `includes/csrf.php` (32-byte session token, `hash_equals`, `abort_403`, rotation on login at `auth.php:88`). Present on all state-changing POSTs inspected. Local probes: POST without token to `/catalogue/request.php`, `/account/profile.php`, `/staff/request-action.php`, `/admin/settings-action.php` all returned **403** with no state change.
- **SQL injection:** all user-influenced queries use PDO placeholders with bound values (`auth.php:16-31`, `catalogue.php:41-93`, `users.php:30-83`, `reporting.php:122-170`, etc.). Raw `query()` calls use constant SQL only. No injection vector identified.
- **Output escaping:** `e()` = `htmlspecialchars(..., ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')` (`functions.php:12-15`); consistently applied to DB/user output on inspected pages.
- **Error handling:** `APP_DEBUG=false` by default; generic 500 pages (`functions.php:402-441`, `database.php:14-15`); live error pages leaked no SQL/paths/credentials.
- **Uploads:** see S-5. Directory denies scripts, listings and PHP execution; images served with `nosniff`.
- **Rate limiting:** login only (5/900 s) — see S-4.

## 4. Personal data exposure — PASS

- No student-facing page displays another student's email. Student dashboard, fines, catalogue and profile contain no other-user email fields; profile shows only the current user's own (disabled) email.
- Privileged roles can see emails: `admin/users.php:70,178` (admin list), `staff/returns.php:20` selects `student_email` but does **not** render it, and `includes/reporting.php:152-169` exposes it to staff/admin report pages only. This is role-gated and consistent with library operations; consider redaction in CSV exports if not needed.
- No personal data or hashes are reproduced in this report.

## 5. Verdict

No **Critical** or **High** severity security issue was identified. The application has strong foundations: hashed passwords, prepared statements, CSRF on all state-changing endpoints, server-side role guards, secure session settings, protected configuration, denied private paths (verified live) and no committed secrets or SMTP credentials. The main security-relevant concerns are the InfinityFree private-config placement (mitigated and live-verified), debug-mode leakage risk, missing registration throttling, and the stale public setup CTA. Functional defects with availability/integrity impact are documented in `PHASE7_FUNCTIONAL_AUDIT.md`.
