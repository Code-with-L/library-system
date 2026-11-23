# Phase 7 — Test Results Record

Date: 2026-11-21. Read-only audit. All evidence below was produced in this phase; no application files were changed.

## 1. Test environments

| Environment | Details | Used for |
|---|---|---|
| **Local (isolated test DB)** | Docker `web` PHP 8.2 / `db` MariaDB 10.11, seeded development database at `http://localhost:8090`. Fixture-based suites create and remove their own test records. | Functional, workflow, auth, authorization, CSRF, search tests |
| **Production (live)** | `https://mmust-library.site.je`, accessed read-only via a real browser after passing its JavaScript cookie challenge. | Guest pages, assets, error pages, private-path denial, response headers, setup-state observation |
| **Static** | Source, schema, `.htaccess`, Git tracked files/history, deployment/package reports | Code-level evidence, secret/history checks |

**Production restriction observed:** no login, registration, form submission, account creation, fine trigger, credential change, or data deletion on the live site. Nothing was deployed.

## 2. Existing test suite (executed, local)

| Command | Result |
|---|---|
| `python phase21_regression.py` | **PHASE21_REGRESSION PASS** |
| `python phase4_smoke.py` | **PHASE4_FINE_SMOKES PASS** |
| `python phase5_reporting_tests.py` | **PHASE5_REPORTING_TESTS PASS** |
| `python phase21_upload_tests.py` | **UPLOAD_HTTP_TESTS PASS** |
| `python phase4_http_tests.py` | **PHASE4_FINE_HTTP_TESTS PASS** (request → approve → issue → return → fine → settle) |
| `php .dev/duplicate_return_e2e.php` | **PHASE5_DUPLICATE_RETURN_E2E PASS** |
| `php .dev/phase6_verify.php` | **PHASE6_VERIFY PASS** |
| `php .dev/phase4_calc_matrix.php` | **PHASE4_CALC_MATRIX PASS** (8/8 fine cases) |
| `php .dev/phase4_fines_engine_test.php` | **PHASE4_FINES_ENGINE_TESTS PASS** |
| `php .dev/circulation-test.php basic` | **PASS** |
| `php .dev/circulation-test.php invalid_prevention` | **PASS** |
| `php .dev/circulation-test.php rollback` | **PASS** |
| `php .dev/circulation-test.php audit` | **PASS** (`violations: []`) |
| `php .dev/circulation-test.php concurrent` | **PASS** |
| `php .dev/admin-guard-test.php setup/single/race1/race2/cleanup` | **5 × PASS** |
| `php .dev/security-headers-test.php` | **PASS** (exit 0) |
| `php .dev/uploads-test.php` | **PASS** (exit 0) |

All suites passed against their own fixtures on the local test database. These results do **not** prove live behaviour.

## 3. Additional catalogue tests (local, read-only GETs)

Procedure: student session via `/login.php`, then GET `/catalogue.php` with each parameter set; titles extracted from rendered result links. Baseline: 13 active test books.

| Test | Input | Result |
|---|---|---|
| Empty search | no `q` | **PASS** — 13 titles |
| Complete title | `q=Introduction to Algorithms` | **PASS** — 1 result |
| Partial title | `q=Algorithms` | **PASS** — 1 result |
| Author | `q=Cormen` | **PASS** — 1 result |
| ISBN complete | `q=9780262046305` | **PASS** — 1 result |
| ISBN partial | `q=9780262` | **PASS** — 1 result |
| Case-insensitive lower/upper | `q=algorithms`, `q=ALGORITHMS` | **PASS** — 1 result each |
| No results | `q=this-title-does-not-exist-xyz` | **PASS** — "No books found" |
| Wildcard input | `q=%` | **OBSERVED DEFECT** — all 13 titles matched (unescaped LIKE, `includes/catalogue.php:50,84`) |
| Category filter | `category=3` | **PASS** — 2 titles |
| Pagination page 1 | `page=1` | **PASS** — 13 titles |
| Out-of-range page | `page=999` | **FAIL** — "No books found" instead of clamped last page (`public/catalogue.php:21` before `:24`) |
| Book detail | `id=1` | **PASS** — 200, availability shown |
| Invalid book id | `id=999999` | **PASS** — 404 |

