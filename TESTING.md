# Phase 2 Verification Record

This file records what was actually executed and observed during Phase 2.
It is not a claim that untested items have been verified.

---

## Environment

| Item | Value |
| --- | --- |
| Host OS | Windows (PowerShell 7 / `pwsh`) |
| PHP | 8.2.35 (NTS), Apache 2.4.69 (Debian) in Docker |
| Database | MariaDB 10.11.19-MariaDB (Ubuntu build) |
| Docker Engine | 29.8.0 |
| Web URL | <http://localhost:8090/> |
| Database host port | `3307` |
| PDO driver | `pdo_mysql`, native prepares, exceptions enabled |
| SQL mode | `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` |

PHP modules confirmed: `pdo_mysql`, `mbstring`, `fileinfo`, `session`,
`openssl`, `sodium`.

---

## Static checks

```powershell
cd .dev
docker compose exec -T web sh -lc 'find /var/www/html/campus-library -name "*.php" -print0 | xargs -0 -n1 php -l'
```

Result: **no syntax errors** in every application PHP file (config, includes,
public pages, account/admin/staff/student pages and error pages).

---

## Schema import

```powershell
cd .dev
Get-Content -Raw '..\database\database.sql' |
  docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" campus_library'
```

Observed seed totals:

| Table | Rows |
| --- | --- |
| `roles` | 3 |
| `users` | 2 |
| `categories` | 6 |
| `books` | 12 |
| `book_copies` | 22 |
| `borrow_requests` | 0 |
| `borrowings` | 0 |
| `fines` | 0 |
| `library_settings` | 1 |
| `activity_logs` | 0 |

All ten tables were created with `InnoDB`. No administrator is seeded.

---

## Database integrity tests

`database/database.sql` defines the production schema. `.dev/schema-test.sql`
contains deliberate violations followed by `ROLLBACK`.

```powershell
cd .dev
Get-Content -Raw 'schema-test.sql' |
  docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" campus_library --force'
```

Observed results:

| Test | Expected | Observed |
| --- | --- | --- |
| Second active request for same student + title | Duplicate key | `ERROR 1062` on `uq_active_request_per_title` |
| Same copy reserved twice | Duplicate key | `ERROR 1062` on `uq_reserved_copy` |
| Same copy in two active borrowings | Duplicate key | `ERROR 1062` on `uq_active_borrowing_copy` |
| Returned loan without return date | Check failure | `ERROR 4025` on `chk_returned_has_date` |
| Request for missing user | Foreign-key failure | `ERROR 1452` on `fk_requests_user` |
| Rollback state | 0 requests / 0 borrowings | `schema_test_rollback_complete 0 0` |

### MariaDB compatibility finding

MariaDB 10.11 rejected both of these during development:

```text
ERROR 1901: Function or expression 'assigned_copy_id' cannot be used in the
GENERATED ALWAYS AS clause of `reserved_copy_id`

ERROR 1901: Function or expression 'reserved_copy_id' cannot be used in the
CHECK clause of `chk_res`
```

The approved design used generated columns and checks referencing foreign-key
columns. MariaDB does not allow that combination. The schema was adapted instead
of silently dropping the rules:

- `borrow_requests.active_request_key` remains a stored generated column because
  it references only `status`.
- `borrow_requests.reserved_copy_id` is a nullable FK with a `UNIQUE` index.
- `borrowings.active_copy_id` is a nullable FK with a `UNIQUE` index.
- Application transactions must set these keys while a reservation/loan is
  active and clear them when it ends. This is Phase 3 work.
- The compatibility difference is documented at the top of `database.sql`.

Verified generated column:

```sql
SELECT table_name, column_name, extra, generation_expression
FROM information_schema.columns
WHERE table_schema = DATABASE() AND generation_expression <> '';
```

Observed: `borrow_requests.active_request_key`, `STORED GENERATED`, expression
over `status`.

Verified constraints (after removing temporary probe tables):

- 10 CHECK constraints
- 19 foreign keys
- 10 primary keys
- unique indexes for emails, student numbers, ISBNs, copy codes, active
  requests, reservations and active loans

---

## HTTP smoke tests

All requests were made against `http://localhost:8090`.

