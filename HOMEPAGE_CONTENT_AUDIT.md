# Homepage Content Audit

Date: 2026-11-20. Scope: content accuracy only, ahead of a separate visual redesign phase.

## 1. How the audit was performed

- Inspected the working tree and Git state: branch `main`, HEAD `c3cae95`; no commit or push made.
- Read the project documentation: `README.md`, `TESTING.md`, `PHASE1_PLAN.md`, all `PHASE2_*` through `PHASE6_4` reports, `PRODUCTION_*`, `DATABASE_*` and `DEPLOYMENT_INFINITYFREE.md`.
- **Verified the actual PHP implementation in `public/` and `includes/`** rather than relying on documentation. Every feature area below was traced to concrete files and functions.
- Compared `public/index.php` with the copy inside `campus-library-infinityfree-v2.zip`. The only difference is the Phase 6.3 include-path rewiring (`/../includes/` → `/_private/includes/`); all user-facing content is identical.
- Requested the live site `https://mmust-library.site.je/` read-only. It returned a JavaScript cookie challenge ("This site requires Javascript to work"), so its rendered HTML could not be retrieved. This audit therefore assesses the packaged/deployed **source**, not a live HTML snapshot. No live-host changes were made.

## 2. Verdict

The homepage was **materially inaccurate**. It described the system as a Phase 2 account-management foundation with catalogue work still in the future, while the codebase has since implemented and locally tested catalogue management, circulation, fines, reporting and administrative oversight (Phases 3–5.2). It also understated what students and staff can actually do.

## 3. Specific discrepancies found

| # | Old homepage text (`public/index.php`) | Why inaccurate | Evidence |
|---|---|---|---|
| 1 | "Campus Library Management" eyebrow and "Borrowing, catalogue and accounts in one calm place." | Vague, development-flavoured framing; does not identify the system or its audience. | `public/index.php:30-31` (before edit) |
| 2 | "Phase 2 establishes secure accounts, role-based access and administrator user management." | Explicit phase terminology. Phase 2 was only the foundation; Phases 3–5 added the operational features. | Old `index.php:33-35`; `PHASE3_IMPLEMENTATION_REPORT.md:1-3`, `PHASE5_IMPLEMENTATION_REPORT.md:1-3` |
| 3 | "Catalogue and circulation foundations prepared for Phase 3" | Directly false. Catalogue/circulation were implemented in Phase 3. | `PHASE3_IMPLEMENTATION_REPORT.md:1-3,57-116`; `staff/catalogue.php`, `staff/book-form.php`, `staff/request-action.php`, `staff/issue-action.php`, `staff/returns.php`, `includes/circulation.php` |
| 4 | "What is available now" card listed only sign-in, registration, admin user management, auditing and "foundations" | Omits implemented catalogue, requests, issuing, returns, overdue fines, reports/CSV and settings. | `includes/catalogue.php`, `includes/circulation.php`, `includes/fines.php`, `includes/reporting.php`, `public/staff/reports/*`, `public/admin/settings.php` |
| 5 | Student card: "review your borrowing activity from one dashboard" | Understated. Students can also search the catalogue, request/cancel titles, view history and review fines. | `public/catalogue.php`, `catalogue/{detail,request,cancel,history}.php`, `student/{dashboard,fines}.php` |
| 6 | Staff card: "Staff dashboards summarise the catalogue, pending requests and active loans" | Understated. Librarians perform the full CRUD and workflow actions. | `staff/{categories,category-form,category-action,catalogue,book-form,book-action,copy-form,copy-action,requests,request-details,request-action,issue,issue-action,returns,return-action,fines,fine-details,fine-action}.php` |
| 7 | Administrator card: only "manage accounts, roles and access" | Omits activity log, loan/fine settings and system-wide reports. | `public/admin/{users,user-form,user-action,activity-log,settings,settings-action,reports}.php`, `includes/reporting.php:420-468` |
| 8 | Section label "System foundations" | Historical phase language. | Old `index.php:74` |
| 9 | "Sign in to your account" / "Create a student account" | Acceptable but generic; "Create a student account" clashed with the card CTA "Register as a student". | Old `index.php:39-40,82` |
| 10 | Meta description "Campus Library Management System" (`includes/header.php:30`) and footer "Built with native PHP, PDO and MySQL." (`includes/footer.php:12`) | Generic; did not describe the platform or its audience. | Source files as cited |

## 4. What is genuinely implemented (verified in code)

- **Authentication & accounts:** student self-registration, e-mail/password sign-in, durable DB-backed login throttle (5 failures / 15 min), password change with session revocation, secure cookies and idle timeout (`includes/auth.php`, `includes/init.php`, `public/register.php`, `public/login.php`, `public/account/profile.php`).
- **Access control:** role guards on every protected page; separate student, librarian and administrator areas (`includes/authorization.php`, guards at the top of all protected pages).
- **Catalogue:** categories, books and physical copies with create/edit/delete and validation (`includes/catalogue.php`, `staff/catalogue.php`, `staff/book-form.php`, `staff/copy-form.php` and action endpoints).
- **Circulation:** student browse/request/cancel/history; librarian approve/reject, issue, return, overdue auto-fine (`includes/circulation.php`, `public/catalogue/*`, `public/staff/request*.php`, `issue*.php`, `return*.php`).
- **Fines:** overdue fine assessment on return, settlement with audit, student fine history, librarian fine management (`includes/fines.php`, `staff/fines.php`, `staff/fine-action.php`, `student/fines.php`).
- **Reports:** librarian borrowing/overdue/catalogue/fines reports with CSV export, admin system reports, activity log and settings (`includes/reporting.php`, `public/staff/reports/*`, `public/admin/reports.php`, `admin/activity-log.php`, `admin/settings.php`).
- **Security:** CSRF on state-changing POSTs, prepared statements, audit logging, last-active-administrator protection, protected one-time setup token, deny-all `.htaccess` on private directories.

## 5. What is NOT implemented (must not be advertised)

Confirmed absent from both code and documentation:
- E-mail / SMS notifications, e-mail verification, password reset by e-mail.
- Online payments / M-Pesa; fine waivers, partial payments and refunds.
- Cover-image uploads (validator and hardened directory exist, but no public upload endpoint).
- Renewals, reservations/queues, lost-copy billing, multi-branch transfers.
- Public (logged-out) catalogue browsing — `public/catalogue.php:7` requires a student role.
- Public API, mobile apps, barcode/RFID, charts, "most borrowed"/monthly-trend reports, print view.

## 6. Navigation and link verification

- All homepage targets resolve to real files: `login.php`, `register.php`, `setup-admin.php`, `index.php`, `assets/css/style.css`, `assets/css/print.css` (verified with local HTTP 200 checks and a read-only filesystem check).
- First-administrator setup is **not** a standing public CTA. `public/index.php:20-22,41-43,104-108` shows it only when `SETUP_TOKEN !== ''`, no admin exists and `setup_completed_at` is empty; `setup-admin.php` also re-checks the lock and returns 404/403 as appropriate. This protection was preserved unchanged.
- With an administrator present (local dev state), the homepage correctly shows "Administrator sign in" instead of a setup link.

## 7. Deployment status note

Repository documentation consistently records local-only verification and does not mention the live domain. No claim of live deployment or live verification is made in the updated homepage. The live domain was provided in the task but could not be inspected because of its JavaScript challenge.
