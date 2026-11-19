# Phase 2 Closeout Review

**Review date:** 19 November 2026  
**Scope:** Independent verification of the Phase 2 foundation against `PHASE1_PLAN.md` and the Phase 2 acceptance criteria.  
**Environment:** Local Docker stack only: PHP 8.2.35, Apache 2.4.69, MariaDB 10.11.19, application at `http://localhost:8090/`.  
**Review basis:** Source inspection of every application PHP file, the complete SQL schema, `.htaccess` files, CSS and JavaScript; PHP lint; HTTP behaviour tests; database probes; and independent concurrency tests.  
**Application code changed during this review:** **None.** Only this report was added.

> **Independence note:** The previous completion report was not treated as evidence. Every property below is either directly inspected in source or was reproduced with an executed test. Where a property could not be tested in this environment, it is explicitly marked as untested.

---

## 1. Files inspected

### Configuration and database

- `config/config.php`
- `config/database.php`
- `config/.htaccess`
- `database/database.sql`
- `database/.htaccess`
- `.dev/.env` (values not reproduced in this report)
- `.dev/Dockerfile`
- `.dev/docker-compose.yml`
- `.dev/php.ini`
- `.dev/000-default.conf`
- `.dev/admin-guard-test.php`
- `.dev/schema-test.sql`
- `.gitignore`

### Shared includes

- `includes/init.php`
- `includes/functions.php`
- `includes/auth.php`
- `includes/authorization.php`
- `includes/csrf.php`
- `includes/activity.php`
- `includes/users.php`
- `includes/header.php`
- `includes/sidebar.php`
- `includes/footer.php`
- `includes/alerts.php`
- `includes/pagination.php`
- `includes/.htaccess`

### Public pages and assets

- `public/index.php`
- `public/login.php`
- `public/logout.php`
- `public/register.php`
- `public/setup-admin.php`
- `public/403.php`
- `public/404.php`
- `public/405.php`
- `public/500.php`
- `public/.htaccess`
- `public/admin/dashboard.php`
- `public/admin/users.php`
- `public/admin/user-form.php`
- `public/admin/user-action.php`
- `public/staff/dashboard.php`
- `public/student/dashboard.php`
- `public/account/profile.php`
- `public/assets/css/style.css`
- `public/assets/css/print.css`
- `public/assets/js/app.js`
- `public/uploads/covers/.htaccess`

### Documentation inspected for consistency

- `PHASE1_PLAN.md` (particularly the Phase 2 scope and the `borrow_requests` / `borrowings` schema sections)
- `README.md`
- `TESTING.md`

**PHP lint:** all 30 application PHP files plus `.dev/admin-guard-test.php` passed `php -l` under PHP 8.2.35 (31 files total).

---

## 2. Confirmed strengths

The following are confirmed by source inspection and, where stated, by executed tests.

### 2.1 Authentication and session handling

- Passwords use `password_hash(PASSWORD_DEFAULT)` and `password_verify()`.
- Unknown-email login performs a dummy `password_verify()` to reduce timing differences (`includes/auth.php`).
- Failed login returns the same message whether the email is unknown, the password is wrong, or the account is inactive.
- Inactive accounts are rejected at login and are re-read from the database on every authenticated request (`current_user()`), so deactivation takes effect on the next request.
- Session ID is regenerated on login and logout.
- Session cookies are `HttpOnly`, `SameSite=Lax`, cookies-only, and `Secure` when HTTPS is detected.
- `session.use_strict_mode` is enabled.
- Idle timeout is enforced at one hour and is sliding (`includes/init.php`).
- CSRF token is random, compared with `hash_equals()`, and rotated after login and after a password change.
- Logout is POST-only and CSRF-protected.
- Password hashes are rehashed automatically when PHP's default algorithm changes.

**Independently reproduced:** login redirects to the correct dashboard; CSRF token changes after login; logout destroys access; a rewritten session with an expired `last_activity` is redirected to `login.php`; deactivated accounts cannot sign in and their existing session is revoked on the next request.

### 2.2 Authorization

- Every protected page calls a server-side guard: `require_login()`, `require_role()`, or `require_staff()`.
- The student dashboard additionally checks the role explicitly.
- `require_student_owner()` exists for future record-level ownership checks.
- Unauthorized roles receive the application's 403 page, not a redirect or a hidden-link-only control.
- Error pages are renderable without the normal database-dependent layout.

**Independently reproduced:** student access to `/admin/users.php`, `/admin/dashboard.php`, `/admin/user-form.php`, `/admin/user-action.php` and `/staff/dashboard.php` returns 403. The student also remains blocked when posting to an admin action with a valid CSRF token.

### 2.3 CSRF and request-method controls

- Every state-changing form includes `csrf_field()`.
- `verify_csrf()` rejects missing, non-string, empty and wrong tokens.
- `logout.php` and `admin/user-action.php` reject non-POST requests with 405.
- The check order is authentication → method → CSRF, which is correct: an anonymous POST is redirected to login before CSRF is evaluated, while an authenticated request without a token gets 403.

**Independently reproduced:** POST to login, register, setup, logout, admin user form and admin user action without a token is rejected for authenticated requests; GET on logout and user-action returns 405.

### 2.4 Initial administrator setup

- Setup is disabled entirely when `SETUP_TOKEN` is empty.
- The token comes from protected configuration/environment, never from the page or database.
- Token comparison uses `hash_equals()`.
- The setup transaction acquires the administrator role lock, re-reads administrators with a locking read, and locks the settings row before inserting.
- Setup records `library_settings.setup_completed_at` in the same transaction.
- A duplicate-key failure is converted into a field error.
- Once an administrator exists or setup is complete, the page is locked.

**Independently reproduced:** locked `GET /setup-admin.php` returns 403; a valid-token POST after completion returns “already completed”; no second administrator is created. A scratch-database race with three concurrent setup transactions produced exactly one administrator and one `setup_completed_at`; the other workers reported `locked`.

### 2.5 Last-administrator protection

- Administrative write paths use a consistent lock order: administrator role row first, then the target user row, then a locking read of active administrators.
- Self-demotion and self-deactivation are blocked in the UI and re-checked inside the transaction.
- The last active administrator cannot be demoted or deactivated.
- The user list disables the deactivate button for the last active administrator, but the server check is the real control.