**Production catalogue search: NOT TESTED** (no student credentials; catalogue reportedly empty).

## 4. Authorization and CSRF probes (local)

| Test | Result |
|---|---|
| 16 protected URLs fetched unauthenticated | **PASS** — all 302/403 |
| Admin+staff URLs as student | **PASS** — all 403 |
| Admin URLs as librarian | **PASS** — all 403 |
| Student URLs as librarian/admin | **PASS** — all 403 |
| POST without CSRF to `/catalogue/request.php`, `/account/profile.php`, `/staff/request-action.php`, `/admin/settings-action.php` | **PASS** — all 403, no state change |
| Profile shows only own email | **PASS** |

`AUTHZ_TESTS PASS` — 0 failures.

## 5. Live production checks (read-only)

| Check | Observed | Label |
|---|---|---|
| Homepage, login, register, error pages | HTTP 200 / intended status codes; no SQL, filesystem paths or credentials in output | PASS |
| Private URLs (5 paths under `/_private/`) | **404**, no config content | PASS (denied) |
| `/uploads/covers/.htaccess` | **403** | PASS |
| Security headers on PHP pages | CSP, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, Referrer-Policy, Permissions-Policy present; HSTS absent (expected) | PASS |
| `.htaccess` direct request | 403 | PASS |
| Live Phase 7 stylesheet served | present (design-system tokens confirmed in response) | PASS |
| Demo credential banner | absent on live (host/environment gated) | PASS |
| `/setup-admin.php` GET | **403**, "setup already completed" | PASS (locked) |
| Homepage setup CTA | present twice despite locked setup endpoint — inconsistent state, likely stale cache | **LOW FINDING** (S-7) |
| Live session cookie flags | **NOT TESTED** — no login performed; Fetch API hides `Set-Cookie` and browser capture showed none on anonymous page | NOT TESTED |
| Live catalogue search / borrowing / fines | **NOT TESTED** — no production credentials and no permission to create records or trigger transactions | NOT TESTED |

## 6. Static evidence checks

| Check | Result |
|---|---|
| `git ls-files` and `git log --all` for `.env`, `.dev/`, `config.local.php` | **PASS** — never tracked; no credential values found in config history |
| Secrets scanning of source/ZIP reports | **PASS** — no live DB identifiers or real passwords; only template placeholder |
| Password column definition | `password_hash VARCHAR(255) NOT NULL` (`database.sql:62`) |
| Email/SMTP implementation | none; no SMTP credentials anywhere |
| Upload endpoint | none (validator + hardened directory only) |

## 7. Label summary

- **PASS:** password hashing/verification, email validation, session controls, logout, login throttle, server-side auth, CSRF, prepared statements, output escaping, config protection (live-denied), private paths (live-denied), role separation, no committed secrets, no SMTP, local catalogue search (except noted defects), local borrowing happy path, local fine calculation, local admin-user protections.
- **FAIL:** out-of-range catalogue pagination; borrowing-limit bypass at issuance; approve/issue conflict handling (500s); staff fine "days overdue" display.
- **PARTIAL:** concurrent duplicate requests (500 instead of friendly error); active-overdue fine assessment and lost-copy workflows (service-only, no UI); copy-status guard bypass; live cookie flags and live authenticated functionality not exercised.
- **NOT TESTED:** all authenticated production functionality; live catalogue search (empty catalogue); live registration/duplicate handling; live throttle effectiveness; runtime rehash.

## 8. Important caveat

A passing local test suite is evidence about the **code**, not about the live deployment. No claim of production verification is made for any authenticated feature. The live site's guest surface, headers, private-path denial and setup lock were directly observed; everything else is local/static evidence.
