# Production Database Export Report

Prepared 2026-11-19. Deliverable: `PRODUCTION_DATABASE_EXPORT.sql` (15,458 bytes, 327 lines). **No live hosting database was contacted or modified. No commit/push made. No application code, source schema or migration history was modified.**

## 1. Source schema and migration reconciliation

**Authoritative fresh-install source:** `database/database.sql` (target MySQL 8.x / MariaDB 10.6+). It was verified locally on MariaDB 10.11 (Docker).

Migrations inspected in execution order:

| Order | File | Effect | Incorporated in fresh schema? |
|---|---|---|---|
| 1 | `database/migrations/0001_add_users_session_version.sql` | Adds `users.session_version` via a temporary `DELIMITER $$` stored procedure, then drops the procedure. | **Yes** — `users.session_version INT UNSIGNED NOT NULL DEFAULT 1` is in the source schema. |
| 2 | `database/migrations/0002_fines_settlement.sql` | Adds `fines.status`, `assessed_at`, `settled_by_id`, `settled_at`, `idx_fines_status`, `idx_fines_settled_by` and `fk_fines_settled_by` via a temporary stored procedure. | **Yes** — all columns/indexes/FK are in the source schema except `idx_fines_settled_by` (see §2). |
| 3 | `database/migrations/0003_durable_login_throttle.sql` | Creates `login_throttles` and `schema_migrations`, records versions 0001–0003. No stored procedures. | **Yes** — both tables and the three version rows are in the source schema. |

**Dependencies:** 0002 requires `users` to exist for `fk_fines_settled_by`; 0003 is independent but records versions after 0001/0002. For a fresh install none of the migration files must be executed — the final state is already present in the export.

**Routines/triggers/views:** none in the authoritative schema. Migrations 0001/0002 create temporary stored procedures only for upgrades and drop them immediately; the final state has no routines. Verification confirmed `routines=0, triggers=0, views=0` after import.

## 2. Export preparation and changes from the source schema

Generated from `database/database.sql` using a one-off script in the temp directory; source files untouched.

- Removed the destructive block: `SET FOREIGN_KEY_CHECKS`, ten `DROP TABLE IF EXISTS` statements, and `SET time_zone`/`SET NAMES` session statements.
- Removed all demonstration rows: demo users (with bcrypt hashes and `@campus.test` addresses), 6 categories, 12 books and 22 book copies.
- Kept only required baseline rows: 3 roles, one `library_settings` row, and `schema_migrations` versions 0001/0002/0003.
- Used `CREATE TABLE IF NOT EXISTS` once per table (12 statements) — safe if phpMyAdmin is retried on a partially populated database; no duplicate table creation.
- Preserved all PRIMARY/UNIQUE/KEY indexes, CHECK constraints and FOREIGN KEY declarations.
- **Corrected migration-state gap:** migration 0002 explicitly creates `idx_fines_settled_by`; the old fresh schema relied on InnoDB's implicit FK index `fk_fines_settled_by`. The export adds `KEY idx_fines_settled_by (settled_by_id)` to `fines` so the FK reuses it, matching the final migrated state without creating a duplicate index. Verified by index-parity check.

## 3. Import order

1. Database already created in the hosting control panel (as stated by the user).
2. In phpMyAdmin, select the empty database → **Import** → choose `PRODUCTION_DATABASE_EXPORT.sql` → **Go** (official InfinityFree import procedure: `forum.infinityfree.com/t/49341`; phpMyAdmin `Import and export` docs confirm SQL file import into the selected database).
3. No `CREATE DATABASE`/`USE` in the file; phpMyAdmin must be pointed at the target database before import.
4. Do **not** run migrations 0001–0003 on this fresh database — versions are already recorded and the schema is final.

## 4. phpMyAdmin / DELIMITER compatibility

