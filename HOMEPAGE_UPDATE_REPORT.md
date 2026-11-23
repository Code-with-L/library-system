# Homepage Update Report

Date: 2026-11-20. Phase: public homepage content correction (content only — not the visual redesign).

## 1. Summary

The public landing page was rewritten to describe the **implemented** Campus Library System rather than its historical development phases. No application logic, schema, authorization, deployment configuration or design system was changed.

## 2. Files changed

| File | Change | Nature |
|---|---|---|
| `public/index.php` | Headline, lead, hero note, capability list, three audience cards, section labels and CTA wording; **all PHP logic unchanged**. | Content only |
| `includes/header.php` | Meta description string now states the platform's scope. | Content only |
| `includes/footer.php` | Footer technology line now names the platform. | Content only |

No other files were modified. Git HEAD remains `c3cae95`; no commit and no push were made.

## 3. What changed and why

- **Removed all phase terminology** ("Phase 2 establishes…", "prepared for Phase 3", "System foundations", "one calm place") because Phases 3–5 delivered the operational features.
- **Headline/lead** now name the system's three concerns — catalogue, student borrowing and staff operations — and state who does what.
- **Hero note** makes access explicit: students self-register and sign in to browse the catalogue and follow borrowing; staff/administrator accounts are administrator-created. This avoids implying public logged-out catalogue browsing, which is not implemented.
- **Capability list** now names real features: catalogue (categories/books/copies), registration and role dashboards, requests → approval → issuing → returns, overdue fines with automatic assessment and manual settlement, staff reports with CSV export and administrator oversight, and security controls.
- **Student / staff / administrator cards** now describe actual capabilities rather than the narrow Phase 2 view. The last-administrator protection is retained as a stated safeguard.
- **CTA wording** is consistent ("Register as a student", "Set up the first administrator"), and the **existing setup-link guard was preserved exactly** — the setup CTA appears only when `SETUP_TOKEN` is non-empty, there are zero administrators and `setup_completed_at` is empty.
- **Meta/footer text** now describe a campus library management platform built with native PHP, PDO and MySQL.

The existing markup structure and CSS classes (`hero`, `hero__card`, `hero__list`, `panel-grid`, `card span-4`, `btn btn--ghost btn--sm`, `review-banner`, `muted small`) are unchanged, so the visual design is substantially unchanged. The local-only demo-credentials banner and its `show_demo_credentials()` guard were left untouched.

## 4. Capabilities verified as implemented

Verified by reading the PHP implementation and cross-checking phase reports:
- Student registration, sign-in, durable login throttle, password change/session revocation, secure sessions.
- Role-based dashboards and guards for student, librarian and administrator areas.
- Administrator user management (create/edit/role/status/password reset), last-admin protection, activity log, loan/fine settings, system reports.
- Catalogue categories/books/copies CRUD and validation.
- Student catalogue search, detail, borrow request/cancel and borrowing history.
- Librarian request review/approval, issuing, returns, overdue indicators and fine management.
- Overdue fine assessment on return and manual settlement with audit.
- Librarian reports with CSV export (borrowing, overdue, catalogue, fines).
- Security: CSRF, prepared statements, output escaping, audit logging, protected one-time setup, deny-all private `.htaccess`.

## 5. Capabilities deliberately NOT advertised

Not implemented in this codebase: e-mail/SMS, password-reset e-mail, online payments/M-Pesa, cover-image uploads, renewals, reservations, lost-copy billing, multi-branch, public API/mobile apps, charts, most-borrowed/monthly-trend reports, public logged-out catalogue browsing. These were excluded from the homepage.

## 6. Tests performed and results

| # | Check | Result |
|---|---|---|
| 1 | PHP syntax check (`php -l`) across `includes/`, `config/`, `public/` — 70 files | **PASS** — 0 syntax errors |
| 2 | Rendered homepage over local Apache (`GET /index.php`) | **PASS** — HTTP 200 |
| 3 | Stale-phase strings absent ("Phase 2/3/4/5", "prepared for Phase", "What is available now", "System foundations", "one calm place") | **PASS** — none present |
| 4 | New accurate strings present (headline, "What the system does", three audience headings, CSV export, fines, "Who the system is for") | **PASS** |
| 5 | No live-deployment claims in page HTML | **PASS** |
| 6 | Internal links `/index.php`, `/login.php`, `/register.php`, `/assets/css/style.css`, `/assets/css/print.css` | **PASS** — all HTTP 200; all targets exist |
| 7 | First-admin setup CTA absent while an administrator exists | **PASS** |
| 8 | Meta description and footer technology text updated | **PASS** |
| 9 | `python phase21_regression.py` (auth, roles, CSRF, 403/405, link crawl) | **PHASE21_REGRESSION PASS** |
| 10 | `python phase4_smoke.py` | **PHASE4_FINE_SMOKES PASS** |
| 11 | `python phase5_reporting_tests.py` | **PHASE5_REPORTING_TESTS PASS** |
| 12 | `python phase21_upload_tests.py` | **UPLOAD_HTTP_TESTS PASS** |
| 13 | `python phase4_http_tests.py` | **PHASE4_FINE_HTTP_TESTS PASS** |
| 14 | `git diff -- public/index.php includes/header.php includes/footer.php` | Only the three intended files changed; PHP logic untouched |

## 7. Remaining issues / out of scope

- **Live site not modified or verified.** `https://mmust-library.site.je/` is behind a JavaScript cookie challenge, so its rendered HTML could not be retrieved. The change is in the local source; publishing it requires the normal packaging/upload workflow. To deploy, the Phase 6.3 include-path rewiring must be reapplied when rebuilding the ZIP (the packaged `index.php` differs from the source only in those three include paths). No ZIP was rebuilt here.
- **Stale phase wording remains on three signed-in dashboards** (`public/student/dashboard.php:160,166,205`, `public/staff/dashboard.php:95,163`, `public/admin/dashboard.php:149`) and in `public/staff/catalogue.php:19`. These are separate from the public homepage and were intentionally left unchanged to respect the "do not rewrite unrelated pages" boundary. They are candidates for the content pass before UI redesign.
- **Known minor defects elsewhere (pre-existing, not introduced here):** student fine rows show "—" for title; returned/settled fine details show 0 days; the staff fines search says "title" but does not search title; the borrowing report's `q` input is unused. None affect homepage accuracy.
- No live-host, database, schema, credential or deployment-configuration changes were made.

## 8. Completion status

The public homepage now accurately represents the implemented Campus Library System. The application remains functional, all available regression suites pass, and the existing visual design is unchanged. Ready for the separate UI/UX beautification phase.
