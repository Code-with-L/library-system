# Phase 7.1 — Production Deployment ZIP Rebuild & Verification

Date: 2026-11-20. Scope: packaging and verification only. No application functionality was changed, nothing was committed, pushed, deployed, or uploaded, and the live website was not touched.

## 1. Deliverable

| | Value |
|---|---|
| Package | `campus-library/campus-library-infinityfree-v3.zip` |
| Files | **85** |
| Size | **123,860 bytes** |
| SHA-256 | **`8F1AC5EC37B7C05ED539A01A8158B0353F37C9C66A77BAD0B612D28B0EA10FD9`** |
| Archive root | files and directories at root (no `htdocs/` prefix) |
| Extraction target | InfinityFree `htdocs/` document root |

The previous verified package `campus-library-infinityfree-v2.zip` was **not overwritten**: its SHA-256 remains `79B10496BFBE752988DFD33453278B27E007A1CEB0FD8F1F555FCA73D15FEEC7` (121,115 bytes). The new file was written only after the build logic was fixed and was verified in isolation.

## 2. Packaging process

The approved Phase 6.3 → 6.4 process was reproduced exactly, from current source:

1. **Phase 6.3 layout:** public files copied into a staging `htdocs/` tree with every include reference rewired from `__DIR__ . '/../includes/...'` to `__DIR__ . '/_private/includes/...'` at the correct relative depth (123 references rewritten); private config/includes/migrations/storage placed under `_private/` with deny-all `.htaccess`; `APP_PUBLIC_PATH` default rewired to `dirname(APP_ROOT)`.
2. **Phase 6.4 compatibility:** the top-level `htdocs/` prefix stripped from every entry name, so File Manager can extract directly into the existing document root.
3. **Phase 7 content included:** current `public/assets/css/style.css` (the token-driven redesign), the grouped `includes/sidebar.php`, the redesigned homepage, dashboards, login, profile, staff catalogue, admin settings and all other Phase 7 edits.

## 3. Package structure

```text
<archive root>/
├── .htaccess, index.php, login.php, register.php, setup-admin.php
├── 403.php, 404.php, 405.php, 500.php, catalogue.php
├── account/, admin/, assets/css, assets/js, catalogue/, staff/, student/
├── uploads/covers/.htaccess
└── _private/                      HTTP-denied
    ├── .htaccess
    ├── config/     .htaccess, config.php, database.php, config.local.example.php
    ├── includes/   .htaccess + all 17 shared PHP files (incl. redesigned sidebar.php)
    ├── database/   .htaccess, migrations/0001,0002,0003.sql
    └── storage/    .htaccess, logs/.htaccess
```

## 4. Explicitly excluded files

Nothing local-only, secret, or unnecessary was packaged:

| Excluded | Reason |
|---|---|
| `.dev/` (docker-compose.yml, `.env`, php.ini, all PHP/Python test harnesses, PHASE2_1_CHECKLIST.md) | local-only development and tests; `.env` holds local DB password and setup token |
| `config/config.local.php` | real local credentials; never packaged |
| `config/config.local.example.php` template values | only the placeholder template is included (`REPLACE_WITH_ACCOUNT_PASSWORD`) |
| `database/database.sql` | full seed schema with demo users; only migrations are packaged |
| `PRODUCTION_DATABASE_EXPORT.sql`, `PRODUCTION_*.md`, `PHASE*.md`, `DATABASE_*.md`, `DEPLOYMENT_*.md`, `HOMEPAGE_*.md`, `README.md`, `TESTING.md` | documentation / reports |
| `.git/`, `.gitignore`, all ZIPs | repository metadata and prior packages |
| `config.local.php`, `*.log`, `.gitkeep`, fixtures, temp/screenshot/lint directories | secrets, runtime output, empty placeholders, test artifacts |

No development database credentials (`MARIADB_ROOT_PASSWORD`, `.dev/.env` values), live hosting identifiers, or real passwords are embedded. The only password string is the template placeholder. The source-level demo-account banner remains, as in the approved v2 package, but is guarded by `show_demo_credentials()` (development environment + localhost) and cannot appear in production.

## 5. Verification results

All checks passed (`P71_VERIFY PASS`, `PACKAGED_DEPS PASS`, `P71_SIM PASS`):

- **Structure:** 85 unique entries, no `htdocs/` prefix, no absolute paths or traversal; `index.php` and all four `_private/` directories at ZIP root; public root `.htaccess`, both CSS files, both JS files, config template, migration `0003` and upload `.htaccess` present.
- **Include paths:** no stale `../includes` references; every public PHP include resolves to `_private/includes` at the correct depth; `APP_PUBLIC_PATH` default rewired to `dirname(APP_ROOT)`; `config.local.php` load path preserved.
- **Source parity:** every packaged file matches the approved local source after the two documented transforms (include rewiring and `APP_PUBLIC_PATH` default). Private `.htaccess` files are generated deny-all rules by design; line endings are normalised to LF exactly as in the Phase 6.3 package.
- **Phase 7 content:** packaged stylesheet is byte-identical to the approved source and contains the design tokens/components; all nine Phase 7 source files plus the sidebar match their approved versions; "Built for every role" and the dashboard report fix are present; no phase branding in the sidebar.
- **Dependencies:** all `__DIR__` require targets in the package resolve inside the package (except the intentionally user-created `config.local.php`); no external runtime dependency, framework, vendor directory or build step.
- **Security:** every private `.htaccess` contains `Require all denied`; no live DB identifiers, no real credentials, no dev/test/doc filenames.
- **Integrity:** `testzip()` returned `None` (CRC OK); extracted tree matches the archive exactly.
- **PHP lint on packaged application:** **70 PHP files, 0 syntax errors**.
- **Local InfinityFree-layout simulation:** extracted package served over local Apache → homepage 200 with Phase 7 content, stylesheet 200, login 200; five private URLs returned 403 with no config content leaked. Simulation artifacts removed.
- **Delta vs v2:** the only changed files are the 12 approved Phase 7 / homepage-phase files (stylesheet, sidebar, homepage, login, three dashboards, profile, staff catalogue, admin settings, header, footer). File set is identical (85), with no additions or removals.

## 6. Manual deployment instructions

1. In InfinityFree File Manager, navigate to the existing document root `htdocs/`.
2. Extract `campus-library-infinityfree-v3.zip` **directly into `htdocs/`** (not the account root), overwriting the previous files. Hidden `.htaccess` files must be included.
3. Keep `_private/config/config.local.php` already created on the host: this package does not contain or overwrite it.
4. Verify `/index.php`, `/login.php` and `/assets/css/style.css` load with the new design; verify direct requests to `/_private/...` are denied.

## 7. Status

- Branch `main` at `c3cae95`; no commit, push, deploy or live-host access.
- Verdict: **PACKAGE VERIFIED LOCALLY — READY FOR MANUAL UPLOAD.**
- All verification above is local. Actual InfinityFree extraction, HTTPS, session and private-path behaviour on the live account remain unverified by this phase.