**Independently reproduced:** self-deactivation and self-demotion are blocked and leave the account unchanged. A two-worker race deactivated the second administrator and then blocked deactivation of the remaining administrator (`active_admins = 1`).

### 2.6 Validation and data handling

- Names, emails, roles, statuses, student numbers, phone numbers and departments are length- and format-validated.
- Email addresses are lower-cased and trimmed; student numbers are upper-cased and whitespace-stripped.
- Student numbers are required for students and cleared for staff roles.
- Password policy requires at least 8 characters, at least one letter and at least one number, with a 72-byte upper bound.
- Uniqueness is checked before insert and is also protected by database unique keys; duplicate-key errors are caught and shown as form errors.
- All SQL uses native prepared statements; filter values are whitelisted before use.
- Output is escaped through `e()`/`htmlspecialchars()` with `ENT_QUOTES | ENT_SUBSTITUTE`.

**Independently reproduced:** duplicate email rejected; short profile name rejected; profile ignores an `id` parameter and shows only the session user's account; admin can create, deactivate and reactivate a student; wrong current password rejected; old password stops working after a change; new password works.

### 2.7 Auditing and error handling

- Activity logging is append-only in normal operation and is called after successful operations.
- Logging failure is caught and does not break a successful user operation.
- The global exception handler logs technical details and shows a generic 500 page; `APP_DEBUG` defaults to false.
- 403, 404, 405 and 500 pages exist, and 403/404 render the application error layout rather than the Apache default.

**Independently reproduced:** unknown paths return the application 404 page; denied directory access returns the application 403 page, not an Apache default page.

### 2.8 Interface and navigation

- All statistics on the admin, staff and student dashboards are queried from the database; no fake or hard-coded counts were found.
- Empty states are honest about Phase 3 features.
- A crawl of the reachable pages found 27 internal links and no broken links, no redirects to unexpected pages, and no POST form missing a CSRF token.
- CSS and JavaScript have no external network dependencies.

---

## 3. Actual defects

These are behaviours that are wrong as implemented, not merely hardening opportunities.

### D-1 — Student dashboard displays a blank “Overdue” value when there are no active loans

**Severity:** Low (UI correctness)  
**File:** `public/student/dashboard.php`, lines 35–43 and 110–113

The query uses:

```sql
SUM(CASE WHEN due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_loans
```

With zero matching active loans, `SUM()` returns `NULL`, not `0`. The template then renders:

```php
<?= e($loanCounts['overdue_loans']) ?>
```

`e(NULL)` produces an empty string, so the “Overdue” statistic is blank.

**Independently reproduced:** the student dashboard shows “Overdue” with no numeric value when the student has no loans. The other statistics correctly show `0`.

**Correction:** use `COALESCE(SUM(...), 0)` in SQL or coalesce in PHP before rendering. Add a regression test for a student with zero active loans.

### D-2 — A password containing a NUL byte causes an unhandled 500

**Severity:** Low–Medium (input robustness)  
**Files:** `includes/functions.php` (`password_error()`), `public/register.php` line 60, `public/account/profile.php` line 87, `public/admin/user-form.php` lines 153/181, `public/setup-admin.php` line 106

`password_error()` checks length, letter and digit but does not reject control characters. PHP's bcrypt implementation throws:

```text
ValueError: Bcrypt password must not contain null character
```

when `password_hash()` receives a NUL byte. The exception reaches the global handler and produces a 500 page instead of a field validation error.

**Independently reproduced:** a registration POST with password `Abcd1234\x00` returned HTTP 500 and the generic “Something went wrong” page. No user was created. `storage/logs/app.log` recorded:

```text
Bcrypt password must not contain null character in .../public/register.php:60
```

**Correction:** reject NUL and other control characters in `password_error()` (and ideally in `validate_user_management_input()` before hashing), and/or catch `ValueError` around every `password_hash()` call and convert it to a password field error. Add an HTTP regression test.

### D-3 — Mobile navigation is unusable without JavaScript

**Severity:** Low–Medium (accessibility / progressive enhancement)  
**Files:** `public/assets/css/style.css` lines 1466–1496; `public/assets/js/app.js` lines 12–39; `includes/header.php` lines 44–48

At `max-width: 860px`, `.sidebar` is fixed off-canvas with `transform: translateX(-102%)`. It only becomes visible when JavaScript adds `body.nav-open`. Without JavaScript, the sidebar toggle button is visible but has no handler, so mobile users cannot reach navigation links at all.

The same CSS also leaves the off-canvas links in the tab order when the sidebar is closed; there is no `visibility: hidden`, `inert` or equivalent. Keyboard users can therefore tab into links that are not visible.

**Independently reproduced by source/CSS inspection.** A real browser with JavaScript disabled was not available in this environment, but the CSS and JS relationship is unambiguous.

**Correction:** provide a no-JS fallback (for example, make the sidebar a normal stacked block by default and let JavaScript opt into the off-canvas behaviour), and make the closed sidebar non-focusable (`inert`, `visibility: hidden` after transition, or equivalent). Add a keyboard/no-JS test.

### D-4 — The upload directory serves SVG as active content

**Severity:** Medium (latent stored XSS; becomes relevant when cover uploads ship)  
**Files:** `public/uploads/covers/.htaccess` lines 14–23; `config/config.php` lines 59–65

The upload `.htaccess` denies a blacklist of executable extensions (`.php`, `.phtml`, `.phar`, `.pl`, `.py`, `.cgi`, `.sh`, `.bash`, `.asp`, `.aspx`, `.jsp`, `.htaccess`) and dotfiles, but does not deny `.svg`, `.xml`, `.html` or `.xhtml`. A file placed in `public/uploads/covers/` is served directly.

**Independently reproduced:** a temporary `review-probe.svg` containing a `<script>` element was requested and returned `200` with `Content-Type: image/svg+xml`, body intact. `.php` and `.phtml` probes returned 403. The probe file was removed.

Phase 2 has no upload handler yet, and `COVER_ALLOWED_MIME_TYPES` lists only JPEG, PNG and WebP. The defect is therefore latent, but the directory is already prepared for Phase 3 and the current `.htaccess` blacklist is not a sufficient defence if an SVG, HTML or XML file ever reaches it.

**Correction:** use an allowlist, not a blacklist. Deny all files in the upload directory except the permitted image extensions/MIME types, or store covers outside the web root and stream them through a PHP endpoint with a fixed image content type and `Content-Disposition`. Explicitly deny `.svg`, `.svgz`, `.xml`, `.html`, `.htm`, `.xhtml` and any script-capable type. Add an upload security test.

