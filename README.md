# Campus Library Management System

A native PHP 8 / MySQL library management system built for a campus library.
This repository contains the **Phase 2 foundation**, the **Phase 2.1 remediation**,
and the **Phase 3 catalogue and circulation implementation**: database,
configuration, authentication, authorization, account management, dashboards,
administrator user management, catalogue categories/books/copies, student
catalogue, borrow-request workflow, librarian approval/issuance/returns, and
real catalogue/circulation statistics.

No frameworks, Composer packages or Node.js build steps are used.

---

## Phase 2 scope

Implemented:

- Ten-table MySQL/MariaDB schema with foreign keys, indexes, checks and audit logs
- Environment-aware configuration outside the public web root
- PDO connection with exceptions and native prepared statements
- Secure session handling, idle timeout and session-ID rotation
- Student self-registration
- Email/password sign-in with a session-level failed-attempt throttle
- Role-based dashboards for administrators, librarians and students
- Profile editing and password changes
- Administrator user management:
  - create administrator, librarian and student accounts
  - edit details, role, status and optional password reset
  - search, role/status filters and pagination
  - activate/deactivate accounts
- One-time initial administrator setup with a protected setup token
- Last-active-administrator protection using transactions and row locks
- Self-demotion and self-deactivation protection on the server
- CSRF protection on all state-changing forms
- Append-only activity audit log
- Consistent 403, 404, 405 and 500 pages

Phase 3 catalogue and circulation:

- Catalogue categories: list, create, edit, delete with book-count guards
- Catalogue books: list, search by title/author/ISBN, category filter, pagination, create/edit/archive, ISBN uniqueness, validation
- Physical copies: per-book status list, create/edit, unique identifiers, no manual “available” while reserved/issued
- Student catalogue: browse, search, filter, pagination, book details
- Student requests: browse catalogue, request title, view/cancel requests
- Librarian requests: list, filters, approve with safe copy assignment, reject/cancel with decision notes
- Librarian issuance: approved requests awaiting issue, issue borrowing, due date from loan settings
- Librarian returns: active loans list with overdue indicators, search, record returns
- Dashboards: real database-backed catalogue/circulation stats for students, librarians and admins

Phase 4 fines management and settlement:

- Librarian fine management: list, search, filters, calculation breakdown, settle.
- Student fine history: outstanding and settled fines, due dates, overdue duration, amounts.
- Fines integration with return workflow: overdue returned copies receive outstanding fines automatically.
- Manual library-desk settlement with audit trail.
- Dashboards: real outstanding/settled totals for student/librarian/admin.

Not part of Phase 3: full fine calculation/payment settlement, email delivery,
password reset by email, analytics/reports, and production deployment verification.
These belong to later phases.

Not part of Phase 2: catalogue CRUD, borrow request review, issuing/returns,
fine calculation, reports, settings management, email delivery, password reset
by email, and production deployment verification. These belong to later phases.

---

## Requirements

- PHP **8.1+** (tested with PHP 8.2.35)
- MySQL 8.x or MariaDB 10.6+ (tested with MariaDB 10.11.19)
- PHP extensions: `pdo_mysql`, `mbstring`, `fileinfo`
- Apache with `.htaccess` support, or equivalent protected-directory rules
- No Composer or Node.js required

Intended production host: **InfinityFree** (shared hosting). Production
deployment has **not** been tested yet.

---

## Project layout

```text
campus-library/
├── config/                 Private configuration and PDO connection
├── database/               Schema, seed data and integrity test SQL
├── includes/               Shared bootstrap, auth, authz, CSRF, helpers, UI
├── public/                 Apache document root
│   ├── assets/             CSS and JavaScript
│   ├── account/            Signed-in profile pages
│   ├── admin/              Administrator pages
│   ├── staff/              Librarian dashboard
│   ├── student/            Student dashboard
│   ├── uploads/covers/     Future cover-image storage (hardened)
│   └── *.php               Public, auth and error pages
├── storage/logs/           Runtime logs (protected)
├── .dev/                   Local Docker stack and test scripts (not deployed)
├── PHASE1_PLAN.md          Approved architecture and requirements
├── README.md
└── TESTING.md
```

`config/`, `includes/`, `database/` and `storage/` are deliberately outside
`public/`. The `.dev/` directory is ignored by version control and must never be
uploaded to production.

---

## Local development with Docker

The development stack is temporary and is not a production dependency.

### Start

1. Start Docker Desktop and wait for the daemon.
2. From the repository root:

   ```powershell
   cd .dev
   docker compose up -d --build
   ```

3. Open <http://localhost:8090/>.
4. MariaDB is exposed on host port `3307` for inspection tools.

The stack contains:

- `web`: PHP 8.2 Apache, document root `public/`
- `db`: MariaDB 10.11 with database `campus_library`

### Stop

```powershell
cd .dev
docker compose down
```

Add `-v` to remove the database volume and start completely fresh.

### Configuration and secrets

`.dev/.env` contains local database passwords and the one-time setup token. It
is ignored by Git and never belongs in `public/`.

`docker-compose.yml` passes database and setup settings to PHP through
environment variables.

---

## Database import

Create the database first, then import the schema. The SQL file intentionally
does **not** contain `CREATE DATABASE` or `USE`, because shared hosts normally
provide the database through their control panel.

### Docker (PowerShell)

```powershell
cd .dev
Get-Content -Raw '..\database\database.sql' |
  docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" campus_library'
```

### MySQL/MariaDB client

```bash
mysql -u USER -p DATABASE_NAME < database/database.sql
```

Seed data includes:

