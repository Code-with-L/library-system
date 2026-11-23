# Phase 6 — InfinityFree Deployment Audit

Prepared 2026-11-19. This audit records **actual repository findings** and **verified InfinityFree capabilities**. No real InfinityFree account was available in this environment; real-host deployment and verification remain explicitly pending.

## 1. Sources (fetched during this phase)

| Source | What it establishes |
|---|---|
| `https://infinityfree.com/` (official homepage, fetched 2026-11-19) | PHP **8.4**, MySQL **8.0 / MariaDB 11.4**, free SSL, full `.htaccess` support, 5 GB disk, 400 MySQL databases, free subdomains, no ads. |
| `https://forum.infinityfree.com/docs.json` and `/c/.../l/latest.json` (official knowledge base API) | Official docs categories: Websites and PHP, MySQL Databases, File Management and FTP, HTTPS and SSL, Email, Fair Usage Limits. |
| Official article **How to connect with MySQL** (`forum.infinityfree.com/t/49340`) | PDO **and MySQLi available on all accounts and PHP versions**; DB hostname must be the exact host from the control panel (`localhost` does **not** work); free DBs **cannot** be connected to from outside the hosting platform. |
| Official article **How to setup a new MySQL Database** (`/t/49342`) | Database created through control panel; name/user/host from control panel; password is the account password. |
| Official article **How to import a MySQL database backup** (`/t/49341`) | Import through phpMyAdmin; phpMyAdmin has upload-size and import-time limits; BigDump is the documented workaround for large dumps. |
| Official article **File size and upload limits** (`/t/49308`) | PHP upload limit **20 MB**; HTML/PHP/JS files **1 MB**; `.htaccess` **10 kB**; other files **10 MB**; oversized files are deleted automatically. |
| Official article **How to set custom `.htaccess` rules** (`/t/49350`) | Website files go in `htdocs/` (first domain) or `example.com/htdocs/` (addon/subdomain); custom `.htaccess` belongs in that folder; the account-root `.htaccess` cannot/should not be edited. |
| Official article **How to get Free SSL (HTTPS)** (`/t/49323`, category HTTPS and SSL) | Free SSL is available (article exists in official docs; activation steps in control panel). |
| Official article **How to send email with Gmail SMTP** (`/t/49239`) | PHP `mail()` is **heavily restricted**; external SMTP providers are not restricted. (Article archived but still official; a newer PHP-mail article is referenced.) |
| Official Admin post (`/t/64417/3`, surfaced via official forum search) | Cron is a **web cron** tool: it calls a URL on a schedule; it does not run CLI commands or PHP directly. |
| Official article **Browser Security System – Features and Limitations** (`/t/49353`) | Hosting applies a browser-challenge security system; non-browser clients (including some cron callers) can be blocked. |
| Official Admin post (`/t/106811/2`) | No SSH / command-line access; most outbound ports are firewalled; document-root changes are not available (web-hosting model). |

## 2. Repository findings (inspected, not assumed)

| Area | Finding | Implication |
|---|---|---|
| Structure | `config/`, `includes/`, `database/`, `storage/`, `.dev/` are siblings of `public/`; `public/` is the Docker document root (`.dev/000-default.conf`). | The existing split layout maps cleanly to InfinityFree: `public/*` → `htdocs/`, private dirs one level above. |
| Config loading | `config/config.php` reads environment variables with fallbacks (`DB_USER` default `root`, `DB_PASS` default `''`, `DB_HOST` default `127.0.0.1`); no local protected config file support yet. | Must add safe production config file support and fail-closed defaults. |
| DB connection | PDO with `ERRMODE_EXCEPTION`, native prepares, `SET time_zone = '+03:00'`. | PDO is available per official docs; explicit timezone offset avoids named-timezone dependency. |
| Hostname | Default `127.0.0.1` is correct for Docker, wrong on InfinityFree (exact host required). | Production config must set DB host from control panel. |
| Schema | `database/database.sql` targets MySQL 8.x / MariaDB 10.6+; uses CHECK constraints, generated `active_request_key`, InnoDB, utf8mb4; includes **demo users** with documented passwords; `DROP TABLE IF EXISTS` at top; no `CREATE DATABASE`/`USE`. | Clean install works on MariaDB 10.11 locally; production import must remove demo users and must never be run against a populated DB. |
| Migrations | `0001_add_users_session_version.sql`, `0002_fines_settlement.sql`; idempotent stored procedures using `DELIMITER`. | Need explicit version tracking and a durable-throttle migration. |
| Sessions | `includes/init.php` uses PHP filesystem sessions, `HttpOnly`/`SameSite=Lax`, `Secure` when HTTPS detected, idle timeout 3600s. | Shared hosting filesystem sessions work; session save path should not be overridden. |
| HTTPS detection | Only `HTTPS`/`SERVER_PORT` checked — no reverse-proxy `X-Forwarded-Proto` handling. | Behind hosting proxies this may not detect HTTPS → cookies/headers need proxy-aware detection plus explicit production override. |
| Error handling | `APP_DEBUG` gates detail; `handle_exception()` logs and renders a generic page; `render_error_document()` independent of DB. | Good for production; ensure production config never enables debug. |
| Uploads | `includes/uploads.php` validates extension, `getimagesize`, `finfo` MIME, max 2 MB; `public/uploads/covers/.htaccess` allowlists images and denies scripts/SVG; **no public upload endpoint exists yet**. | Validator is suitable; add safe server-side filename/storage helpers for any future endpoint; preserve existing images. |
| Email | No `mail()`, SMTP, PHPMailer or external service usage anywhere in `public/` or `includes/`. | PHP `mail()` restriction does not affect this app. No email features to add. |
| Cron | No cron usage. | Web cron only; not needed by this app. |
| Auth/throttle | `public/login.php` uses a **session-only** 5 failures / 15 min throttle (C-10 deferred limitation). | Implement DB-backed durable throttle using the available MySQL DB (no external service). |
| Logs | `config.php` writes `storage/logs/app.log`, created with `@mkdir`. | Verify writability; private dir must stay outside web root. |
| Largest PHP/SQL file | `includes/circulation.php` ≈ 24 KB, `database.sql` ≈ 19.8 KB. | Well under the 1 MB PHP / 10 MB other-file limits. |

