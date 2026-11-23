# Deploying Campus Library to InfinityFree

This guide follows the **verified** InfinityFree capabilities recorded in `PHASE6_DEPLOYMENT_AUDIT.md`. It describes a manual upload deployment. **No real InfinityFree deployment was performed during Phase 6** — follow this procedure on the live account and verify each step.

## 1. Create the hosting account and website

1. Register at `https://infinityfree.com/` and create a free hosting account.
2. In the client area, open **Accounts → Manage → Control Panel**.
3. Note the account's document root: the first domain uses `htdocs/`; addon domains/subdomains use `example.com/htdocs/` (official: *How to set custom `.htaccess` rules*, `/t/49350`). The document root cannot be changed (official admin statement, `/t/106811/2`).

## 2. Create the MySQL database

1. Control panel → **MySQL Databases → New Database** → enter a short name → **Create Database** (official: `/t/49342`).
2. Note the **DB name, user and exact host** under *Current Databases*. Do **not** use `localhost` — it does not work on InfinityFree (official: `/t/49340`).
3. The database password is the hosting account password (official: `/t/49342`).

## 3. Import the schema

1. Control panel → **phpMyAdmin** → select the new database → **Import** → choose `database/database.sql` → **Go** (official: `/t/49341`).
2. `database.sql` is ~20 KB, well below typical phpMyAdmin limits. For large future dumps, the official workaround is BigDump.
3. `database.sql` includes **demo users** (`librarian@campus.test`, `student@campus.test`) with known local passwords. **Remove them immediately after import on a fresh production database** — see `DATABASE_DEPLOYMENT.md`. Do not run the script against a populated database: it begins with `DROP TABLE IF EXISTS`.
4. Run `database/migrations/0003_durable_login_throttle.sql` if importing an older schema without `login_throttles` (fresh `database.sql` already contains it).

## 4. Configure production credentials

1. Copy `config/config.local.example.php` to `config/config.local.php` on the account (outside `htdocs/`).
2. Fill in `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_URL`, `SETUP_TOKEN`, `APP_TRUST_PROXY_HEADERS=true`, `SESSION_COOKIE_SECURE=1`.
3. **Set `APP_PUBLIC_PATH` to the absolute `htdocs` directory path** shown in File Manager/FTP (for example `/home/volX.../epiz_XXXXX/yourdomain/htdocs`). Leaving it empty points cover uploads at `APP_ROOT/public`, which does not exist in this layout. Environment variables take precedence over this file; InfinityFree does not normally expose custom environment variables through the control panel, so `config.local.php` is the intended production mechanism.
4. Never commit or upload `config/config.local.php` into `htdocs/`. It is git-ignored. Docker preserves its environment-variable setup.
5. Keep `APP_DEBUG=false`. Leave `HSTS_MAX_AGE=0` until HTTPS is confirmed.

## 5. Upload application files

Recommended safe layout (private files above the web root):
```
/home/account/
  htdocs/            <- contents of public/ only (index.php, login.php, assets/, staff/, ...)
  config/            <- config/config.php, config.local.php (private)
  includes/          <- includes/*.php (private)
  database/          <- schema and migrations (private)
  storage/           <- logs (private, writable)
  .dev/              <- DO NOT upload
```
Because public pages resolve includes via `__DIR__/../includes`, this layout works unchanged. If the host's file manager forces everything inside `htdocs/`, keep the deny-all `.htaccess` files in `config/`, `includes/`, `database/`, `storage/` and verify with step 10.

Upload via File Manager or FTP (no SSH available — official). Do not upload `.dev/`, `.git/`, `.env`, local logs or test files.

Documented permissions (set in File Manager/FTP where supported):
- `storage/` and `storage/logs/`: **writable by the web server** (typically 0755/0775); logs must stay private and outside `htdocs/`.
- `htdocs/uploads/covers/`: **writable by the web server** (0755/0775) when cover uploads are used; it already denies script execution and listings via `.htaccess`. No cover upload endpoint exists yet, so write access is only a future requirement.
- Session storage uses PHP's host-provided default directory; do not set a custom `session.save_path`. Verify sessions work after upload (login test in step 9).

## 6. Set the application URL

Set `APP_URL` in `config/config.local.php` to the real HTTPS URL (e.g. `https://yourdomain.rf.gd`). `APP_BASE_PATH` is derived from its path component; override explicitly for subdirectory installs. `url()` produces root-relative links, which work at the domain root.

## 7. Create the first administrator

1. Set a long random `SETUP_TOKEN` in `config/config.local.php`.
2. Visit `https://yourdomain/setup-admin.php`, enter the token and admin details.
3. Setup is transactional and one-time (records `library_settings.setup_completed_at`). Clear `SETUP_TOKEN` afterwards.

## 8. Enable HTTPS

Control panel → **HTTPS and SSL** → enable free SSL (official: `/t/49323`). Confirm the browser shows HTTPS, then set `HSTS_MAX_AGE` (e.g. `31536000`) and re-test. Session cookies become `Secure` automatically via proxy-aware detection or `SESSION_COOKIE_SECURE=1`.

## 9. Test the application

Use the smoke checklist in `PRODUCTION_SECURITY_CHECKLIST.md`. The repeatable script `.dev/phase6_smoke.py` was written for **local Docker** and uses `SMOKE_BASE` (not `BASE`) plus a Docker-based SQL helper for fixture cleanup. **Do not upload `.dev/` to the host.** To test a live site, either run the checklist manually in the browser, or run a local copy of the script with `SMOKE_BASE=https://yourdomain...` while keeping its SQL helper pointed at a local database only if you intend to mutate local fixtures — never point it at production data. The script can create/delete a temporary student fixture; do not run it against an account where that is unacceptable. Verify login, catalogue, circulation, fines, reports, CSV export, settings, activity log, upload validation, CSRF and private-path denial.

## 10. Verify private paths are not accessible

Request these URLs directly; each must be denied (404/403), never downloaded:
- `/config/config.php`, `/config/config.local.php`, `/config/database.php`
- `/includes/functions.php`, `/includes/reporting.php`
- `/database/database.sql`, `/storage/logs/app.log`
- `/uploads/covers/probe.php` (temporary probe only — delete after test)
- `/../config/config.php` (path traversal attempts)

## 11. Backups

- **Database**: phpMyAdmin → Export → SQL (official backup guidance: `/t/49304`). Schedule regular exports; store outside the account.
- **Files**: download `htdocs/uploads/covers/` and `config/config.local.php` separately via File Manager/FTP.
- Restore: create a fresh DB, import the export via phpMyAdmin, re-upload files, verify. Never restore over live data without a fresh backup.

## 12. Safe updates

1. Back up DB and files first.
2. Upload changed public files, then private files.
3. Run any new `database/migrations/*.sql` **once**, in version order, and check `schema_migrations`.
4. Run `.dev/phase6_verify.php` locally and the live smoke checklist.
5. Keep the previous release archive for rollback.

## Known hosting limitations

- PHP `mail()` is heavily restricted; this app sends no email (official: `/t/49239`).
- No SSH/CLI or persistent workers; the app is request/response only.
- Cron is URL-based web cron only; this app has no scheduled jobs.
- Outbound database connections from outside the host are blocked (official: `/t/49340`); use phpMyAdmin on the host.
- Real-host PHP extension list and session save path were **not verified** in Phase 6 — confirm in the control panel after deployment.
