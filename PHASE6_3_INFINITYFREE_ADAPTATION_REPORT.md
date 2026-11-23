# Phase 6.3 — InfinityFree Hosting Architecture Adaptation Report

Prepared 2026-11-20. Scope: adapt the existing production package so **everything lives inside InfinityFree's mandatory `htdocs` directory**. No live hosting account or production database was accessed. No commit/push. No business logic, schema or migration history changed.

**Package:** `campus-library/campus-library-infinityfree.zip` — 85 files, 121,898 bytes.
SHA-256: `3FE704FC6247690535BAEEFFDC3D9490D1660C0886D8ACBD9032C755A7988FE1`.

## 1. What changed (deployment layout only)

The Phase 6.2 package put `config/`, `includes/`, `database/` and `storage/` **outside** `htdocs`. InfinityFree deletes files outside `htdocs` and restricts PHP to that directory, so that layout is incompatible.

The new package places the whole application under `htdocs/`:

```
htdocs/
  index.php, login.php, register.php, setup-admin.php, ... (public pages)
  403.php, 404.php, 405.php, 500.php
  catalogue.php
  account/, admin/, catalogue/, staff/, student/       (public routes)
  assets/css, assets/js                                (public assets)
  uploads/covers/.htaccess                             (public upload dir, image allowlist)
  .htaccess                                            (public ErrorDocument rules)
  _private/                                            (HTTP-denied, inside htdocs)
    .htaccess                                          (Require all denied)
    config/
      .htaccess                                        (Require all denied)
      config.php
      database.php
      config.local.example.php                         (template only; no real credentials)
    includes/
      .htaccess                                        (Require all denied)
      init.php, functions.php, auth.php, ... all shared PHP
    database/
      .htaccess                                        (Require all denied)
      migrations/0001,0002,0003.sql                    (no database.sql)
    storage/
      .htaccess                                        (Require all denied)
      logs/.htaccess                                   (Require all denied)
```

`config.local.php` is **not in the package**; it must be created on the host in `htdocs/_private/config/`.

## 2. Include/path rewiring (verified, business logic untouched)

- All 49 public PHP include references were rewritten from e.g. `__DIR__ . '/../includes/init.php'` / `'/../../includes/...'` / `'/../../../includes/...'` to the corresponding `_private/includes` path at the same depth.
- `config/config.php` (deployment copy only): `APP_PUBLIC_PATH` default changed from `APP_ROOT . '/public'` to `dirname(APP_ROOT)`, which resolves to `htdocs` because `APP_ROOT` is `htdocs/_private`. `config.local.php` overrides it with the absolute server path.
- `APP_ROOT` remains `htdocs/_private`; `LOG_DIRECTORY` = `htdocs/_private/storage/logs`; `COVER_UPLOAD_DIR` = `htdocs/uploads/covers`. All filesystem targets are inside `htdocs`.
- Source parity check (`P63_SOURCE_PARITY PASS`) confirmed the package differs from the repository **only** in include-path and public-path rewiring — no business logic changed.
- Public routes are unchanged: pages are at the same URL paths (`/index.php`, `/login.php`, `/staff/...`, etc.); `url()` still emits root-relative paths, and `BASE_PATH` is empty at the domain root.

## 3. Private access denial

Every private directory has an `.htaccess` with `Options -Indexes -ExecCGI` and `Require all denied` (plus Apache 2.2 fallback). Denial applies to HTTP only; PHP `require` still resolves these files normally. A locally simulated HTTP test proved all private URLs return **403** and no config content leaks (see §6). `htdocs/uploads/covers/.htaccess` retains its image-only allowlist and denies PHP/SVG.

## 4. Setup-admin and SETUP_TOKEN review

`htdocs/setup-admin.php` is unchanged in logic. It:

- aborts 404 when `SETUP_TOKEN` is empty (so leaving the template value empty disables setup),
- compares the submitted token with `hash_equals()`,
- requires a valid CSRF token on POST,
- refuses reuse once an admin exists or `library_settings.setup_completed_at` is set,
- serializes concurrent attempts with `lock_admin_role()` and locking reads inside a transaction.

Host procedure: set a long random token in `config/config.local.php`, create the admin, then clear the token. `setup_completed_at` provides the permanent DB-side lock.

## 5. PHP / open_basedir / extension compatibility

- Code uses PHP 8.0+ features (`match`, `mixed`, union types) and runs on local PHP 8.2.35; official InfinityFree advertises PHP 8.4.
- Extensions used: `pdo_mysql`, `mbstring`, `fileinfo`, `ctype`, `filter`, `session`, `json`; all present locally. No external APIs, mail(), cURL or GD.
- `open_basedir`: the adapted layout keeps every application file inside `htdocs`. A simulated CLI run with `open_basedir` restricted to the htdocs tree and including `init.php` succeeded (`OPEN_BASEDIR_INCLUDE_OK`).
- `php://temp` used by CSV export may use the host temp directory for very large exports; current exports are small and stay in memory. Live host temp-dir policy is **not verified**.
- `finfo` magic-file internals and session save path are host-managed; live behaviour is **not verified**.

## 6. Verification — locally simulated vs actual hosting