## 3. Feature compatibility classification

| Feature | Classification | Evidence / notes |
|---|---|---|
| PHP 8.x application code (enums? no; match expressions, typed properties, `mixed`, `finfo`, `getimagesize`) | **Compatible** | Official PHP 8.4; code already runs on PHP 8.2 locally. |
| PDO MySQL, prepared statements | **Compatible** | Official: PDO on all accounts/versions. |
| MySQL 8.0 / MariaDB 11.4 schema | **Compatible with modification** | Locally verified on MariaDB 10.11; production import must be a clean, non-destructive procedure without demo users. Real host pending. |
| Generated column `active_request_key` + CHECK constraints | **Compatible** (requires MySQL 8.0.16+ / MariaDB 10.6+, both advertised) | No FK-referencing generated columns (MariaDB limitation documented in schema header). |
| `.htaccess` deny rules, ErrorDocument, headers | **Compatible** | Official: full `.htaccess` support in `htdocs/`; account-root file not editable. |
| URL rewriting | **Not required** — no rewrite rules used | Routes are real PHP files; no dependency on mod_rewrite. |
| PHP filesystem sessions | **Compatible** | Standard PHP; no custom save path; verify session dir writable on host (not yet verified). |
| HTTPS / secure cookies | **Requires configuration** | Free SSL available; proxy-aware detection and explicit `APP_URL`/cookie overrides to be added. |
| File uploads (cover images) | **Compatible with modification** | 20 MB PHP limit > 2 MB app limit; add safe filename/storage helpers; no endpoint exists yet. |
| Outbound SMTP / PHP `mail()` | **Unsupported (not used)** | Official: `mail()` heavily restricted. App has no email dependency. |
| Cron / background workers | **Unsupported / not needed** | Web cron only; app has no scheduled jobs or persistent processes. |
| Outbound sockets/API calls | **Not used** | App only talks to its own DB. |
| Persistent processes / long-running workers | **Unsupported** | Shared hosting model; app is request/response only. |
| Git deployments / Composer / SSH | **Unsupported** | No SSH; upload via File Manager/FTP. Deployment docs must be manual-upload based. |
| Database import size | **Requires configuration** | phpMyAdmin limits; our schema ≈ 20 KB is far below typical limits; BigDump documented as fallback. |
| Database privileges (CREATE PROCEDURE for migrations) | **Not yet verified** | Migration procedures use `CREATE PROCEDURE`; if denied, run equivalent idempotent `ALTER` statements manually (documented). |
| Error logs | **Requires configuration** | `storage/logs/` must be writable; private and outside doc root. |

## 3.1 Post-audit implementation status (Phase 6)

Section 2 records the state **at audit time**. Phase 6 subsequently implemented the items it identified:

- Protected `config/config.local.php` support and fail-closed DB defaults — **implemented**.
- Proxy-aware HTTPS detection (`is_https_request()`) via opt-in header trust — **implemented**.
- Durable DB-backed login throttle (C-10) + `schema_migrations` — **implemented**.
- Safe server-side cover filename/storage helpers — **implemented**.
- `APP_PUBLIC_PATH` guidance for `htdocs/` deployments — **documented in the runbook**.

Section 3 classifications remain valid, with "Requires configuration" now meaning "configure on the live host using the Phase 6 runbook", not "missing code". Real-host verification is still pending.

## 4. Docker development preservation

- `.dev/docker-compose.yml` (MariaDB 10.11 + PHP 8.2 Apache) and all `.dev/*.php` test harnesses remain unchanged in purpose.
- New production configuration must be additive: environment variables keep working in Docker; a protected `config/config.local.php` supplies production values when environment variables are absent.
- No application rewrite; no database destructive reset; no removal of existing records.