- 3 roles (`admin`, `librarian`, `student`)
- 1 library settings row
- 6 categories
- 12 books
- 22 physical copies
- 2 local demonstration users
- **No administrator account**

### Local demonstration users

These exist only to make the development database usable and must be removed or
have their passwords changed before public deployment.

| Role | Email | Password |
| --- | --- | --- |
| Administrator | `admin@campus.test` | `Admin123!` |
| Librarian | `librarian@campus.test` | `Librarian123!` |
| Student | `student@campus.test` | `Student123!` |

The administrator is not part of the SQL seed. It was created through the
one-time setup flow in the supplied development database. A fresh schema import
leaves no administrator and requires `/setup-admin.php` with the configured
token.

---

## First-run administrator setup

1. Confirm `SETUP_TOKEN` is set in protected configuration. In Docker this comes
   from `.dev/.env`; on shared hosting set it as an environment variable or in
   `config/config.php` (never in `public/`).
2. Open `/setup-admin.php`.
3. Enter the setup token and the first administrator's details.
4. After success, setup records `library_settings.setup_completed_at` and the page
   is permanently locked.

The setup operation is transactional. Concurrent attempts serialize on the
administrator role row and re-check both the administrator count and the
completion timestamp before inserting.

For extra production hardening, clear `SETUP_TOKEN` after setup. The database
guard already prevents reuse, so clearing the token is a second layer.

---

## Production deployment notes (InfinityFree)

Phase 2 verification was local only. Before relying on a production deployment:

1. Upload only the contents of `public/` into the web root (`htdocs`).
2. Place `config/`, `includes/`, `database/` and `storage/` **outside** the web
   root. If the host forces them inside, keep the restrictive `.htaccess` files
   and verify direct HTTP access is denied.
2a. Set `APP_PUBLIC_PATH` to the web root. Error rendering and `COVER_UPLOAD_DIR`
   use this path; application code no longer requires `APP_ROOT/public` to exist.
   Leave the default (`APP_ROOT/public`) for root deployments.
3. Set database credentials and `SETUP_TOKEN` in protected configuration.
4. Import `database/database.sql`.
5. Run the one-time administrator setup.
6. Remove the demonstration accounts or change their passwords.
7. Ensure `storage/logs/` and the upload directory are writable by the web server.
8. Adjust `ErrorDocument` paths in `public/.htaccess` if the application is not
   served from the domain root; for subdirectory deployments set `APP_BASE_PATH`.
9. Serve the site over HTTPS. Session cookies become `Secure` automatically when
   HTTPS is detected.
10. Verify that `.env`, SQL files, logs and configuration cannot be downloaded.

Direct HTTP access was verified locally:

- `/config/config.php`, `/includes/functions.php`, `/database/database.sql`,
  `/storage/logs/app.log`, `/.dev/.env` → **404** (outside the document root)
- `/uploads/covers/probe.php` → **403** (upload directory denies executable types)
- `/assets/` and `/uploads/covers/` directory listings → **403**

---

## Security measures

- Passwords hashed with `password_hash(PASSWORD_DEFAULT)`; automatic rehash on login
- PDO native prepared statements throughout
- Output escaped through `e()` / `htmlspecialchars`
- CSRF token on every state-changing POST; token rotated on login and password change
- Session ID regenerated on login and logout
- `HttpOnly`, `SameSite=Lax` cookies; `Secure` on HTTPS
- Idle session timeout (1 hour)
- Server-side role guards for every protected page and action
- Deactivated accounts lose access on their next request
- Self-demotion and self-deactivation blocked server-side
- Last active administrator protected by a serialized transaction and locking reads
- Generic sign-in error messages to avoid account discovery
- Session-level failed-login throttle (5 failures / 15 minutes)
- Security headers: `nosniff`, `SAMEORIGIN`, referrer policy, permissions policy
- Strict Content-Security-Policy; HSTS only when HTTPS is active and `HSTS_MAX_AGE` is set
- Audit logging for setup, login, logout, registration, profile, password and user-management actions
- Failed-sign-in audit events for generic invalid credentials, wrong passwords and throttle blocks
- Password changes/resets revoke existing sessions via `users.session_version` (Docker import includes the column; see `database/migrations/0001_add_users_session_version.sql`)
- Control characters are rejected in passwords; NUL bytes cannot bypass password validation
- Upload allowlist: only `jpg/jpeg/png/webp` are served; SVG/HTML/XML/PHP/scripts are denied; `cover_upload_error()` validates extension, MIME and content
- `.dev/` is deny-all (`XAMPP` root also denies dotfiles and private files through `campus-library/.htaccess`)
- Setup-locked POST returns `403`; search escapes `%`/`_`; admin overdue card is conditional
- Demo credential banner only on local hosts (`APP_ENV=development` and `localhost/127.0.0.1`)
- Setup token compared with `hash_equals`; never stored in the database or public code

### Last-administrator locking order

All administrator-sensitive operations use the same lock order:

1. Lock the `admin` role row (`SELECT ... FOR UPDATE`).
2. Lock the target user row.
3. Perform a locking read of active administrators.
4. Apply the change and commit.

This prevents two concurrent requests from removing the final active
administrator.

---

## Error pages

- `403.php` — permission denied
- `404.php` — missing page or record
- `405.php` — non-POST request to a state-changing endpoint
- `500.php` — generic server error; technical details go to `storage/logs/app.log`

Apache error documents are configured in `public/.htaccess`.

---

## Next phase

Phase 3 can build on this foundation with catalogue management, borrow request
review, issuing and returns, fine calculation and reporting. Do not begin Phase 3
without approval.

See `TESTING.md` for the verification record and exact commands.