| Request | Result |
| --- | --- |
| `GET /` | `200` landing page |
| `GET /login.php` | `200` |
| `GET /register.php` | `200` |
| `GET /setup-admin.php` (before setup) | `200` setup form |
| `GET /admin/dashboard.php` (signed out) | `302` to `/login.php` |
| `GET /account/profile.php` (signed out) | `302` to `/login.php` |
| `GET /assets/css/style.css` | `200` |
| `GET /assets/js/app.js` | `200` |
| `GET /404.php` | `404` rendered page |
| `GET /500.php` | `500` rendered page |
| `GET /this-page-does-not-exist` | `404` application page (Apache `ErrorDocument`) |
| `GET /assets/` | `403` application page (directory listing denied) |
| `GET /uploads/covers/` | `403` |
| `GET /uploads/covers/probe.php` (temporary probe) | `403`; probe deleted |
| `GET /config/config.php` | `404` |
| `GET /includes/functions.php` | `404` |
| `GET /database/database.sql` | `404` |
| `GET /storage/logs/app.log` | `404` |
| `GET /.dev/.env` | `404` |

Response headers observed on `GET /login.php`:

```text
Set-Cookie: CAMPUSLIB_SESSION=...; path=/; HttpOnly; SameSite=Lax
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
```

---

## Setup token and one-time setup

### Invalid token

- Submitted the setup form with `setup_token=wrong-token-value`.
- Result: `200` with “The setup token is not valid.”
- Database: `admin_count = 0`, `setup_completed_at = NULL`.

### Concurrent setup

Three independent sessions submitted valid setup requests at the same time,
each with a different administrator email.

Observed:

- Exactly **one** administrator row was created.
- `library_settings.setup_completed_at` was set.
- Other attempts were refused; at most one request won.
- No duplicate email or second administrator was created.

### Repeat setup

- `GET /setup-admin.php` after completion → `403` with “Setup has already been
  completed.”
- A fresh POST with a valid CSRF token and the correct setup token →
  `200` with the same rejection message.
- Administrator count remained **1**.

The setup token is never present in application source. It is read from
protected configuration via `SETUP_TOKEN`.

---

## Authentication and authorization

| Test | Result |
| --- | --- |
| Administrator sign-in | `302` to admin dashboard; dashboard `200` |
| Student sign-in | `302` to student dashboard; dashboard `200` |
| Librarian sign-in | `302` to staff dashboard; dashboard `200` |
| Student → `/admin/users.php` | `403` |
| Student → `/staff/dashboard.php` | `403` |
| Librarian → `/admin/users.php` | `403` |
| Librarian → `/student/dashboard.php` | `403` |
| Administrator → `/staff/dashboard.php` | `403` |
| Administrator → `/student/dashboard.php` | `403` |
| Inactive librarian sign-in | `200` with generic “Invalid email address or password.” |
| Deactivated user with existing session | next request `302` to login |
| Session ID before/after login | different (rotation verified) |

Role guards execute on the server before rendering protected pages. Hiding
navigation links is not relied upon.

---

## CSRF and HTTP method tests

| Test | Result |
| --- | --- |
| `POST /login.php` without token | `403` |
| `POST /admin/user-action.php` without token | `403` |
| `POST /admin/user-form.php` without token | `403` |
| `GET /admin/user-action.php` as administrator | `405` |
| `GET /logout.php` as an authenticated user | `405` |
| `POST /logout.php` with token | signs out and redirects |

`verify_csrf()` uses `hash_equals()` and rejects missing, malformed or stale
tokens.

---

## Administrator user management

### Create

- Created librarian `test.librarian@campus.test`.
- Result: redirect to user list with “The user account has been created.”
- Database: role `librarian`, status `active`, `created_by = 3`.
- Student number was stored as `NULL` for a non-student role.

### Validation

| Input | Result |
| --- | --- |
| Student without student number | rejected: “A student number is required…” |
| Duplicate email | rejected: “That email address is already registered.” |
| Password confirmation mismatch | rejected: “The password confirmation does not match.” |

No invalid rows were inserted.

### Edit, role change and reset

- Edited the librarian's name, phone and department without changing the password.
- Result: “The user account has been updated.”
- Changed a student account to librarian; stored `student_number` was cleared to
  `NULL`.
- Reset that account's password through the edit form.
- Signed in with the new password and reached the librarian dashboard.