### D-5 — The documented production layout conflicts with `APP_ROOT/public` paths

**Severity:** High for production deployment; not observable in the Docker layout  
**Files:** `README.md` lines 195–204; `config/config.php` lines 9 and 59; `includes/authorization.php` lines 84, 96, 109

`README.md` instructs the deployer to:

1. upload only the **contents of `public/`** into the web root (`htdocs`), and
2. place `config/`, `includes/`, `database/` and `storage/` **outside** the web root.

But the code assumes a `public/` directory still exists under `APP_ROOT`:

- `COVER_UPLOAD_DIR` is `APP_ROOT . '/public/uploads/covers'`.
- `abort_403()`, `abort_404()` and `abort_405()` require `APP_ROOT . '/public/403.php'`, `404.php` and `405.php`.

If only `public/` contents are uploaded to `htdocs` and the private directories are placed outside, then `APP_ROOT/public/...` does not exist. Consequences include:

- an unauthorized admin request can fail with a 500/fatal error instead of rendering 403;
- cover uploads would target a non-existent directory;
- the application error pages may not load at all.

This is a real deployment blocker and a documentation/code inconsistency. It does not affect the current Docker stack because `public/` is the document root and `APP_ROOT/public/` exists.

**Correction:** choose one canonical layout and make the code independent of it. Recommended: introduce a separate `APP_PUBLIC_PATH` constant (environment-overridable, default `APP_ROOT . '/public'`), use it for error documents and uploads, and document both Docker and shared-hosting layouts. Better still, move error rendering into `includes/` and store uploads outside the web root so the public tree can be uploaded alone. Update `README.md` to match the code.

### D-6 — `.dev/` has no access-control file and is exposed in the documented XAMPP layout

**Severity:** Medium in development; High if the project is placed under a public `htdocs` on a reachable host  
**Files:** `.dev/.env`, `.dev/admin-guard-test.php`, `.dev/schema-test.sql`; absence of `.dev/.htaccess`

`README.md` documents an XAMPP layout where the whole `campus-library/` directory sits under `htdocs` and the site is opened at `.../campus-library/public/`. In that layout, `.dev/` is inside the web root. It has no `.htaccess`, so `.dev/.env` (database passwords and the setup token), the dev test script and the schema test file would be served or executable by anyone who can reach the host.

Under Docker the risk is not present: the document root is `public/`, so `/.dev/.env` returns 404 (confirmed). The README correctly says `.dev/` is not deployed, but the local XAMPP layout still exposes it if the host is reachable.

**Correction:** add a deny-all `.dev/.htaccess` (same pattern as `config/.htaccess`) and keep `.dev/` outside the web root where possible. Never expose `.dev/.env` or test scripts. Consider moving the dev stack outside the application tree, or add a root-level deny rule for `.dev`, `README.md`, `TESTING.md` and `PHASE1_PLAN.md` in the XAMPP layout.

### D-7 — Administrator password reset does not revoke an existing session

**Severity:** Medium (security; confirmed behaviour)  
**Files:** `includes/auth.php` (`current_user()`), `public/admin/user-form.php` (password reset), `public/account/profile.php` (password change)

`current_user()` re-reads the user row and status on every request, but it does not compare a password version or password-change timestamp. Changing a password (self-service or administrator reset) therefore leaves every existing session for that account valid until the one-hour idle timeout.

**Independently reproduced:** an administrator created a student, the student signed in, the administrator reset the student's password through `admin/user-form.php`, and the student's existing session still returned HTTP 200 for `/student/dashboard.php`. The test user was deleted and the database restored.

**Correction:** add a `session_version` (or `password_changed_at` / `credentials_changed_at`) column to `users`, increment it on password change, administrator password reset and deactivation, and store the issued version in the session. `current_user()` should reject a session whose version no longer matches. Alternatively, use a sessions table and delete the account's sessions. Add a regression test.

---

## 4. Security concerns

### 4.1 No critical authentication or authorization bypass found

Independent HTTP testing did not find an authentication bypass, role-guard bypass, CSRF bypass, SQL injection, or setup/last-administrator race. The server-side guards are present on every protected page, and the setup and last-admin concurrency controls survived independent races.

### 4.2 Confirmed session-revocation gap

See D-7. Password changes and administrator password resets do not invalidate existing sessions. Deactivation does, because `current_user()` checks `status`. For a compromised account, a password reset alone is insufficient to remove the attacker.

### 4.3 Login throttle is session-only

`public/login.php` stores the failed-attempt counter in `$_SESSION`. An attacker can obtain a new session (clear cookies) and continue guessing; the throttle does not protect against distributed or cookie-clearing attacks. It also means the “15 minutes” lockout applies only to that browser session. This is a weak control, not a vulnerability in itself, but it should not be described as brute-force protection.

**Recommended:** add a server-side throttle keyed by normalized email and/or source IP, with a bounded store and audit log. Keep the session throttle as a UX layer.

### 4.4 Failed logins are not audited

`log_activity()` is called only after a successful login. Failed attempts (including throttled attempts) leave no audit record. This limits incident investigation.

**Recommended:** log failed and throttled attempts with normalized email, source IP, user agent and outcome. Avoid logging the submitted password.

### 4.5 No Content-Security-Policy or HSTS

Confirmed headers on a normal page:

```text
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
Content-Security-Policy: (absent)
Strict-Transport-Security: (absent)
```

The application has no CSP. Inline styles in `handle_exception()` would need to be externalised or allowed by hash/nonce before a strict CSP can be deployed. HSTS is absent; it should only be added once HTTPS is confirmed, and with a sensible max-age.

### 4.6 Development banner exposes demonstration credentials when `APP_ENV=development`

`public/index.php` lines 63–71 render the librarian and student demo emails/passwords when `APP_ENV === 'development'`. The Docker stack sets `APP_ENV=development`. This is intentional for local review, but a production deployment that forgets to set `APP_ENV=production` would publish credentials.

**Recommended:** keep the banner, but add a deployment check that refuses to start with `APP_ENV=development` on a non-local host, or make the banner opt-in rather than environment-default.

### 4.7 Cookie `Secure` detection behind a reverse proxy

