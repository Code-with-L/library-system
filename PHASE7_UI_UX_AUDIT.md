# Phase 7 — UI/UX Reconnaissance Audit

Date: 2026-11-20. Status: **audit complete, before implementation**. This phase is visual/interaction design only — no application rebuild.

## 1. Method

- Read `README.md`, phase closeout reports and `HOMEPAGE_UPDATE_REPORT.md`.
- Inspected all 43 public PHP pages and the shared `includes/header.php`, `footer.php`, `sidebar.php`, `alerts.php`; mapped `public/assets/css/style.css` (1,717 lines), `print.css`, `app.js`, `boot.js`.
- **Actually rendered** the homepage, sign-in, registration, and the administrator, librarian and student dashboards in headless Chromium at 1440×1000 and the homepage at 390×844. Screenshots were captured and inspected (stored outside the repository). Findings below marked "rendered" come from those captures.

## 2. Page inventory

| Area | Entry points | Shared shell |
|---|---|---|
| Public | `index.php`, `login.php`, `register.php`, `setup-admin.php`, `403/404/405/500.php` | Guest header/footer (`header.php`) |
| Student | `student/dashboard.php`, `catalogue.php`, `catalogue/{detail,request,cancel,history}.php`, `student/fines.php`, `account/profile.php`, `logout.php` | App shell + sidebar |
| Librarian | `staff/dashboard.php`, `staff/{catalogue,categories,category-form,category-action,book-form,book-action,copy-form,copy-action}.php`, `staff/{requests,request-details,request-action,issue,issue-action,returns,return-action}.php`, `staff/{fines,fine-details,fine-action}.php`, `staff/reports/*` | App shell + sidebar |
| Administrator | `admin/dashboard.php`, `admin/{users,user-form,user-action,activity-log,settings,settings-action,reports}.php` plus all librarian routes | App shell + sidebar (extended nav) |

Reusable components found: brand mark, sidebar nav, topbar, user chip, cards, stat cards, `panel-grid`/`span-*`, buttons, badges, forms, tables, filters, pagination (two different systems), alerts, auth card, error page, empty state.

## 3. Findings

### 3.1 Visual identity
- The design is a dated cream/beige academic template: Georgia serif headings, Segoe UI body, gold bullets/accent, thick soft shadows on cards. It reads as a basic PHP project, not institutional software.
- No icon system: only the brand book SVG exists; navigation and actions are plain text.
- Gold on cream is used for emphasis but has weak contrast for small text.

### 3.2 Homepage (rendered)
- Headline uses `clamp(2.1rem, 4.4vw, 3.5rem)` and wraps to **four lines** on desktop, wasting prime viewport space; mobile likewise. Brief explicitly calls for restrained heading scale.
- ~150 px of empty space between header and hero; the hero card has a decorative offset outline that adds noise without meaning.
- The capability list is a plain bulleted list; the three audience cards have uneven text lengths and large empty space. The section communicates the right facts but without hierarchy or rhythm.
- Guest header is only a brand and two small buttons; no sense of product identity or institutional context.

### 3.3 Authentication screens (rendered)
- Sign-in copy still says "in later phases, request titles and track your borrowing" — stale and inaccurate.
- Layout leaves a large empty area below the auth card; the card carries a heavy shadow.
- The demo-credentials box is correct for local development but visually dominates the left column.

### 3.4 Authenticated shell (rendered)
- Sidebar: dark green, **eleven flat items** for administrators with no grouping or icons; footer still reads **"Phase 2 · Foundation"**.
- Topbar is thin; the page title repeats inside the content on several pages (e.g. staff Catalogue shows "Catalogue" twice).
- Stat grid is `auto-fit minmax(170px, 1fr)`; with eight metrics it renders **six cards on row one and two on row two** — visibly unbalanced on all three dashboards.
- The "Welcome back" flash callout sits above every dashboard heading and pushes content down.
- Tables are dense; some pages (e.g. `staff/catalogue.php`) render a bare table with no card wrapper while others use `.card`, so table chrome is inconsistent.
- Two pagination systems coexist (`.pagination` and `.pagination-simple`).

### 3.5 Rendered defects and stale copy
- **Staff dashboard "Operational reports" buttons are visibly crushed** — text wraps into narrow 30 px buttons. This is a genuine layout defect, not a style preference.
- Stale phase wording still present: `student/dashboard.php` ("Requesting titles opens in Phase 3", "Issued and returned books will be listed here"), `staff/dashboard.php` ("Request review and issuing arrive in Phase 3", "Phase 3 will add catalogue management…"), `admin/dashboard.php` ("Verified during Phase 2 development"), `login.php` ("in later phases"), `includes/sidebar.php` ("Phase 2 · Foundation"), `staff/catalogue.php` ("Search or browse titles" with no search control).
- Environment card on the admin dashboard exposes raw runtime versions; acceptable as diagnostics but visually competing with primary content.

### 3.6 Responsive (rendered at 390 px)
- Sidebar off-canvas behaviour works, but the guest header wraps so both buttons sit on a second line aligned left.
- The homepage capability card becomes very tall; the three audience cards stack correctly.
- Dashboard stat cards stack one per row; tables rely on horizontal scrolling (acceptable but needs clearer affordance).

### 3.7 Interaction and accessibility
- `app.js` already provides mobile nav toggle (with Escape and outside-click close), alert dismissal, destructive-action confirmation and password reveal — keep and enhance rather than replace.
- Focus-visible outline and skip link exist. Alerts use `aria-live`. Contrast of muted 0.7–0.78rem uppercase labels is marginal on cream.
- No loading states, no disabled-state styling for async actions, no visible feedback beyond flash alerts; empty states are plain text.

## 4. Design decisions for implementation

1. **Refine, don't replace, the palette**: keep institutional deep green as the primary and warm neutral paper, but shift to a cleaner near-white surface, stronger ink, cooler neutrals, and restrained semantic colours. Gold becomes a rare accent, never a primary action.
2. **Typographic hierarchy without serif headings**: one system sans-serif stack for display and body; editorial quality from weight, size, line-height and letter-spacing. Headline scale capped so it never exceeds three lines.
3. **Eight-point spacing scale and strict alignment**; consistent 1,200/1,280 px content widths; 6–10 px radii only.
4. **Sidebar grouped by task** with small monochrome SVG icons and a role label; remove phase branding. Preserve every route and guard exactly.
5. **Fix dashboard metrics layout** with an explicit 4/2/1 column grid, and repair the crushed report buttons.
6. **Consistent surfaces**: every table, filter bar and pagination control gets one treatment; drop the duplicate pagination style.
7. **Content cleanup**: remove all remaining phase wording from dashboards, login and sidebar; keep factual capability statements.
8. **Progressive enhancement only**: extend the existing vanilla JS with reduced-motion support and small usability touches; no dependencies, no build step.
9. **Preserve everything functional**: no schema, auth, authz, business-logic, route, form-action or parameter changes.

## 5. Out of scope

No framework migration, no static mockups, no new pages, no external font/CDN dependency, no deployment or live-host changes, and no removal of existing features.
