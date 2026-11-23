# Campus Library — Production Deployment Guide (InfinityFree)

Prepared after the final local verification. **The live site has not been touched.** This guide is for the manual upload only.

## 1. Files to use

| File | Size | SHA-256 |
|---|---|---|
| **`campus-library-infinityfree-v55.zip`** (current) | 280,024 bytes | `79525678BF185B1ED4348DBF958C5EE7D40BC600781B57BC552681A95C8FA132` |
| `campus-library-infinityfree-v54.zip` | 279,883 bytes | `9E5D93F3286502EFAC0F71D31D5C28E3B1BE47495F2610EC2B226D618DDD34E2` |
| `campus-library-infinityfree-v53.zip` | 279,879 bytes | `3D31E2DB0A571D817CE63F822A1028F0E2D76E1D542C86D9C749B3D5F51C4A1C` |
| `campus-library-infinityfree-v52.zip` | 278,084 bytes | `AE3904AF9E6389E212BEF2D7858F62EF2608EFC44146FDE64015875F017CEE01` |
| `campus-library-infinityfree-v51.zip` | 278,501 bytes | `8B823F947A2591A1168FCD0B3FF5A411F6DB27BA672F297919382B4AE761EE78` |
| `campus-library-infinityfree-v5.zip` | 278,553 bytes | `20F21800DA6EAB3F9DD56F8177A5F120CAEFA26B2294A898367F6D1EEB8BAAAA` |
| `BOOK_COVERS.zip` | 9,072,249 bytes | `0BBE9497EB75E906BE74BCA03D05B441A40DD91058EBD7BC4E4F7EC309B1AD90` |
| `REAL_BOOKS_IMPORT.sql` | 395,363 bytes | generated from the 703-book CSV |
| `REAL_BOOK_COVERS.sql` | ~76 KB | cover-image assignments for all 703 books |
| `config/config.local.example.php` | — | template for the live config |

The current app ZIP contains **115 entries** (81 PHP files), no `htdocs/` prefix, no cover images, no secrets, and migrations 0001–0006 under `_private/database/migrations/`.

**v52 changes:** the loading overlay is removed entirely; the favicon switches between a dark-green icon (light browser chrome) and a light-cream icon (dark chrome); catalogue covers are enlarged; selecting a category in the catalogue filter refreshes the list automatically; registration, profile and admin user forms now use an MMUST school dropdown; registration numbers are validated against the MMUST format `XXX/Y/01-00000/YYYY` (uppercase letters only, e.g. `SIT/B/01-02392/2024`) with a format hint on the sign-up and admin user forms; and `uploads/covers/.htaccess` is made InfinityFree-safe to fix the HTTP 500 on cover images.

**v53 changes:** the catalogue is now a tappable card list on phones (no sideways scrolling — verified at a Samsung S21 360×800 viewport); the book detail page was redesigned with a much larger cover, breadcrumb, category/ISBN/publisher chips, a prominent request button, compact availability stats, a borrowing guide and related titles. The request form also now includes the required CSRF token it was missing.

**v54 change:** the registration-number placeholder now reads `e.g. SIT/B/01-02392/2024` so it cannot be mistaken for an already-filled value.

**v55 change:** the "My borrow requests" page now uses mobile cards on phones, removing a 39px horizontal overflow found during a full QA sweep.

**Existing legacy registration numbers** (e.g. `STU-2026-001`) still display and work, but an admin must update them to the MMUST format before they can save that user again.

## 2. Before you start — back up

1. InfinityFree control panel → **phpMyAdmin** → select `if0_XXXXXX_campus_library` → **Export** → save the SQL dump.
2. File Manager → download the existing `htdocs/_private/config/config.local.php` to your computer.
3. Keep both somewhere safe. Do not skip this.

## 3. Database (phpMyAdmin)

1. Open phpMyAdmin and select the production database.
2. Go to the **SQL** tab.
3. Apply pending migrations **in order**:
   - `_private/database/migrations/0004_email_verification.sql`
   - `0005_password_resets.sql`
   - `0006_mfa.sql`
   (0001–0003 are already applied on the live host. If unsure, check the `schema_migrations` table first.)