`includes/init.php` sets `Secure` based on `$_SERVER['HTTPS']` or port 443. Behind a TLS-terminating proxy that does not set `HTTPS`, the cookie may be sent without `Secure`. This is a hosting-dependent concern; document it and consider an explicit `SESSION_COOKIE_SECURE` override.

### 4.8 Email enumeration

Registration and administrator user creation return distinct “already registered” messages for email/student number. This is standard UX but enables account enumeration. Login itself is correctly generic. If enumeration is a concern, use a neutral message and send a verification email, but that is a product decision.

### 4.9 Audit-log timing

Activity logs are written after the business transaction commits. A process failure between commit and log can lose the audit record. For Phase 3/4 circulation operations, consider writing the audit row inside the same transaction (accepting that log failure rolls back the operation) or using a durable outbox. The current “logging must not break the operation” policy is reasonable for Phase 2 but should be revisited for financial/stock-like operations.

### 4.10 No account recovery or MFA

There is no password-reset flow, no email verification and no MFA. These are outside the Phase 2 scope, but they matter before public production. The setup page text correctly says recovery must be handled by the site administrator.

---

## 5. Database integrity concerns

### 5.1 The implemented schema deviates from the approved Phase 1 schema

Phase 1 specified:

- `borrow_requests.reserved_copy_id` as a **generated** column, non-NULL while approved, with a unique index.
- A check constraint that an approved request must have an assigned copy.
- `borrowings.active_copy_id` as a **generated** column, non-NULL while active, with a unique index.

See `PHASE1_PLAN.md` lines 451, 457, 459, 479 and 486.

The implementation instead uses:

- `borrow_requests.active_request_key` generated from `status` (this one works).
- `borrow_requests.reserved_copy_id` as a plain nullable FK with a unique index.
- `borrowings.active_copy_id` as a plain nullable FK with a unique index.
- No check constraint tying `status = 'approved'` to a non-NULL reserved/assigned copy.

See `database/database.sql` lines 153–186 and 191–229; `TESTING.md` lines 87–109 documents the adaptation.

The reason is real and independently re-verified: MariaDB 10.11 rejects a generated column or CHECK constraint that references a foreign-key column when the FK uses `ON UPDATE CASCADE`:

```text
ERROR 1901: Function or expression 'parent_id' cannot be used in the
GENERATED ALWAYS AS clause of `active_parent_id`
```

A generated column worked when the FK did **not** use `ON UPDATE CASCADE`; a CHECK constraint referencing the FK column still failed. The implementation chose portable nullable keys instead of removing cascade behaviour.

**Assessment:** the deviation is technically justified for MariaDB, but it is a material difference from the approved design. It must be formally accepted as a Phase 1 amendment, or the schema must be changed to restore database enforcement. It is not a Phase 2 runtime defect because no Phase 2 code writes `borrow_requests` or `borrowings` yet, but it is the most important Phase 3 precondition.

### 5.2 Confirmed invalid states that the database currently accepts

The following probes were run inside transactions and rolled back. Each invalid state was accepted:

| Probe | Current result |
| --- | --- |
| Approved request with no assigned/reserved copy | Accepted |
| Rejected request with a reserved copy | Accepted |
| Two active borrowings for the same copy with `active_copy_id = NULL` | Accepted |
| Returned borrowing retaining `active_copy_id` | Accepted |
| Active borrowing whose `active_copy_id` differs from `book_copy_id` | Accepted |

This is the direct consequence of using plain nullable columns. The unique indexes only work when the columns are correctly populated.

### 5.3 What the database does enforce correctly

The following were independently reproduced:

- `uq_active_request_per_title` rejects a second pending/approved request for the same student and title (`ERROR 1062`).
- `uq_reserved_copy` rejects two non-NULL reservations of the same copy (`ERROR 1062`).
- `uq_active_borrowing_copy` rejects two non-NULL `active_copy_id` rows for the same copy (`ERROR 1062`).
- `chk_returned_has_date` rejects a returned borrowing without a return date.
- Foreign keys reject missing users/books/copies.
- `chk_borrowing_dates` enforces `due_date >= issue_date`.
- The other CHECK constraints (10 total) are present in `information_schema.check_constraints`.

So the schema has useful last-resort guards, but it does not enforce the full reservation/loan state machine.

### 5.4 Exact transaction rules for Phase 3

These rules must be implemented before any endpoint writes `borrow_requests` or `borrowings`. They are written for MySQL/MariaDB with InnoDB and `SELECT ... FOR UPDATE`.

#### Invariants to maintain

**Reservation invariants**

- **R1:** `borrow_requests.reserved_copy_id IS NOT NULL` **if and only if** `status = 'approved'`.
- **R2:** For an approved request, `reserved_copy_id = assigned_copy_id`, and both are non-NULL.
- **R3:** `reserved_copy_id` is unique among non-NULL values (one approved request per copy).
- **R4:** If `reserved_copy_id` is set, the referenced `book_copies.status` is `'reserved'`.
- **R5:** A copy cannot be reserved if it is `maintenance`, `archived`, `issued`, or already reserved by another approved request.
- **R6:** For `pending`, `rejected`, `cancelled` and `fulfilled`, `reserved_copy_id` must be `NULL`.
- **R7:** `assigned_copy_id` is historical: it may remain after fulfilment/rejection/cancellation for audit, but it must be non-NULL for an approved request and must reference a copy of the requested `book_id`.

**Borrowing invariants**

- **B1:** `borrowings.active_copy_id IS NOT NULL` **if and only if** `status = 'active'`.
- **B2:** For an active borrowing, `active_copy_id = book_copy_id`.
- **B3:** `active_copy_id` is unique among non-NULL values (one active loan per copy).
- **B4:** If a borrowing is active, the referenced `book_copies.status` is `'issued'`.
- **B5:** When a borrowing becomes `returned`, `active_copy_id` is set to `NULL` in the same statement, and the copy becomes `available` (or `maintenance`/`archived` if explicitly flagged).
- **B6:** `return_date` is non-NULL when `status = 'returned'` (enforced by `chk_returned_has_date`); for `active`, it must be `NULL`.
- **B7:** `request_id` is unique (one borrowing per request), and the linked request becomes `fulfilled` when the borrowing is created.

#### State matrix

