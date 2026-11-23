# Production Security Checklist

Status: each item lists the control and how to verify it on the live host. Local Docker evidence is recorded in `PHASE6_IMPLEMENTATION_REPORT.md`; real-host items remain **NOT VERIFIED** until executed.

## Authentication and sessions

- [ ] Passwords hashed with `password_hash(PASSWORD_DEFAULT)`; rehash on login. (Code review: PASS; live: NOT VERIFIED)
- [ ] Session ID regenerated on login and logout; CSRF token rotated on login. (Code review: PASS)
- [ ] `HttpOnly`, `SameSite=Lax`, `Secure` cookies; strict mode; cookies only. (Local test: PASS; live: NOT VERIFIED)
- [ ] 1-hour idle timeout; deactivated accounts lose access on next request. (Code review: PASS)
- [ ] Password changes/resets increment `users.session_version`, revoking sessions. (Phase 2.1 tests: PASS)
- [ ] Generic sign-in errors; dummy hash comparison reduces user enumeration. (Code review: PASS)
- [ ] **Durable login throttle (C-10):** 5 failures/15 min keyed by hashed email+IP in `login_throttles`; session fallback retained. (Local fresh-session test: PASS; live: NOT VERIFIED)

## Authorization and CSRF

- [ ] Every protected page calls `require_role()`/`require_staff()` server-side. (Phase 5 tests: PASS)
- [ ] Student → staff/admin, librarian → admin: 403. (Phase 2.1/5 tests: PASS)
- [ ] State-changing POST endpoints call `verify_csrf()`; GET/without token → 403/405. (Phase 2.1/4.1 tests: PASS)
- [ ] Setup-admin token from protected config; one-time; locked by admin count and `setup_completed_at`. (Code review: PASS)

## Database and input

- [ ] PDO prepared statements for all user-influenced queries. (Code review + SQL-injection probes in earlier phases: PASS)
- [ ] Output escaped with `e()`; report exports use CSV formula-injection protection. (Phase 5 tests: PASS)
- [ ] `library_settings` changes validated; historical fines/loans not rewritten. (Phase 5 tests: PASS)

## Error handling and headers

- [ ] `APP_DEBUG=false` in production; exception handler shows generic page, logs technical details privately. (Config default: PASS; live: NOT VERIFIED)
- [ ] `database.php` fails closed with a generic page if credentials are missing. (Code review: PASS)
- [ ] CSP, `nosniff`, `SAMEORIGIN`, referrer/permissions policies on all responses including error pages. (Local header tests: PASS)
- [ ] HSTS only after HTTPS confirmed and `HSTS_MAX_AGE>0`. (Local test: PASS)
- [ ] No stack traces, SQL, filesystem paths or secrets in rendered pages or reports. (Code review: PASS)

## Files and uploads

- [ ] Public document root contains only `public/` contents; private dirs above `htdocs/` or deny-all `.htaccess`. (Local direct-access tests: PASS; live: NOT VERIFIED)
- [ ] `config/`, `includes/`, `database/`, `storage/`, `.dev/` not web-accessible. (Local: 404; live: NOT VERIFIED)
- [ ] Uploads restricted to jpg/jpeg/png/webp with extension + MIME + content validation; SVG/executables rejected; directory denies script execution and listings. (Upload tests: PASS)
- [ ] Server-side generated random filenames; no path traversal; missing/unwritable directory handled. (Phase 6 verify: PASS)
- [ ] No public upload endpoint currently exists — validator and storage helpers are ready for a future endpoint. (Code review: PASS)

## Hosting-specific

- [ ] Free SSL enabled; HTTPS URL set; proxy headers trusted only if host sets them. (Live: NOT VERIFIED)
- [ ] No email dependency (PHP `mail()` restricted on host). (Code review: PASS)
- [ ] No cron/persistent processes required (web cron only on host). (Code review: PASS)
- [ ] DB reachable only from the hosting platform; exact host used, not `localhost`. (Docs + config: PASS; live: NOT VERIFIED)

## Repeatable smoke commands (local Docker)

```bash
php -l on includes/, config/, public/          # PASS
python phase21_regression.py                   # auth/roles/CSRF/405/link crawl
python phase4_smoke.py                         # fines/smoke
python phase4_http_tests.py                    # late-return/fine/settlement e2e
python phase5_reporting_tests.py               # reports/CSV/authz
php .dev/duplicate_return_e2e.php              # duplicate return safety
php .dev/phase6_verify.php                     # Phase 6 config/schema/upload checks
```

For a live deployment, use this checklist manually in the browser. The repeatable HTTP script is `.dev/phase6_smoke.py` and its base URL variable is `SMOKE_BASE` (not `BASE`); it is designed for local Docker and includes fixture creation/cleanup via Docker, so do not upload or run it blindly against production data. Direct private-path URLs must return 403/404 on the live host.

Directory permissions to verify on the host:
- [ ] `storage/` and `storage/logs/` writable by the web server, private (outside `htdocs/` or deny-all `.htaccess`).
- [ ] `htdocs/uploads/covers/` writable by the web server only if cover uploads are used; script execution and listings denied by `.htaccess`.
- [ ] Session storage uses PHP's default host directory (no custom `session.save_path`); login test succeeds.