### Activate / deactivate

- Deactivated the librarian: list showed `inactive`; flash confirmed.
- An already signed-in librarian was redirected to login on the next request.
- Reactivated the librarian via the user action endpoint.
- Self-deactivation through a crafted POST was blocked.
- A deactivated account cannot be authenticated.

### Search, filter and pagination

With 14 users present (including 8 temporary pager users):

- `GET /admin/users.php?q=Test+Librarian` matched the correct account.
- `GET /admin/users.php?role=student` listed students.
- Page 1 and page 2 each showed 4 of the 8 temporary student accounts.
- A page-2 link was rendered.
- Filtered `role=student&status=active` returned all 8 pager users.
- `page=99` clamped to the last valid page.

Temporary pager users were deleted after the test.

---

## Last-administrator protection

### Single-administrator check

`.dev/admin-guard-test.php single` was run inside the web container.

Observed:

```json
{
    "active_admins": 1,
    "deactivation_blocked": true,
    "demotion_blocked": true
}
```

### Real two-process lock race

A second active administrator was created, then two PHP processes ran
concurrently:

- Worker 1 locked the administrator role row, waited 3 seconds, then deactivated
  administrator B and committed.
- Worker 2 waited for the role lock, then attempted to deactivate administrator A
  and perform the last-admin check.

Observed:

```json
{"worker":1,"target_admin":"B","blocked":false,"active_admins":2,"deactivated_b":true}
{"worker":2,"target_admin":"A","blocked":true,"active_admins":1}
```

This confirms the second transaction observed the first commit, counted one
remaining active administrator, and refused the change. The temporary second
administrator was then deleted and administrator A reactivated.

### Self-protection

Crafted POSTs using the administrator's own ID were rejected:

- demotion to student → “You cannot demote your own administrator account.”
- status inactive → “You cannot deactivate your own administrator account.”
- status action endpoint → “You cannot change the status of your own account.”

Database role/status remained `admin` / `active`.

---

## Registration and account flows

### Student registration

- Valid registration created a student account and redirected to login with a
  success message.
- Duplicate student number was rejected.
- Posting `role=admin` and `status=inactive` to the registration form still
  created a **student** account with status **active**; self-assigned roles are
  ignored server-side.
- Student profile update changed name, phone and department.
- Wrong current password was rejected.
- Password change succeeded, rotated the CSRF token, and the new password worked
  after signing out and back in.
- Logout produced a confirmation message and the next protected request
  redirected to login.

### Sign-in throttle

Six consecutive failed sign-ins in one session:

- Attempts 1–5 returned the generic invalid-credentials message.
- Attempt 6 returned “Too many failed sign-in attempts. Please wait 15 minutes…”.

The throttle is session-based, not IP-based. It is a deterrent, not a complete
brute-force control.

---

## Known defects found and fixed during Phase 2

| Defect | Fix |
| --- | --- |
| Admin dashboard contained a leftover invalid aggregate query | Removed the dead query |
| Student loan history joined `books` through a non-existent `borrowings.book_id` | Join through `book_copies` |
| Profile page included shared files with the wrong directory depth (500) | Corrected to `../../includes/` |
| Logout confirmation flash was lost after `session_destroy()` | Clear session data and call `session_regenerate_id(true)` instead |
| Self-edit role/status selects rendered `name=""` | Omit the `name` attribute when the control is disabled |
| MariaDB rejected generated/check expressions referencing FK columns | Replaced with nullable unique FKs plus documented application transaction rules |
| Temporary schema probe table remained in the dev database | Dropped it; schema re-verified at ten tables |

The final implementation was re-imported and re-checked after these fixes.

---

## Final fresh-install pass

After all fixes, the schema was re-imported from `database/database.sql` and the
one-time setup was run again.

Observed:

- Seed totals returned to 3 roles, 2 demonstration users, 6 categories, 12 books,
  22 copies, 1 settings row and 0 activity rows.
- Setup created `Campus Administrator <admin@campus.test>` as the only
  administrator.
- `library_settings.setup_completed_at` was set on the fresh import.
- A later `GET /setup-admin.php` returned `403` with the locked message.
- Administrator sign-in reached the dashboard; `/admin/users.php`,
  `/admin/user-form.php`, `/account/profile.php` and `/admin/dashboard.php`
  all returned `200`.