| Request status | `reserved_copy_id` | `assigned_copy_id` | `book_copies.status` |
| --- | --- | --- | --- |
| `pending` | `NULL` | `NULL` | unchanged (normally `available`) |
| `approved` | copy ID | same copy ID | `reserved` |
| `rejected` | `NULL` | `NULL` or historical | `available` |
| `cancelled` | `NULL` | `NULL` or historical | `available` |
| `fulfilled` | `NULL` | the issued copy (historical) | `issued` until return, then `available` |

| Borrowing status | `active_copy_id` | `return_date` | `book_copies.status` |
| --- | --- | --- | --- |
| `active` | same as `book_copy_id` | `NULL` | `issued` |
| `returned` | `NULL` | non-NULL | `available` / `maintenance` / `archived` |

#### Rule A — Approve a pending request

Lock the copy first, then the request. Verify ownership, copy/title match, and copy availability before updating.

```sql
BEGIN;

SELECT id, book_id, status
FROM book_copies
WHERE id = :copy_id
FOR UPDATE;
-- require book_id = :book_id and status = 'available'

SELECT id, user_id, book_id, status, reserved_copy_id
FROM borrow_requests
WHERE id = :request_id
FOR UPDATE;
-- require status = 'pending'

-- Optional friendly pre-check; the unique key is the final guard.
SELECT id FROM borrow_requests
WHERE reserved_copy_id = :copy_id
LIMIT 1;

UPDATE book_copies
SET status = 'reserved'
WHERE id = :copy_id AND status = 'available';
-- require rowCount = 1

UPDATE borrow_requests
SET status = 'approved',
    assigned_copy_id = :copy_id,
    reserved_copy_id = :copy_id,
    reviewed_by = :staff_id,
    reviewed_at = NOW()
WHERE id = :request_id AND status = 'pending';
-- require rowCount = 1

COMMIT;
```

**Timing rule:** `status`, `assigned_copy_id` and `reserved_copy_id` are set in the **same UPDATE**. Do not commit an approved request without a reservation, and do not reserve a copy before the request is approved. If either update affects zero rows, roll back and report a conflict.

#### Rule B — Reject or cancel a request

```sql
BEGIN;

-- Non-locking read to discover the reservation before acquiring locks.
SELECT status, reserved_copy_id, assigned_copy_id
FROM borrow_requests
WHERE id = :request_id;
-- require current status in ('pending', 'approved')
-- do not allow cancellation of a fulfilled request that has an active borrowing

-- If reserved_copy_id is not NULL, lock the copy first (copy -> request order).
SELECT id, status
FROM book_copies
WHERE id = :reserved_copy_id
FOR UPDATE;

-- Then lock the request and re-verify after any wait.
SELECT id, status, reserved_copy_id, assigned_copy_id
FROM borrow_requests
WHERE id = :request_id
FOR UPDATE;
-- require status still in ('pending', 'approved')
-- require reserved_copy_id to be unchanged (NULL or :reserved_copy_id)

UPDATE book_copies
SET status = 'available'
WHERE id = :reserved_copy_id AND status = 'reserved';

UPDATE borrow_requests
SET status = :new_status,          -- 'rejected' or 'cancelled'
    reserved_copy_id = NULL
WHERE id = :request_id
  AND status IN ('pending', 'approved');
-- require rowCount = 1

COMMIT;
```

**Timing rule:** clear `reserved_copy_id` in the same UPDATE that changes the status to a terminal state. If `assigned_copy_id` is retained for history, document that decision; it must not be used as a reservation after the status leaves `approved`. If it is cleared, clear it in the same UPDATE.

#### Rule C — Fulfil an approved request (issue the copy)

```sql
BEGIN;

SELECT id, book_id, status
FROM book_copies
WHERE id = :copy_id
FOR UPDATE;
-- require status = 'reserved'

SELECT id, user_id, book_id, status, assigned_copy_id, reserved_copy_id
FROM borrow_requests
WHERE id = :request_id
FOR UPDATE;
-- require status = 'approved'
-- require reserved_copy_id = :copy_id
-- require assigned_copy_id = :copy_id

-- Optional friendly pre-check; the unique key is the final guard.
SELECT id FROM borrowings
WHERE active_copy_id = :copy_id
LIMIT 1;

INSERT INTO borrowings
    (request_id, user_id, book_copy_id, issued_by,
     issue_date, due_date, status, active_copy_id)
VALUES
    (:request_id, :user_id, :copy_id, :staff_id,
     CURDATE(), DATE_ADD(CURDATE(), INTERVAL :loan_days DAY),
     'active', :copy_id);

UPDATE borrow_requests
SET status = 'fulfilled',
    reserved_copy_id = NULL
WHERE id = :request_id AND status = 'approved';
-- require rowCount = 1

UPDATE book_copies
SET status = 'issued'
WHERE id = :copy_id AND status = 'reserved';
-- require rowCount = 1

COMMIT;
```

**Timing rule:** insert the borrowing with `active_copy_id` populated, clear the reservation, and set the copy to `issued` in one transaction. Do not create an active borrowing with `active_copy_id = NULL` even temporarily within the transaction; if the insert and the key update are separate statements, update the key before commit and assert the final row.

#### Rule D — Return a borrowing

```sql
BEGIN;

-- Non-locking read to discover the copy and request.
SELECT book_copy_id, request_id, status, active_copy_id
FROM borrowings
WHERE id = :borrowing_id;
-- require status = 'active'

-- Lock the copy first (copy -> request -> borrowing order).
SELECT id, status
FROM book_copies
WHERE id = :copy_id
FOR UPDATE;

-- Lock the request if the request row will be updated.
SELECT id, status
FROM borrow_requests
WHERE id = :request_id
FOR UPDATE;

-- Then lock the borrowing and re-verify after any wait.
SELECT id, book_copy_id, status, active_copy_id
FROM borrowings
WHERE id = :borrowing_id
FOR UPDATE;
-- require status = 'active'
-- require active_copy_id = book_copy_id = :copy_id

UPDATE borrowings
SET status = 'returned',
    return_date = :return_date,
    returned_by = :staff_id,
    active_copy_id = NULL
WHERE id = :borrowing_id AND status = 'active';
-- require rowCount = 1

UPDATE book_copies
SET status = :returned_copy_status   -- 'available', 'maintenance' or 'archived'
WHERE id = :copy_id AND status = 'issued';
-- require rowCount = 1

-- Optional fine, in the same transaction:
INSERT INTO fines (borrowing_id, days_overdue, rate_per_day, amount)
VALUES (:borrowing_id, :days, :rate, :amount);

COMMIT;
```