4. Import the catalogue:
   - SQL tab → **Choose File** → `REAL_BOOKS_IMPORT.sql` → **Go**. It is idempotent (re-running adds nothing).
   - Then import `REAL_BOOK_COVERS.sql` the same way.

## 4. Upload the application

1. InfinityFree File Manager → open the existing **`htdocs/`** document root.
2. Upload `campus-library-infinityfree-v55.zip` there.
3. **Extract it directly inside `htdocs/`** — not in the account root. This is the requirement that fixes the old "extract path must be within an htdocs folder" error.
4. Overwrite existing files when prompted. Keep hidden `.htaccess` files — make sure File Manager shows hidden files.
5. Confirm the resulting layout:
   ```text
   htdocs/index.php
   htdocs/.htaccess
   htdocs/assets/...        (css, js, img, favicon)
   htdocs/_private/config/config.php
   htdocs/_private/includes/init.php
   htdocs/_private/database/migrations/...
   htdocs/_private/storage/logs/.htaccess
   ```

## 5. Upload the book covers

1. In File Manager, open `htdocs/uploads/covers/`.
2. Upload `BOOK_COVERS.zip` there and extract it in place.
3. Verify a few images appear, e.g. `htdocs/uploads/covers/9780262046305.jpg`.
4. **Replace** the existing `.htaccess` in that folder with the one from the v52 package (`uploads/covers/.htaccess`). The old one contains a `Header set` directive and `php_flag` blocks that InfinityFree rejects, which is what causes every cover image to return **HTTP 500**. Overwrite it when extracting the package; if you upload covers separately, copy this file explicitly. It still blocks scripts and directory listings safely (using `mod_headers` only when available).

## 6. Update the live config

Edit `htdocs/_private/config/config.local.php` (the existing file you backed up) and add these keys:

```php
'EMAIL_VERIFICATION_ENABLED' => 'false',
'EMAIL_FROM_ADDRESS'        => '',
'PASSWORD_RESET_ENABLED'    => 'false',
'MFA_ENABLED'               => 'false',
```

Leave all four as shown until email delivery is confirmed. Keep every existing value (DB host/name/user/password, `APP_PUBLIC_PATH`, `APP_TRUST_PROXY_HEADERS`, `SESSION_COOKIE_SECURE`, `HSTS_MAX_AGE`, `SETUP_TOKEN`) exactly as it is.

To enable features later, in this order:
1. Put a real sender address in `EMAIL_FROM_ADDRESS` and confirm `mail()` delivers.
2. Set `EMAIL_VERIFICATION_ENABLED` to `'true'`.
3. Set `PASSWORD_RESET_ENABLED` to `'true'`.
4. Optionally set `MFA_ENABLED` to `'true'` (users then opt in from their profile).

## 7. Post-upload verification

- `/index.php`, `/login.php`, `/register.php` and `/assets/css/style.css?v=8` load with the new design.
- There is no loading overlay at all — the page appears immediately.
- The favicon is dark green on a light Chrome theme and light cream on a dark Chrome theme.
- Catalogue shows large covers (92×124 px) and compact pagination; cover images load instead of showing a 500 error.
- Choosing a category in the catalogue filter immediately refreshes the list to that category.
- Registration, profile and admin user forms show a dropdown of the 11 MMUST schools.
- Direct requests to `/_private/config/config.local.php`, `/_private/includes/init.php`, `/_private/database/migrations/0003_durable_login_throttle.sql` return 403/404 with no content.
- Sign in with your real account; check the cookie is `Secure`, `HttpOnly`, `SameSite=Lax` in browser dev tools.
- Test a search, a book detail page and a role-appropriate dashboard.
- After setup is complete, the old public "Set up the first administrator" CTA should be gone.

## 8. If something goes wrong

- Restore the DB dump from step 2 and the backed-up `config.local.php`.
- Re-upload the previous package if needed.
- Check `htdocs/_private/storage/logs/app.log` (via File Manager, not HTTP) for errors.

## 9. What I did not do

No upload, no live DB change, no config change, no commit beyond the authorised GitHub push. All verification above was local. Actual InfinityFree behaviour remains unconfirmed until you follow these steps.