- Final user table: the seeded librarian, the seeded student and the newly created
  administrator.
- One setup activity-log row was recorded.

The final development credentials are documented in `README.md` and are
local-only.

---

## Not tested, and why

- **InfinityFree production deployment** — no production account or upload was
  used. Local Docker/Apache behavior is not proof of shared-host behavior.
- **HTTPS-specific cookie flags and HSTS** — the local stack is HTTP only.
  `Secure` is set when HTTPS is detected, but that path was not exercised.
- **One-hour idle session timeout** — waiting an hour was not practical. The
  timeout branch was inspected, not timing-tested.
- **MySQL 8.x specifically** — only MariaDB 10.11 was available. The SQL aims to
  be compatible, but MySQL 8 was not run.
- **`SETUP_TOKEN` empty / setup-disabled path** — changing the running container's
  environment was out of scope; the guard is a simple early 404 and was inspected.
- **Full browser, responsive-layout and accessibility testing** — pages were
  checked at HTTP/HTML level, not with a formal cross-browser or screen-reader
  audit.
- **JavaScript-only behaviors** — mobile navigation, alert dismissal and confirm
  dialogs are progressive enhancements and were not exercised in a browser.
- **Email delivery, password reset by email, cover upload processing** — these
  features are not part of Phase 2.
- **Load, large datasets and query performance** — not measured.
- **Activity-log retention/rotation** — no rotation policy is implemented yet.

---

## Phase 2.1 remediation verification

### Circulation Rules A–H, rollback and concurrency

```powershell
docker compose exec -T web php -f /var/www/html/campus-library/.dev/circulation-test.php basic
docker compose exec -T web php -f /var/www/html/campus-library/.dev/circulation-test.php invalid_prevention
docker compose exec -T web php -f /var/www/html/campus-library/.dev/circulation-test.php rollback
docker compose exec -T web php -f /var/www/html/campus-library/.dev/circulation-test.php audit
docker compose exec -T web php -f /var/www/html/campus-library/.dev/circulation-test.php concurrent
```

Expected: `basic`, `invalid_prevention`, `rollback`, `audit` and `concurrent` all `PASS`; the audit result is an empty violation list.

### Upload security

```powershell
python C:\Users\leon2\AppData\Local\Temp\opencode\phase21_upload_tests.py
docker compose exec -T web php -f /var/www/html/campus-library/.dev/uploads-test.php
```

Expected: valid PNG/JPEG serve; SVG, PHP payload, mismatched extension and directory listing are denied; helper returns errors for SVG/PHP/non-image/oversize.

### Session revocation on password reset

```powershell
python C:\Users\leon2\AppData\Local\Temp\opencode\phase21_session_revocation.py
```

Expected: student's old session becomes `302 -> /login.php`; administrator's session remains `200`; student can log in with the new password.

### Security headers

```powershell
docker compose exec -T web php -f /var/www/html/campus-library/.dev/security-headers-test.php
docker compose exec -T web sh -c 'HSTS_MAX_AGE=31536000 php -f /var/www/html/campus-library/.dev/security-headers-test.php'
python C:\Users\leon2\AppData\Local\Temp\opencode\phase21_http_headers.py
```

Expected: CSP present; HSTS absent on HTTP and present only when HTTPS + `HSTS_MAX_AGE>0`.

### C-2 canonical public path and split layout

```powershell
docker compose exec -T web php -f /var/www/html/campus-library/.dev/split-layout-test.php
docker compose exec -T web sh -c 'APP_PUBLIC_PATH=/tmp/opencode/split-public php -f /var/www/html/campus-library/.dev/split-layout-test.php'
```

Expected: `COVER_UPLOAD_DIR` follows `APP_PUBLIC_PATH`; `abort_403()` renders the 403 page without requiring `APP_ROOT/public/403.php`.

### C-4 development-file protection

Docker layout:

```powershell
python - <<'PY'
import requests
B = "http://localhost:8090"
for path in ["/.dev/.env", "/config/config.php", "/includes/functions.php", "/database/database.sql", "/storage/logs/app.log", "/README.md"]:
    print(path, requests.get(B + path, timeout=20).status_code)
PY
```

Expected: `404` for `.dev/.env` and private/private files under the Docker document root.