**Timing rule:** set `status = 'returned'` and `active_copy_id = NULL` in the same UPDATE. Never update `book_copy_id`; it is the historical copy. Use `WHERE status = 'active'` and check `rowCount()` to prevent double returns. If a fine is recorded, it must be in the same transaction as the return, and `uq_fines_borrowing` prevents duplicates.

#### Rule E — Lost, damaged or withdrawn copy

Treat it as a return with a non-available copy status:

```sql
BEGIN;
-- Non-locking read, then lock copy, request (if needed) and borrowing as in Rule D.
UPDATE borrowings
SET status = 'returned',
    return_date = CURDATE(),
    returned_by = :staff_id,
    active_copy_id = NULL,
    notes = :note
WHERE id = :borrowing_id AND status = 'active';

UPDATE book_copies
SET status = 'maintenance'   -- or 'archived'
WHERE id = :copy_id AND status = 'issued';
COMMIT;
```

The same clearing rule applies: no returned row may retain `active_copy_id`.

#### Rule F — Copy status changes and deletion

- Do not set a copy to `maintenance` or `archived` while it has an approved request or an active borrowing. Lock the copy, verify both tables, resolve the request/loan first, then change the status.
- Prefer never deleting copies, requests or borrowings that have history.
- If copy deletion is permitted, change `fk_requests_reserved_copy` and `fk_requests_copy` from `ON DELETE SET NULL` to `RESTRICT` (or enforce the equivalent in application transactions). The current `SET NULL` can silently clear a reservation while leaving the request approved.
- `fk_borrowings_active_copy` is also `ON DELETE SET NULL`; `book_copy_id` is `RESTRICT`, so a copy with a borrowing cannot normally be deleted, but do not rely on that alone.

#### Rule G — Lock order and deadlock avoidance

Define one circulation lock order and use it everywhere:

```text
book_copies  →  borrow_requests  →  borrowings  →  fines
```

- Approval: copy, then request.
- Fulfilment: copy, then request, then insert borrowing.
- Return: read the borrowing to find the copy, then lock copy, then the request if it will be updated, then the borrowing.
- Never lock the same rows in the opposite order in another endpoint.
- Keep transactions short; do not perform HTTP calls, file uploads or user-facing rendering inside them.
- Use `rowCount()` after every state transition and roll back on an unexpected count.
- Treat unique-key errors (`1062`) as conflicts, not as the primary validation message.

The administrative user-management lock order (`roles` admin row → `users` row) is separate. Do not mix administrative user locks and circulation locks in one transaction unless a documented reason exists.

#### Rule H — Integrity audit queries

Run these in the test suite and periodically in production:

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

All queries should return zero rows after every test scenario.

### 5.5 Schema hardening options

Three options are viable; the first is the least disruptive for the current MariaDB target.

1. **Keep the nullable keys and enforce the state machine in application transactions** (Rules A–H). This is what `TESTING.md` already anticipates. It requires disciplined code, row locks, `rowCount()` checks and the audit queries. Add a dedicated Phase 3 test suite for approval, rejection, cancellation, issuance, return, lost copy, double-return and concurrent issue.
2. **Remove `ON UPDATE CASCADE` from the relevant FKs and use generated columns/CHECK constraints.** A generated column referencing an FK column worked when the FK did not use `ON UPDATE CASCADE`. This restores database-level enforcement but changes referential behaviour and may not be acceptable on all hosts. Test on both MySQL 8 and MariaDB before adopting.
3. **Use triggers** to populate/validate `reserved_copy_id` and `active_copy_id`. This is portable across MySQL/MariaDB but adds trigger complexity and can be harder to debug on shared hosting. Triggers must be covered by the same concurrency tests.

Whichever option is chosen, the Phase 1 plan should be amended explicitly; the current implementation is not the schema the plan describes.

---

## 6. UI/UX concerns

### 6.1 Confirmed

- **Blank “Overdue” statistic** — D-1.
- **No-JS mobile navigation** — D-3.
- **Off-canvas sidebar focusability** — links remain in the tab order when the sidebar is closed; see D-3.
- **Setup locked POST returns 200** — a locked `GET /setup-admin.php` returns 403, but a locked POST returns 200 with the “already completed” message. This is a minor consistency issue, not a security boundary.
- **Admin “Overdue loans” card is always styled as danger** — `public/admin/dashboard.php` uses `stat stat--danger` unconditionally, so a zero overdue count still appears in the danger style. Cosmetic only; the number is real.
- **Search treats `%` and `_` as wildcards** — `admin/users.php` wraps the search term in `%...%` without escaping LIKE metacharacters. Searching for a literal `%` or `_` behaves unexpectedly. Not a security issue.

### 6.2 Positive UI findings

- Statistics are real queries, not placeholders.
- Empty states accurately say that catalogue/request features arrive in Phase 3.
- All 27 reachable internal links worked in the crawl.
- Forms have labels, `aria-invalid` on errors, and inline error text.
- The skip link and `main` landmark are present.
- Print CSS hides navigation and actions.
- No external fonts, scripts or stylesheets are loaded.
- The demo banner is clearly marked as development-only.

### 6.3 No-JS behaviour summary

| Interaction | Without JavaScript |
| --- | --- |
| Login, registration, setup, profile, admin forms | Work (normal POST forms) |
| Logout | Works (POST form) |
| Mobile sidebar navigation | **Broken** (D-3) |
| Flash-message dismiss button | Does nothing; message remains (acceptable) |
| Destructive confirmation prompt | Form submits without confirmation (acceptable, but worth noting) |
| Pagination and filters | Work (links/GET forms) |

---

## 7. Hosting compatibility concerns

### 7.1 Verified environment

- PHP 8.2.35 with `pdo_mysql`, `mbstring`, `fileinfo`, `session`, `openssl`, `sodium`.
- MariaDB 10.11.19.
- Apache 2.4.69 with `AllowOverride All` for `public/`.
- Docker ports bound to `127.0.0.1` only.

### 7.2 Production layout is not coherent

See D-5. This is the main hosting blocker.

### 7.3 InfinityFree/shared-hosting assumptions to verify

`README.md` targets InfinityFree, but production deployment has not been tested. Before deployment:

