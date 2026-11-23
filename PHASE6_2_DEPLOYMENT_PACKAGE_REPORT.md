# Phase 6.2 — Production Application Deployment Package Report

Prepared 2026-11-19. Scope: package preparation and local verification only. **No upload to InfinityFree, no live database access, no account creation, no production data changes, no commits/pushes.**

**Package:** `campus-library/campus-library-production.zip` — 85 files, 123,177 bytes.
SHA-256: `82776D0C1B05A28CEC910EBA6B91A46D8F2F6C7372E03E5C931784FCF5DF6394`.

## 1. Compatibility audit (code inspection + official evidence)

| Area | Finding / evidence |
|---|---|
| PHP version | Code uses PHP 8.0+ features (`match`, `mixed`, union types, `str_ends_with`) and runs on local PHP 8.2.35. Official InfinityFree homepage advertises PHP 8.4 — **compatible**. Live PHP version not verified. |
| Required extensions | `pdo_mysql`, `mbstring`, `fileinfo`, `ctype`, `filter`, `session`, `json`, `openssl` are used; all are available in the local Docker PHP image. `getimagesize()` is core. No GD, cURL, Imagick, mail or external API usage found. |
| MySQL/PDO | PDO with `ERRMODE_EXCEPTION`, native prepares; schema verified locally on MariaDB 10.11. Official InfinityFree: MySQL 8.0 / MariaDB 11.4, PDO on all accounts; exact DB hostname required (not `localhost`). |
| Sessions | `includes/init.php`: PHP filesystem sessions, `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS detected, strict mode, cookies-only, 1-hour idle timeout, ID regeneration on login/logout. No custom `session.save_path`. |
| Uploads | `includes/uploads.php`: extension allowlist (jpg/jpeg/png/webp), `getimagesize()`, `finfo` MIME/content check, 2 MB app limit; `htdocs/uploads/covers/.htaccess` denies scripts/SVG and listings. No public upload endpoint exists yet; helpers are ready for a future endpoint. |
| URL/routing | Real PHP files only; no rewrite rules. `config.php` derives `BASE_PATH` from `APP_URL` or override; `url()` emits root-relative paths. Works at domain root; subdirectory installs set `APP_BASE_PATH`. |
| `.htaccess` | `htdocs/.htaccess` sets `ErrorDocument` to application pages; private dirs use deny-all `.htaccess`; root `.htaccess` included for defence-in-depth if the account root is web-accessible. Official InfinityFree supports custom `.htaccess` in `htdocs/`; account-root file is not editable. |
| Private-dir protection | `config/`, `includes/`, `database/`, `storage/` are packaged **outside** `htdocs/`; deny-all `.htaccess` included as a second layer. Local smoke tests return 404 for private URLs. |
| Writable storage | `storage/` and `storage/logs/` must be web-server writable and stay private; `uploads/covers/` writable only if cover uploads are introduced. Directory rule files are in the package; no local logs are included. |

**No live hosting compatibility has been verified** — all findings are local/code/official-doc based.

## 2. Application configuration verification

`config/config.php` reads environment variables first, then `config/config.local.php` (outside `htdocs/`). It supports:

- InfinityFree MySQL hostname, database name, username and privately supplied password (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
- Production URL (`APP_URL`), public path (`APP_PUBLIC_PATH`), HTTPS/proxy detection (`APP_TRUST_PROXY_HEADERS`, `SESSION_COOKIE_SECURE`), `APP_DEBUG=false`, `HSTS_MAX_AGE`.
- Fail-closed DB defaults: missing credentials show a generic page, no secrets/SQL/paths.

A local config-resolution test with **placeholder** values passed all 11 checks (`P62_CONFIG_CHECK PASS`). **No real credentials were inserted into the package or report.** The exact host/database/username you supplied should be entered privately in `config.local.php` on the account; the password is the account password from the client area.

`config/config.local.example.php` contains only placeholders (`sqlXXX.infinityfree.com`, `if0_XXXXXX_library`, `REPLACE_WITH_ACCOUNT_PASSWORD`). Package scan confirmed no live DB identifiers are embedded.

## 3. Deployment package contents and exclusions

Included (85 files):
- `campus-library/htdocs/` — all 56 public files from `public/`: PHP pages, `assets/css`, `assets/js`, `.htaccess`, error pages, `uploads/covers/.htaccess`.
- `campus-library/config/` — `.htaccess`, `config.php`, `database.php`, `config.local.example.php` (template only).
- `campus-library/includes/` — `.htaccess` and all shared PHP includes.
- `campus-library/database/` — `.htaccess` and `migrations/0001…0003.sql` only; **no `database.sql`** (already imported; contains development demo hashes).
- `campus-library/storage/` — `.htaccess` and `storage/logs/.htaccess`; no log files.
- `campus-library/.htaccess` — defence-in-depth root rule.

Excluded: `.dev/`, `.git/`, `.env`, `config.local.php`, `database/database.sql`, `storage/logs/*.log`, all phase/deployment reports and documentation, test fixtures, `.gitkeep`, local caches and temporary files. Package verification confirmed zero forbidden artifacts.

Integrity: `PACKAGE_VERIFY PASS` — 85 files, required entries present, no secret/doc artifacts, extracted bytes match ZIP.

## 4. Exact upload directory structure

Extract the ZIP locally. It produces:

```
campus-library/                  <- private application root (upload ABOVE htdocs)
  .htaccess
  config/                        <- private
  includes/                      <- private
  database/migrations/           <- private
  storage/                       <- private, writable
  htdocs/                        <- public document root contents
    index.php, login.php, ...
    assets/css, assets/js
    uploads/covers/.htaccess
    .htaccess
```

On InfinityFree:

```
/home/account/                   <- account root (private area)
  config/          <- from package
  includes/        <- from package
  database/        <- from package
  storage/         <- from package (writable)
  .htaccess        <- from package (defence in depth)
  htdocs/          <- InfinityFree document root
    [upload contents of package htdocs/ here]
```

Public pages use `__DIR__ . '/../includes/init.php'`, so placing `includes/` one level above `htdocs/` works unchanged. If the host's file manager forces everything inside `htdocs/`, keep the deny-all `.htaccess` files and verify private-path denial (step 6).

## 5. Configuration steps (manual, on the account)

1. Copy `config/config.local.example.php` to `config/config.local.php` (private root, never inside `htdocs/`).
2. Set: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-real-domain`, `APP_BASE_PATH=''`, `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` from your control panel, `DB_CHARSET=utf8mb4`, `APP_TRUST_PROXY_HEADERS=true`, `SESSION_COOKIE_SECURE=1`, `HSTS_MAX_AGE=0` initially.
3. **Set `APP_PUBLIC_PATH` to the absolute `htdocs` path** shown in File Manager/FTP (e.g. `/home/volX.../epiz_XXXXX/yourdomain/htdocs`). Leaving it empty breaks cover storage in this layout.
4. Set a long random `SETUP_TOKEN` for first-admin creation; clear it afterwards.
5. Database already imported: 12 tables, versions 0001–0003, 3 roles, 1 settings row. Do **not** run migrations or re-import `database.sql`.
6. Create the first administrator via `https://your-domain/setup-admin.php` with the token, then clear `SETUP_TOKEN`.
7. Enable free SSL, confirm HTTPS, then set `HSTS_MAX_AGE` (e.g. `31536000`) and re-test.

## 6. Verification after upload (manual)

- Homepage `/index.php` and static assets load.
- Login/logout, registration, role separation (student cannot access admin/staff, librarian cannot access admin).
- Catalogue search, book request, librarian approve → issue → return → fine assessment and settlement.
- Staff reports and CSV exports; admin reports, activity log, settings page.
- Direct private URLs return 403/404: `/config/config.php`, `/config/config.local.php`, `/includes/functions.php`, `/database/migrations/0003...sql`, `/storage/logs/app.log`.
- Security headers present (CSP, `nosniff`, `SAMEORIGIN`, referrer/permissions policies); HSTS only after HTTPS confirmed.
- No stack traces, SQL, paths or credentials in rendered pages.
- Session cookie has `Secure`, `HttpOnly`, `SameSite=Lax`.

Use `PRODUCTION_SECURITY_CHECKLIST.md` (local file, not packaged) for the full manual list.

## 7. Local verification performed

| Check | Result |
|---|---|
| PHP lint on extracted package (`config`, `includes`, `htdocs`) | **PASS** — 70 files, no syntax errors |
| Package integrity (85 files, required entries, no forbidden artifacts, extracted bytes match ZIP) | **PACKAGE_VERIFY PASS** |
| Production config resolution with placeholder values | **P62_CONFIG_CHECK PASS** (11 checks) |
| `python phase21_regression.py` | **PASS** |
| `python phase4_smoke.py` | **PASS** |
| `python phase4_http_tests.py` | **PASS** |
| `python phase5_reporting_tests.py` | **PASS** |
| `php .dev/duplicate_return_e2e.php` | **PASS** |
| `php .dev/phase6_verify.php` | **PASS** |
| `php .dev/phase4_calc_matrix.php`, `phase4_fines_engine_test.php` | **PASS** |
| Circulation Rules A–H (5 modes), Admin-guard (5 modes) | **PASS ×10** |
| `python .dev/phase6_smoke.py` (includes private-path denial) | **PASS** |
| Live InfinityFree upload/HTTPS/session check | **NOT PERFORMED** |

## 8. Final status

- Branch `main` at `c3cae95`; no commit/push.
- Package: `campus-library/campus-library-production.zip` (untracked deliverable); report: `PHASE6_2_DEPLOYMENT_PACKAGE_REPORT.md`.
- Source code, schema and migration history unchanged by this phase.
- No live hosting account or production database was accessed.
- Verdict: **PACKAGE READY FOR MANUAL UPLOAD** — real-host compatibility remains unverified until you perform §5–§6 on InfinityFree.

**Exact next manual action:** extract `campus-library-production.zip`, upload `campus-library/htdocs/*` into the InfinityFree `htdocs/` folder, upload `config/`, `includes/`, `database/`, `storage/` and `.htaccess` to the account root one level above `htdocs/`, create `config/config.local.php` with your private values (including the absolute `APP_PUBLIC_PATH`), then open `https://your-domain/setup-admin.php` to create the first administrator.