Simulated XAMPP layout (temporary Apache on port 8081 serving `campus-library/` with `AllowOverride All`):

```text
For paths /.dev/.env, /config/config.php, /includes/functions.php,
/database/database.sql, /storage/logs/app.log, /README.md, /TESTING.md,
/public/.htaccess expect 403.
For /public/login.php expect 200/302. Directory listing remains 403.
```

### C-8 dashboard/search/setup regressions

```powershell
python C:\Users\leon2\AppData\Local\Temp\opencode\phase21_c8.py
```

Expected: student overdue displays a real `0`; admin overdue card not danger at zero; admin search with `q=%` returns `200` without an error; locked setup GET and POST are `403`.

### C-9 mobile navigation static regression

```powershell
python C:\Users\leon2\AppData\Local\Temp\opencode\phase21_c9_static.py
```

Expected: authenticated page references `boot.js`; `boot.js` adds `js`; CSS contains both `html:not(.js)` fallback and `html.js` off-canvas rules.

### C-7 NUL password

```powershell
python C:\Users\leon2\AppData\Local\Temp\opencode\phase21_nul_password.py
```

Expected: NUL-byte registration returns `200` with a control-character validation error and creates no user; NUL-byte login returns the generic invalid-credentials page (`200`).

---

## Phase 3 verification

### HTTP smoke

```powershell
python C:\Users\leon2\AppData\Local\Temp\opencode\phase3_smoke.py
```

Expected: student login, catalogue, book detail, history `200`; librarian catalogue/book/copy/requests/issue/returns `200`; admin dashboard `200`.

### End-to-end circulation workflow

```powershell
python C:\Users\leon2\AppData\Local\Temp\opencode\phase3_workflow.py
```

Expected: student request `302 -> /catalogue/history.php`; request pending; librarian approve `302`; request approved and copy reserved; issue `302`; borrowing active and copy issued; return `302`; borrowing returned with null active-copy key and copy available.

### HTTP catalogue/workflow tests

```powershell
python C:\Users\leon2\AppData\Local\Temp\opencode\phase3_http_tests.py
```

Expected: staff create/edit books/copies, student search/filter, request submitted, duplicate request prevented, cancel submitted, authorization regressions all `PASS`.

### Category CRUD

```powershell
python C:\Users\leon2\AppData\Local\Temp\opencode\phase3_category_test.py
```

Expected: category list/add/edit `200`, create/update/delete `302`, delete blocked while referenced.

### Link crawl and regression

```powershell
python C:\Users\leon2\AppData\Local\Temp\opencode\phase21_regression.py
```

Expected: all reachable internal links return `200`/`302`; no error pages; role guards and method-not-allowed regressions pass.

## Phase 4 verification

### Fines smoke

```powershell
python C:\Users\leon2\AppData\Local\Temp\opencode\phase4_smoke.py
```

Expected: student/staff/admin logins and fines pages/dashboards `200`; unauthorized roles get `403`.

### Phase 4 HTTP settlement flow

```powershell
python C:\Users\leon2\AppData\Local\Temp\opencode\phase4_http_tests.py
```

Expected: student request/librarian approve/issue/return/settle pass; DB checks show outstanding→settled.

### Fines engine regression

```powershell
docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" campus_library --force < .dev/schema-test.sql'
```

Expected: same prior Phase 2.1 integrity results.

## Useful development commands

```powershell
# Start / stop
cd .dev
docker compose up -d --build
docker compose down

# PHP syntax check
docker compose exec -T web sh -lc 'find /var/www/html/campus-library -name "*.php" -print0 | xargs -0 -n1 php -l'

# Re-import schema
Get-Content -Raw '..\database\database.sql' |
  docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" campus_library'

# Database shell
docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" campus_library'

# Integrity test (intentional errors, rolled back)
Get-Content -Raw 'schema-test.sql' |
  docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" campus_library --force'

# Last-admin guard checks
docker compose exec -T web php /var/www/html/campus-library/.dev/admin-guard-test.php single
docker compose exec -T web php /var/www/html/campus-library/.dev/admin-guard-test.php setup
# Run race1 and race2 in two concurrent shells, then:
docker compose exec -T web php /var/www/html/campus-library/.dev/admin-guard-test.php cleanup
```