1. **PHP version and extensions** — confirm PHP 8.x, `pdo_mysql`, `mbstring`; `fileinfo` is needed for cover validation.
2. **MySQL/MariaDB version and CHECK enforcement** — the schema targets MySQL 8.x or MariaDB 10.6+. CHECK constraints are enforced in MySQL 8.0.16+ and MariaDB 10.2+; older servers may parse but ignore them. Confirm the host's version.
3. **`getenv()` availability** — shared hosts may not populate environment variables. `config/config.php` falls back to literals, so credentials and `SETUP_TOKEN` must be set in the protected config file if environment variables are unavailable.
4. **`.htaccess` support** — `AllowOverride` must permit the directives used (`Options -Indexes`, `Require all denied`, `ErrorDocument`). If the host ignores `.htaccess`, private directories must be outside the web root.
5. **`ErrorDocument` paths** — `public/.htaccess` uses root-absolute `/403.php`, `/404.php`, `/500.php`. This works only when `public/` is the domain root. For a subdirectory deployment, adjust or make the paths base-aware. The README notes this but does not provide the exact values.
6. **`BASE_PATH` detection** — `config/config.php` auto-detects `/public/` in `SCRIPT_NAME`; it does not detect a generic subdirectory when `public/` is the document root. Set `APP_BASE_PATH` for subdirectory deployments.
7. **Writable directories** — `storage/logs/` and `public/uploads/covers/` must be writable. The current Docker image runs as `www-data`; shared-hosting ownership differs.
8. **HTTPS and cookies** — serve over HTTPS; confirm the `Secure` cookie flag is actually set (see §4.7).
9. **Database time zone** — `config/database.php` executes `SET time_zone = '+03:00'`. This is a numeric offset and normally needs no time-zone tables, but confirm the host permits it.
10. **MariaDB generated-column limitation** — if the host uses MariaDB, the nullable-key approach is appropriate. If it uses MySQL 8, the schema still works but could be strengthened with generated columns/checks. Do not assume the MariaDB limitation applies to MySQL 8 without testing.
11. **No shell/Composer/Node** — the application has no build step and does not require shell access. This is compatible with typical shared hosting.
12. **Demo data** — remove or change the demonstration accounts before public deployment (`README.md` lines 156–157); ensure no administrator password from local testing remains.

### 7.4 Docker-specific findings

- The Docker stack is development-only: `APP_ENV=development`, demo credentials visible on the landing page, and `.dev/.env` holds secrets.
- The database port and web port are bound to `127.0.0.1`, which is good.
- `.dev/` is outside the document root in Docker, so `/.dev/.env` returns 404. This does not validate the XAMPP layout.
- The Docker PHP configuration sets `display_errors=Off`, `log_errors=On`, `expose_php=Off`, and upload limits of 2M/3M. These are sensible for development and should be mirrored in production.

---

## 8. Corrections ranked by severity

No **critical** defect requiring an immediate application-code change was found. The following are proposed corrections; none were applied during this review.

| ID | Severity | Correction | Why / target |
| --- | --- | --- | --- |
| C-1 | **High** | Implement and test the transaction rules in §5.4 before any Phase 3/4 code writes `borrow_requests` or `borrowings`; or restore database-level generated/CHECK enforcement. Add the audit queries to the test suite. | The approved Phase 1 invariants are not database-enforced. This is the main integrity precondition for Phase 3. |
| C-2 | **High** | Resolve the production layout conflict: introduce `APP_PUBLIC_PATH` (or move error rendering/upload storage), and update `README.md`. Verify 403/404/405 and cover uploads in the documented layout. | As written, the README deployment steps can break error pages and the upload path (D-5). |
| C-3 | **Medium** | Replace the upload blacklist with an allowlist; deny SVG/HTML/XML; serve covers from outside the web root or with a fixed image response. Add probes. | Latent stored XSS in a writable public directory (D-4). Do this before cover uploads ship. |
| C-4 | **Medium** | Add `.dev/.htaccess` deny-all and keep `.dev/` outside the web root; never expose `.env` or test scripts in the XAMPP layout. | Protects DB passwords and the setup token in the documented development layout (D-6). |
| C-5 | **Medium** | Add session revocation on password change/reset: `session_version`/`credentials_changed_at` in `users`, checked by `current_user()`, or a sessions table. Test with an existing session. | An administrator password reset currently leaves the old session valid (D-7). |
| C-6 | **Medium** | Add a Content-Security-Policy; add HSTS once HTTPS is confirmed. Externalise the inline styles in the exception handler or allow them by hash/nonce. | Defence in depth against XSS; current headers omit CSP/HSTS (§4.5). |
| C-7 | **Medium–Low** | Reject NUL/control characters in passwords and catch `ValueError` around `password_hash()`; add an HTTP regression test. | A NUL-byte password currently causes a 500 (D-2). |
| C-8 | **Low** | Use `COALESCE(SUM(...), 0)` for the student overdue statistic; add a zero-loan test. | Blank “Overdue” value (D-1). |
| C-9 | **Low** | Provide a no-JS mobile navigation fallback and make the closed off-canvas sidebar non-focusable. Add a keyboard/no-JS test. | Mobile navigation is unusable without JS (D-3). |
| C-10 | **Low** | Add a server-side login throttle keyed by email/IP and log failed/throttled attempts. | Session-only throttle is bypassable and failed logins are not audited (§4.3–4.4). |
| C-11 | **Low** | Make `ErrorDocument` paths base-aware or document the exact subdirectory values; add a subdirectory deployment test. | Root-absolute paths fail in subdirectory deployments (§7.3). |
| C-12 | **Low** | Minor UI consistency: make the admin overdue card conditional on a non-zero count; return 403 for a locked setup POST if consistency is desired; escape LIKE wildcards in search; remove or use the unused `data-toggle-password` code if it has no matching control. | Cosmetic/UX; no security boundary. |
| C-13 | **Low (operational)** | Before production: set `APP_ENV=production`, remove/change demo accounts, clear `SETUP_TOKEN` after setup, and confirm `.env`/SQL/logs are not downloadable. | Prevents credential exposure; the README already recommends most of this. |

### Suggested order of work

1. **Before Phase 3 coding:** decide C-1 (accept the amendment and implement §5.4, or change the schema), and complete C-7 and C-2 (the latter before any cover upload endpoint).
2. **Before public deployment:** C-3, C-4, C-5, C-6, C-11, C-13.
3. **Polish:** C-8, C-9, C-10, C-12.

---

## 9. Final readiness verdict

### Phase 2 foundation: **conditionally accepted**