- `DELIMITER` is used only in upgrade migrations 0001/0002. **The production export contains no `DELIMITER`, no stored procedures and no routine-definition statements**, so those phpMyAdmin parser concerns do not apply to this fresh import.
- Official InfinityFree documentation confirms phpMyAdmin import via the control panel and notes upload/time limits with BigDump as the documented workaround for large dumps. This export is ~15 KB, far below typical limits.
- If an **older existing** database must be upgraded on InfinityFree and `DELIMITER` fails, use the manual idempotent `ALTER TABLE ... ADD COLUMN` / `ADD INDEX` fallback described in `DATABASE_DEPLOYMENT.md` instead of running those migration files.

## 5. Verification performed (local, not live)

A temporary scratch database `p51_export_verify` was created in the **local Docker MariaDB 10.11**, the export was imported with the `mariadb` CLI, and 29 checks were run by `C:\Users\leon2\AppData\Local\Temp\opencode\verify_export.py`. The scratch database was dropped after verification; the existing development database `campus_library` was read only for schema comparison and its records were untouched.

| Check | Result |
|---|---|
| Import command (`mariadb ... p51_export_verify < PRODUCTION_DATABASE_EXPORT.sql`) | **PASS** — exit 0 |
| 12 tables created; expected table set | **PASS** |
| Row counts: roles=3, settings=1, schema_migrations=3; all 9 other tables=0 | **PASS** |
| Column set/type parity with final-state dev schema | **PASS** — 104 vs 104 |
| Index parity with final-state dev schema | **PASS** |
| Foreign key parity with final-state dev schema | **PASS** |
| No routines/triggers/views | **PASS** — 0/0/0 |
| `schema_migrations` versions = 0001,0002,0003 | **PASS** |
| Final-state columns (`session_version`, fines settlement fields) | **PASS** |
| Key indexes/constraints (`idx_fines_status`, `idx_fines_settled_by`, `fk_fines_settled_by`, `uq_active_request_per_title`, `uq_active_borrowing_copy`) | **PASS** |
| Safety scan: no `DROP TABLE`, `DELETE FROM`, `TRUNCATE`, `CREATE DATABASE`, `USE`, `DELIMITER`, credentials, bcrypt hashes or demo addresses | **PASS** (matches only appear in explanatory comments) |

Verification script result: `EXPORT_VERIFY PASS`, exit 0.

## 6. Manual SQL steps still required

None for the fresh schema import. After import, through the application (not SQL):

1. Configure `config/config.local.php` with the real DB host/name/user/password and `APP_URL`/`APP_PUBLIC_PATH`.
2. Set `SETUP_TOKEN`, create the first administrator via `/setup-admin.php`, then clear the token.
3. Optionally add categories/books through the staff UI — no demo catalogue is preloaded.

For older existing databases only: apply migrations 0001→0002→0003 in order (or the manual fallback in `DATABASE_DEPLOYMENT.md`).

## 7. Verification checks to run after live import

Run in phpMyAdmin SQL tab on the production database:

```sql
-- 12 tables, expected names
SELECT COUNT(*) AS tables FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();
SHOW TABLES;

-- Baseline rows
SELECT 'roles' t, COUNT(*) n FROM roles
UNION ALL SELECT 'library_settings', COUNT(*) FROM library_settings
UNION ALL SELECT 'schema_migrations', COUNT(*) FROM schema_migrations
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'books', COUNT(*) FROM books;

-- Versions and final-state columns
SELECT version, applied_at FROM schema_migrations ORDER BY version;
SHOW COLUMNS FROM users LIKE 'session_version';
SHOW COLUMNS FROM fines LIKE 'settled_by_id';
SHOW INDEX FROM fines WHERE Key_name IN ('idx_fines_status', 'idx_fines_settled_by', 'fk_fines_settled_by');
```

Expected: 12 tables; roles=3, settings=1, schema_migrations=3, users=0, books=0; versions 0001–0003 present. Then the Phase 6 production smoke checklist in `PRODUCTION_SECURITY_CHECKLIST.md`.

## 8. Result

**PASS — production export prepared and locally verified.** No live database was touched, no credentials are embedded, source files/migrations/history are unchanged, and nothing was committed or pushed.
