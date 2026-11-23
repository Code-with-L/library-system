# Production Configuration

## Development vs production

Docker development is unchanged: `.dev/docker-compose.yml` supplies `APP_ENV=development`, DB variables and `SETUP_TOKEN` as environment variables. No code changes are needed to run the local environment.

Production uses a protected PHP config file at `config/config.local.php` (git-ignored, outside the public document root). Template: `config/config.local.example.php`. Environment variables always take precedence, so the same code works in both environments.

`config.php` exposes a single helper, `campus_config($key, $default, $emptyIsValue)`, used for all environment/local settings.

## Settings reference

| Key | Purpose | Production guidance |
|---|---|---|
| `APP_ENV` | `development` or `production` | `production`. Demo credentials require `development` **and** a local host. |
| `APP_DEBUG` | Show exception details | `false`. The exception handler logs technical details but renders a generic page. |
| `APP_URL` | Absolute HTTPS URL | Set to the live URL; used to derive `APP_BASE_PATH`. |
| `APP_BASE_PATH` | Subdirectory prefix | Leave empty for domain root; set explicitly for subdirectory installs. |
| `DB_HOST` | Exact MySQL host from control panel | Do **not** use `localhost` on InfinityFree. |
| `DB_PORT` | MySQL port | `3306`. |
| `DB_NAME` | Database name | From control panel. |
| `DB_USER` | Database user | From control panel. |
| `DB_PASS` | Account password | From client area; never committed. |
| `DB_CHARSET` | Charset | `utf8mb4`. |
| `SETUP_TOKEN` | One-time first-admin token | Long random value; clear after setup. |
| `APP_TRUST_PROXY_HEADERS` | Trust `X-Forwarded-Proto`/`X-Forwarded-SSL` | `true` only when the host sets these (InfinityFree terminates TLS upstream). |
| `SESSION_COOKIE_SECURE` | Force `Secure` cookie | `1` in production. Cookie is also auto-detected from HTTPS. |
| `APP_PUBLIC_PATH` | Absolute path to public files | **Required on InfinityFree:** set to the absolute `htdocs` path shown in File Manager/FTP; leaving it empty points cover storage at `APP_ROOT/public`, which does not exist there. Leave empty only in the Docker repo layout. |
| `HSTS_MAX_AGE` | HSTS seconds | `0` until HTTPS verified; then e.g. `31536000`. |

## Fail-safe defaults

- `DB_HOST`, `DB_NAME`, `DB_USER` default to empty strings. `config/database.php` refuses to connect and shows a generic "Database not configured" page if they are missing — no credentials, SQL or paths are displayed.
- `APP_DEBUG` defaults to `false`, so production errors never include stack traces.
- `APP_TIMEZONE` is fixed to `Africa/Nairobi`; `APP_CURRENCY` to `KES`; `config/database.php` sets the session time zone to `+03:00` without requiring named time zone tables.
- HSTS is sent only when HTTPS is active **and** `HSTS_MAX_AGE > 0`.

## HTTPS / proxy detection

`is_https_request()` in `includes/functions.php` checks, in order: `$_SERVER['HTTPS']`, port 443, and — only when `APP_TRUST_PROXY_HEADERS` is enabled — `X-Forwarded-Proto: https` / `X-Forwarded-SSL: on`. It is used for session cookies, security headers, HSTS and error pages. Never enable proxy-header trust on a host that does not set those headers.

## Session security

`includes/init.php` sets `HttpOnly`, `SameSite=Lax`, `Secure` (when HTTPS), strict mode, cookies-only, a 1-hour idle timeout, session ID regeneration on login/logout and rotating CSRF tokens. Do not override the session save path on shared hosting.

## Example local config (no real values)

```php
<?php
return [
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'APP_URL' => 'https://your-domain.example',
    'DB_HOST' => 'sqlXXX.infinityfree.com',
    'DB_NAME' => 'if0_XXXXXX_library',
    'DB_USER' => 'if0_XXXXXX',
    'DB_PASS' => 'REPLACE_WITH_ACCOUNT_PASSWORD',
    'SETUP_TOKEN' => '',
    'APP_TRUST_PROXY_HEADERS' => 'true',
    'SESSION_COOKIE_SECURE' => '1',
    'HSTS_MAX_AGE' => '0',
];
```

Verify with `php .dev/phase6_verify.php` (lints config, private rules, schema, uploads). Do not print the local config file in reports or logs.

**Permissions:** `storage/` and `storage/logs/` must be writable by the web server and remain private; `uploads/covers/` must be writable only if cover uploads are used (the directory already denies scripts/listings). PHP sessions use the host-provided default save directory — do not configure `session.save_path` in application code.
