# Database Deployment

## Compatibility

`database/database.sql` targets MySQL 8.x / MariaDB 10.6+ and was verified locally on MariaDB 10.11 (Docker). InfinityFree advertises MySQL 8.0 / MariaDB 11.4 (official homepage). Real-host import has **not** been performed in Phase 6 — label that step pending until executed on the live account.

Features used: InnoDB, `utf8mb4`, CHECK constraints (`chk_*`), a generated `active_request_key` column, UNIQUE keys for active request/copy guards, foreign keys with `ON UPDATE CASCADE` / `ON DELETE SET NULL`. All are supported by the advertised versions. No generated column references a foreign-key column (MariaDB limitation documented in the schema header).

## Clean installation (fresh database only)

1. Create the database in the hosting control panel (official `/t/49342`).
2. Import `database/database.sql` through phpMyAdmin (official `/t/49341`). It contains no `CREATE DATABASE`/`USE` statements.
3. **Remove demonstration accounts on a fresh production database** (order matters — `books.created_by` is `ON DELETE SET NULL`, so delete dependent catalogue rows *before* the users, otherwise `created_by` becomes NULL and the catalogue cleanup matches nothing):
   ```sql
   -- First: copies, then books, then categories (book.category_id is ON DELETE RESTRICT).
   DELETE FROM book_copies;
   DELETE FROM books;
   DELETE FROM categories;
   -- Last: demo users (books.created_by is ON DELETE SET NULL).
   DELETE FROM users WHERE email IN ('librarian@campus.test', 'student@campus.test');
   ```
   Adjust/omit catalogue seed removals as desired, but **never** reuse demo passwords. If you keep the demonstration books, change their `created_by` reference or keep at least one valid user. The `roles` and `library_settings` rows must remain (role IDs are referenced by `users.role_id` and the settings row is required by `get_settings()`). These statements are for a **fresh** database only.
4. Run `database/migrations/0003_durable_login_throttle.sql` if the schema came from an older release. Fresh `database.sql` already includes `login_throttles` and `schema_migrations`.
5. Create the first administrator through `/setup-admin.php` (see `DEPLOYMENT_INFINITYFREE.md`).

**Never** re-import `database.sql` into a populated database — it begins with `DROP TABLE IF EXISTS`.

## Upgrade procedure (existing data preserved)

1. Back up the database (phpMyAdmin → Export).
2. Copy only the new migration files in `database/migrations/` and apply them in version order, once each:
   - `0001_add_users_session_version.sql` — adds `users.session_version` if missing.
   - `0002_fines_settlement.sql` — adds fine settlement fields if missing.
   - `0003_durable_login_throttle.sql` — creates `login_throttles` + `schema_migrations`, records version 0003.
3. Migrations are idempotent (stored procedures check `information_schema` columns before `ALTER`). `schema_migrations` records applied versions; query it with `SELECT version, applied_at FROM schema_migrations ORDER BY version;`.
4. If the hosting DB user cannot create procedures, run the equivalent `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` statements manually and insert the version row with `INSERT IGNORE`.
5. Re-run `php .dev/phase6_verify.php` and the smoke checklist.

## Backup and restore

- Export from phpMyAdmin (SQL). Keep dated copies outside the hosting account.
- Also back up `htdocs/uploads/covers/` and `config/config.local.php`.
- Restore: create a fresh database, import the SQL, re-upload files, verify login/reports. Do not restore over live data without a fresh backup.

## Notes

- `config/database.php` sets `SET time_zone = '+03:00'`, so named time zone tables are not required.
- Durable login throttling stores only a SHA-256 hash of normalized email + IP, attempt counts and timestamps — no passwords or raw identifiers.
- Verify request #149 (pending) and historical fines are unchanged after any upgrade test; never delete records to make a test pass.