The Phase 2 foundation is functionally sound and materially better than a typical scaffold:

- Authentication, session handling, CSRF, authorization, validation, setup, last-administrator protection, activity logging and error handling are implemented with appropriate server-side controls.
- Independent tests did not find an authentication bypass, role-guard bypass, CSRF bypass, SQL injection, or setup/last-admin race.
- The setup and last-admin concurrency protections were reproduced, not merely accepted from the previous report.
- The UI uses real statistics and has no broken internal links in the tested pages.

It is **not unconditionally ready** for Phase 3 because:

1. **The approved database-level reservation/active-loan invariants are not present.** The implementation uses nullable keys maintained by future application transactions. This is a documented, technically justified MariaDB adaptation, but it is a deviation from the Phase 1 schema and must be formally accepted or reversed. Phase 3/4 cannot safely write borrowing data until Rules A–H in §5.4 are implemented and tested.
2. **The production layout instructions are internally inconsistent.** Deploying exactly as `README.md` describes can break 403/404/405 rendering and cover uploads. This must be resolved before any production deployment.
3. **Latent upload, `.dev` exposure and session-revocation issues** should be fixed before the corresponding features or environments are exposed.

**Recommendation:** approve the Phase 2 foundation **with the conditions above**. The first task of Phase 3 must be the circulation invariant work (C-1), not the catalogue UI. If the project's definition of “Phase 2 complete” requires the exact generated columns/CHECK constraints from `PHASE1_PLAN.md`, then Phase 2 is **not fully compliant** and the deviation needs an explicit written amendment before approval.

**No Phase 3 work has been started. No application code has been modified. Awaiting explicit approval.**

---

## Appendix A — Executed checks and results

### A.1 Static

| Check | Result |
| --- | --- |
| `php -l` on 31 PHP files | All passed (PHP 8.2.35) |
| Source review of all PHP/SQL/`.htaccess`/CSS/JS files | Completed |
| SQL injection spot check | All queries use native prepared statements; filters whitelisted |
| Output escaping spot check | User-controlled output escaped through `e()` |
| Link crawl | 27 internal links checked; no failures |
| CSS/JS external dependencies | None found |

### A.2 HTTP behaviour

| Check | Result |
| --- | --- |
| Anonymous protected pages | 302 to `/login.php` |
| Student → admin pages/actions | 403 |
| Student → staff dashboard | 403 |
| Authenticated POST without CSRF | 403 |
| Anonymous admin POST | 302 to login (auth checked before CSRF) |
| GET logout / GET user-action | 405 |
| Admin self-deactivation | Blocked; account remains active |
| Admin self-demotion | Blocked; role unchanged |
| Locked setup GET | 403 |
| Locked setup POST with valid token | “Already completed”; no second admin |
| Admin creates student | 302; role/status/creator correct |
| Duplicate email | Rejected with field error |
| Deactivation | Existing session revoked on next request; login refused |
| Reactivation | Login works |
| Wrong current password | Rejected |
| Password change | Old password fails; new password works |
| CSRF token after login | Rotated |
| Logout with valid token | Redirect; old session denied |
| Six failed logins | Sixth blocked with throttle message |
| Session ID on login | Rotated |
| Idle timeout (rewritten `last_activity`) | 302 to login |
| Cookie flags | `HttpOnly`, `SameSite=Lax` |
| Security headers | `nosniff`, `SAMEORIGIN`, referrer and permissions policies present; CSP/HSTS absent |
| 403/404 error pages | Application error layout, not Apache default |
| Null-byte password registration | HTTP 500; no user created; `ValueError` logged |
| SVG in upload directory | HTTP 200, `image/svg+xml`, body intact |
| `.php`/`.phtml` in upload directory | 403 |
| Directory listings | 403 |
| `.dev/.env` under Docker | 404 (outside document root) |
| Admin password reset vs existing session | Existing session still 200 (no revocation) |

### A.3 Concurrency

| Check | Result |
| --- | --- |
| Last-admin race (two workers) | Worker 1 deactivated admin B; worker 2 was blocked from deactivating the remaining admin A (`active_admins = 1`) |
| Setup race (three workers, scratch DB) | Exactly one administrator and one `setup_completed_at`; two workers reported `locked` |

### A.4 Database probes

| Probe | Result |
| --- | --- |
| Duplicate active request per student/title | `ERROR 1062` on `uq_active_request_per_title` |
| Duplicate non-NULL reservation | `ERROR 1062` on `uq_reserved_copy` |
| Duplicate non-NULL active loan | `ERROR 1062` on `uq_active_borrowing_copy` |
| Returned loan without return date | CHECK failure |
| Missing user FK | `ERROR 1452` |
| Approved request without copy | Accepted |
| Rejected request with reservation | Accepted |
| Two active loans with `active_copy_id = NULL` | Accepted |
| Returned loan retaining `active_copy_id` | Accepted |
| Active loan with `active_copy_id <> book_copy_id` | Accepted |
| Generated column/CHECK referencing FK with `ON UPDATE CASCADE` | `ERROR 1901` on MariaDB 10.11 |
| Generated column referencing FK without `ON UPDATE CASCADE` | Works |
| CHECK referencing FK without `ON UPDATE CASCADE` | Still `ERROR 1901` |

## Appendix B — Test data state after review

The local database was restored after testing:

- 3 users: `librarian@campus.test`, `student@campus.test`, `admin@campus.test`.
- 1 active administrator.
- `library_settings.setup_completed_at = 2026-11-19 04:04:07`.
- Activity logs 1–3 only (setup and two administrator logins).
- No review users, upload probes, scratch databases or temporary race scripts remain.
- The temporary review race script and marker were removed; the project contains only the original `.dev` files.

## Appendix C — Review limitations

- Local Docker only; **InfinityFree/MySQL 8 production was not tested**.
- No real mobile device or browser automation was available; the no-JS navigation finding is based on direct CSS/JS inspection, not an executed browser test.
- No load, stress or penetration testing was performed.
- The cover upload handler does not exist yet; upload tests used manually placed files.
- The SVG risk is latent until an upload path writes to `public/uploads/covers/`.
- The session-revocation test was performed with a temporary user and cleaned up; no production data was used.
- The last-admin and setup races were reproduced with temporary workers/scratch data and cleaned up.
- MySQL 8 behaviour for generated columns/CHECK constraints was not tested; only MariaDB 10.11 was available.