**Locally simulated (Apache, package copied under the dev document root; not InfinityFree):**
- `P63_PACKAGE_VERIFY PASS` — 85 files, all under `htdocs/`, required private `.htaccess` rules, no dev/secret/doc artifacts, extracted bytes match ZIP, no live DB identifiers embedded, only password placeholder.
- `P63_SOURCE_PARITY PASS` — package files identical to source after reversing include/public-path rewrites.
- PHP lint: application 70 files + simulated package 72 files — no syntax errors.
- `P63_SIM_SMOKE PASS` — homepage and assets 200 (proves rewired includes work); all seven private URLs 403 with no leaked config content; upload PHP probe denied 403; image served with `nosniff`; login CSRF token, login POST 302, cookie `HttpOnly`+`SameSite=Lax`+`Secure` via `X-Forwarded-Proto`.
- `OPEN_BASEDIR_INCLUDE_OK` — CLI include with `open_basedir` limited to htdocs.
- Regression suites: `PHASE21_REGRESSION PASS`, `PHASE4_FINE_SMOKES PASS`, `PHASE4_FINE_HTTP_TESTS PASS`, `PHASE5_REPORTING_TESTS PASS`, `UPLOAD_HTTP_TESTS PASS`, `PHASE5_DUPLICATE_RETURN_E2E PASS`, `PHASE6_VERIFY PASS`, `PHASE4_CALC_MATRIX PASS`, `PHASE4_FINES_ENGINE_TESTS PASS`, Circulation A–H (5 modes) PASS, Admin-guard (5 modes) PASS.
- One transient `PHASE4_FINE_HTTP_TESTS FAIL` occurred when the environment date rolled to Nov 20: the test's raw SQL helper used UTC while PHP used Nairobi, producing a 2-day fine. After setting `SET time_zone = '+03:00'` in the **temp test helper only**, the suite passed with the expected 1-day/KES 10.00 fine. No application code was changed.

**Actual InfinityFree verification: NOT PERFORMED.** No upload, no live DB access, no live HTTPS/session/private-path confirmation. Do not treat the simulated results as live hosting proof.

## 7. Exact upload instructions

1. Extract `campus-library-infinityfree.zip`. It yields a single `htdocs/` folder.
2. Upload the **contents of `htdocs/`** into the InfinityFree document root (`htdocs/`), preserving hidden `.htaccess` files:
   - public pages, `assets/`, `uploads/covers/.htaccess`, `account/`, `admin/`, `catalogue/`, `staff/`, `student/`, `403.php`…`500.php`
   - the entire `_private/` directory with all its `.htaccess` files.
3. There is nothing to upload above `htdocs/` — this is the key adaptation.
4. Create `htdocs/_private/config/config.local.php` from `config.local.example.php` with: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-real-domain`, `DB_HOST=sqlXXX.infinityfree.com`, `DB_NAME=if0_XXXXXX_campus_library`, `DB_USER=if0_XXXXXX`, `DB_PASS` = privately supplied password, `APP_PUBLIC_PATH` = **absolute path to the server `htdocs` folder**, `APP_TRUST_PROXY_HEADERS=true`, `SESSION_COOKIE_SECURE=1`, `HSTS_MAX_AGE=0`, and a long random `SETUP_TOKEN`. (These identifiers are supplied by you; they are not embedded in the ZIP.)
5. Make `htdocs/_private/storage/logs/` writable by the web server; keep `uploads/covers/` writable if cover uploads are introduced later.
6. Database is already imported (12 tables, versions 0001–0003, 3 roles, 1 settings row). Do not run migrations or import `database.sql`.
7. Open `https://your-domain/setup-admin.php`, create the first administrator, then clear `SETUP_TOKEN`.
8. Enable SSL; after HTTPS is confirmed, set `HSTS_MAX_AGE` and re-test.

## 8. Post-upload verification (manual, on InfinityFree)

- `/index.php`, `/login.php`, `/assets/css/style.css` return 200; no 500s.
- Direct requests to `/_private/config/config.php`, `/_private/config/config.local.php`, `/_private/includes/init.php`, `/_private/database/migrations/0003_durable_login_throttle.sql`, `/_private/storage/logs/.htaccess` return 403/404 and never expose content.
- `/uploads/covers/` denies scripts/listings; image files serve with `nosniff`.
- Login/logout, registration, role separation, catalogue, request→approve→issue→return→fine→settle, reports/CSV, admin settings/activity.
- Security headers present; HSTS only over HTTPS; cookies `Secure`/`HttpOnly`/`SameSite=Lax`.
- No stack traces, SQL, paths or credentials in responses.

## 9. Final status

- Branch `main` at `c3cae95`; no commit/push.
- New deliverables: `campus-library-infinityfree.zip`, `PHASE6_3_INFINITYFREE_ADAPTATION_REPORT.md`. The earlier `campus-library-production.zip` remains as a superseded artifact.
- Source code, schema and migration history unchanged by this phase; no live hosting/database access.
- Verdict: **PACKAGE ADAPTED AND LOCALLY VERIFIED — READY FOR MANUAL UPLOAD**. Live InfinityFree compatibility remains unverified.

**Exact next manual action:** extract `campus-library-infinityfree.zip` and upload its `htdocs/` contents into the InfinityFree document root (including `_private/`), create `htdocs/_private/config/config.local.php` with your private values and absolute `APP_PUBLIC_PATH`, ensure `storage/logs/` is writable, then visit `https://your-domain/setup-admin.php` to create the first administrator.
