# Campus Library Management System — Phase 1 Plan

**Status:** Phase 1 complete — planning and design only  
**Application code:** Not started  
**Stack:** PHP 8.x, MySQL 8.x, HTML5, CSS3, vanilla JavaScript, PDO, XAMPP, phpMyAdmin  
**Project folder:** `campus-library/`  
**Web root:** `campus-library/public/`

---

## 1. Executive Summary

The Campus Library Management System is a single-branch university library application with three roles:

1. **Administrator** — full system administration.
2. **Librarian** — daily library operations.
3. **Student / Member** — catalogue access and personal borrowing.

The central design decision is the separation of a **book title** from its **physical copies**:

- `books` stores bibliographic information (title, author, ISBN, category, cover).
- `book_copies` stores each physical item with its own copy code and status.
- A student requests a **title**, not a hidden copy code.
- A librarian assigns a specific available copy when approving the request.
- Transactions and database constraints prevent the same copy from being issued twice.

The system uses native PHP pages, shared include files, PDO prepared statements, server-side validation, CSRF protection, role checks and ownership checks. There is no framework, Composer dependency, JavaScript framework or payment integration.

### Default library policies

These are configurable from the administrator area:

| Policy | Default |
|---|---|
| Loan period | 14 days |
| Maximum active borrowings per student | 3 |
| Fine rate | KES 10.00 per overdue day |
| Fine calculation | Recorded and displayed only; no payment gateway |
| Currency | KES |
| Time zone | `Africa/Nairobi` (configurable) |
| Active request limit per student per title | 1 |
| Renewals | Not included in the initial scope |
| Public student registration | Immediate active account |
| Administrator/librarian registration | Provisioned by an administrator or the one-time setup page |

### Key design decisions

- **Title-level requests, copy-level circulation.** Students cannot choose or manipulate copy codes. Staff assign a real copy during approval.
- **Separate approval and issuance.** Approval reserves a copy; issuance creates the borrowing and sets the due date. This matches the required workflow and keeps each action auditable.
- **Derived overdue status.** Overdue is calculated from `due_date < CURDATE()` for active borrowings, so no stale status field can become incorrect.
- **No destructive history deletion.** Books and copies are archived, users are deactivated, and borrowing records are retained.
- **Database-level guards plus transaction-level guards.** Unique constraints catch duplicates, while `SELECT ... FOR UPDATE` transactions stop two staff members from issuing the same copy concurrently.
- **Simple structure over framework abstraction.** Each page states its purpose, its authorization rule, its input handling, its database operation and its redirect target.

---

## 2. Scope

### In scope

- Student self-registration, login and logout.
- Secure one-time administrator setup.
- Administrator creation of librarian, student and additional administrator accounts.
- Role-based dashboards.
- Category management.
- Book catalogue with cover images.
- Individual physical-copy management.
- Catalogue search, filters, sorting and pagination.
- Student borrowing requests.
- Librarian approval, rejection, cancellation and issuance.
- Return processing.
- Overdue detection and configurable fine recording.
- Student borrowing history.
- Administrator and librarian reports.
- Activity logging.
- Configurable loan period, borrowing limit and fine rate.
- Responsive, printable interface.

### Out of scope

These are intentionally excluded to keep the project focused and defensible:

- Payment gateways, including M-Pesa.
- Email verification, forgotten-password email flows and SMS notifications.
- Barcode scanners and RFID hardware.
- Multi-branch transfers.
- Reservation queues for unavailable titles.
- Book renewals.
- Lost-book replacement billing.
- Public API access.
- Mobile applications.
- Third-party authentication.
- Composer packages or JavaScript frameworks.

### Assumptions

- The library operates from one campus and one circulation desk.
- The application is demonstrated locally under XAMPP.
- Dates use the configured time zone and are stored as `DATE` values.
- A student may have only one pending or approved request for the same book title.
- A student may not have two active borrowings of the same title.
- A fine is recorded when an overdue book is returned. Active overdue records show an **estimated** fine.
- Administrator and librarian accounts are not created through public registration.
- The initial administrator is created through a one-time setup page protected by a setup token and disabled automatically once an administrator exists.

---

## 3. Functional Requirements

### 3.1 Authentication and accounts

| ID | Requirement |
|---|---|
| AUTH-01 | A student can register with full name, email, student number, optional phone, department, password and password confirmation. |
| AUTH-02 | Email and student number must be unique. |
| AUTH-03 | Passwords are hashed with `password_hash()` and verified with `password_verify()`. |
| AUTH-04 | Login accepts email and password and returns a generic error for invalid credentials. |
| AUTH-05 | Inactive accounts cannot log in. |
| AUTH-06 | Successful login regenerates the session ID and CSRF token. |
| AUTH-07 | Logout is a POST action that verifies CSRF, destroys the session and expires the cookie. |
| AUTH-08 | Every authenticated user can update permitted profile fields and change their own password. |
| AUTH-09 | The one-time administrator setup refuses to run if any administrator already exists or if the setup token is wrong. |

### 3.2 Catalogue and book management

| ID | Requirement |
|---|---|
| BOOK-01 | Public visitors can browse and search the catalogue without logging in. |
| BOOK-02 | Catalogue search supports title, author and ISBN. |
| BOOK-03 | Catalogue filters support category and availability. |
| BOOK-04 | Catalogue results support sorting and pagination through GET parameters. |
| BOOK-05 | A public book detail page shows metadata, availability count and the student request option when appropriate. |
| BOOK-06 | Administrators and librarians can create and edit books. |
| BOOK-07 | Books have ISBN, title, author, publisher, year, category, description and optional cover image. |
| BOOK-08 | Administrators and librarians can add and edit physical copies with unique copy codes. |
| BOOK-09 | Books and copies are archived rather than hard-deleted once history exists. |
| BOOK-10 | A book cannot be archived while it has active borrowings, reserved copies or pending/approved requests. |
| BOOK-11 | Cover uploads are restricted by MIME type, extension, image validity and file size. |

### 3.3 Borrowing workflow

| ID | Requirement |
|---|---|
| BOR-01 | An active student can request an active book that has at least one available copy. |
| BOR-02 | A student cannot request a title they already have a pending/approved request for. |
| BOR-03 | A student cannot request a title they currently have on loan. |
| BOR-04 | A student cannot request a book when their active borrowing count has reached the configured limit. |
| BOR-05 | A pending request can be cancelled by its student owner. |
| BOR-06 | A librarian or administrator can approve or reject a pending request. |
| BOR-07 | Approval assigns and reserves one available copy inside a transaction. |
| BOR-08 | Rejection records the reviewer, time and optional note. |
| BOR-09 | A staff member can cancel an approved but not-yet-issued request, releasing the reserved copy. |
| BOR-10 | Issuance is allowed only for an approved request with a reserved copy. |
| BOR-11 | Issuance creates a borrowing, sets issue date and due date, marks the copy issued and marks the request fulfilled. |
| BOR-12 | The same copy cannot have two active borrowings. |
| BOR-13 | The borrowing limit is checked again at issuance. |

### 3.4 Returns, overdue records and fines

| ID | Requirement |
|---|---|
| RET-01 | Staff can return only an active borrowing. |
| RET-02 | Return processing sets the return date, marks the borrowing returned and makes the copy available again. |
| RET-03 | A returned overdue borrowing records days late, the fine rate applied and the amount. |
| RET-04 | Active overdue records are identified through `due_date`; estimated fines use the current configured rate. |
| RET-05 | Historical borrowing and fine records are retained. |
| RET-06 | The borrowing period, borrowing limit and fine rate are configurable by administrators. |

### 3.5 Dashboards, reports and logs

| ID | Requirement |
|---|---|
| DASH-01 | Administrator dashboard shows users, titles, copies, active borrowings, pending requests, overdue borrowings and recent activity. |
| DASH-02 | Librarian dashboard shows pending requests, issued books, returns due today, overdue books and recent circulation activity. |
| DASH-03 | Student dashboard shows current borrowings, pending requests, upcoming due dates, overdue alerts and recent history. |
| REP-01 | Reports include most-borrowed books, borrowing statistics, overdue records, available versus borrowed copies, student history and monthly trends. |
| REP-02 | Reports accept validated date filters and support printing. |
| LOG-01 | Security-sensitive and administrative actions are recorded in `activity_logs`. |
| LOG-02 | Only administrators can view the activity log. |

---

## 4. Role Permission Matrix

Legend: **Yes** = allowed; **Own** = own records only; **No** = denied.

| Capability | Admin | Librarian | Student | Public |
|---|---:|---:|---:|---:|
| Register a student account | No | No | No | Yes |
| Log in / log out | Yes | Yes | Yes | No |
| Browse catalogue and view book details | Yes | Yes | Yes | Yes |
| Submit a borrowing request | No | No | Yes | No |
| View own borrowing records | Yes | Yes | Own | No |
| View all borrowing records | Yes | Yes | No | No |
| Cancel own pending request | No | No | Own | No |
| Approve / reject requests | Yes | Yes | No | No |
| Issue books | Yes | Yes | No | No |
| Process returns | Yes | Yes | No | No |
| View overdue records | Yes | Yes | Own | No |
| Create / edit books | Yes | Yes | No | No |
| Archive books | Yes | Yes | No | No |
| Add / edit physical copies | Yes | Yes | No | No |
| Manage categories | Yes | No | No | No |
| View operational reports | Yes | Yes | No | No |
| Manage user accounts and roles | Yes | No | No | No |
| Configure borrowing policies | Yes | No | No | No |
| View activity logs | Yes | No | No | No |
| Update own profile / password | Yes | Yes | Yes | No |
| Create another administrator | Yes | No | No | No |
| Change own role or privileges | No | No | No | No |

### Authorization rules

- Authentication answers “who is this user?” Authorization answers “may this user perform this action?”
- Every restricted page starts with a role guard, not merely a hidden navigation link.
- Student personal pages read the student ID from `$_SESSION`, never from a URL parameter.
- Staff reports may accept a student ID because staff are authorized to view circulation history, but the role check happens first.
- A librarian cannot access `/admin/`, create administrators or modify roles.
- An administrator cannot deactivate or demote their own account, and the last active administrator cannot be removed or demoted.
- Inactive users are rejected on every authenticated request, not only at login.
- State-changing operations use POST and a valid CSRF token.

---

## 5. Application Workflows

### 5.1 Registration and login

1. Visitor opens `register.php`.
2. Server validates all fields, normalizes email and student number, checks uniqueness and hashes the password.
3. A `users` row is created with role `student` and status `active`.
4. Visitor logs in through `login.php`.
5. Server verifies the password and confirms the account is active.
6. The session ID and CSRF token are regenerated.
7. The user is redirected to the dashboard for their role.

A failed login always returns the same message: “Invalid email or password.” This avoids revealing whether an email address exists.

### 5.2 Borrowing request lifecycle

**Request statuses:** `pending`, `approved`, `rejected`, `cancelled`, `fulfilled`

**Copy statuses:** `available`, `reserved`, `issued`, `maintenance`, `archived`

| Step | Actor | Action | Validation | Request status | Copy status |
|---|---|---|---|---|---|
| 1 | Student | Choose a title | Student active; title active; available copy exists; no duplicate active request; no active loan for the title; borrowing limit not reached | `pending` | unchanged |
| 2 | Librarian/Admin | Approve | Request is `pending`; title active; an available copy can be locked | `approved` | `available` → `reserved` |
| 2a | Librarian/Admin | Reject | Request is `pending` | `rejected` | unchanged |
| 2b | Student | Cancel pending request | Request belongs to the student and is `pending` | `cancelled` | unchanged |
| 2c | Librarian/Admin | Cancel approved request | Request is `approved`; copy is still reserved and not issued | `cancelled` | `reserved` → `available` |
| 3 | Librarian/Admin | Issue book | Request is `approved`; reserved copy still belongs to the request; borrowing limit rechecked | `fulfilled` | `reserved` → `issued` |
| 4 | Librarian/Admin | Return book | Borrowing is `active` | unchanged | `issued` → `available` |

The request table stores a generated `active_request_key` and a unique key on `(user_id, book_id, active_request_key)`. This prevents duplicate pending or approved requests for the same student and title even if two browser tabs submit at once.

The request table also stores a generated `reserved_copy_id` that is unique when the request is approved. This prevents one physical copy from being reserved by two different approved requests.

### 5.3 Issuance and return

**Issue transaction**

1. `BEGIN`.
2. Lock the request row with `SELECT ... FOR UPDATE`.
3. Confirm the request is `approved` and still references a reserved copy.
4. Lock the copy row and confirm it is `reserved`.
5. Re-check the student’s active borrowing count against the current limit.
6. Insert the borrowing with `issue_date = CURDATE()` and `due_date = issue_date + loan_period_days`.
7. Set the copy to `issued`.
8. Set the request to `fulfilled`.
9. Insert an activity log.
10. `COMMIT`; on any failure, `ROLLBACK` and show a safe error.

**Return transaction**

1. `BEGIN`.
2. Lock the borrowing row.
3. Confirm the borrowing is still `active`.
4. Set `return_date`, `status = 'returned'` and the returning staff member.
5. Set the copy to `available`.
6. Calculate `days_overdue = max(0, DATEDIFF(return_date, due_date))`.
7. If days are overdue and the configured fine rate is greater than zero, insert a `fines` row with the rate snapshot and amount.
8. Insert an activity log.
9. `COMMIT`.

Overdue status is never stored on the borrowing. It is derived:

```sql
status = 'active' AND due_date < CURDATE()
```

### 5.4 Book and copy lifecycle

- New book → `active`; new copy → `available`.
- Copy can move to `maintenance` only when it is not reserved or issued.
- Copy can be archived only when it has no active reservation or borrowing.
- A book can be archived only when it has no pending/approved requests, reserved copies or active borrowings.
- Historical requests, borrowings and fines remain attached to archived books and copies.
- No user-facing hard delete is provided for books, copies, requests, borrowings or fines.

### 5.5 User lifecycle

- Student self-registration creates an active student.
- Administrators create librarian, student and additional administrator accounts.
- Administrators may edit names, contact details, role and status, and may set a new password.
- Accounts are deactivated, not deleted, so history remains intact.
- Deactivation blocks login and new requests but does not erase outstanding loans.
- A deactivated user with an open session is rejected by the per-request account-status check.

---

## 6. Database Design

### 6.1 Design principles

- MySQL 8.x, InnoDB, `utf8mb4`.
- Every table has a primary key.
- Foreign keys enforce referential integrity.
- Unique constraints protect natural keys such as email, ISBN and copy code.
- Enum columns constrain lifecycle states.
- `created_at` and `updated_at` timestamps are included where records change.
- Historical records are protected by `RESTRICT` rather than cascading deletes.
- `SET NULL` is used only for optional staff references so deleting a staff account would not destroy history. In practice staff accounts are deactivated, not deleted.
- Application transactions and row locks handle multi-step circulation operations.
- Generated columns provide database-level duplicate protection without extra tables.

### 6.2 Relationship overview

```text
roles 1 ──── * users
categories 1 ──── * books
books 1 ──── * book_copies
users 1 ──── * borrow_requests
books 1 ──── * borrow_requests
book_copies 1 ──── * borrow_requests   (historical assignment)
borrow_requests 1 ──── 0..1 borrowings
users 1 ──── * borrowings              (student)
users 1 ──── * borrowings              (issuing/returning staff)
book_copies 1 ──── * borrowings        (historical loans)
borrowings 1 ──── 0..1 fines
users 1 ──── * activity_logs
library_settings 1 ──── 1 system policy row
```

### 6.3 Table catalogue

#### `roles`

Stores the three fixed roles. A separate table avoids repeating role names and allows joins for administration screens.

| Column | Type | Notes |
|---|---|---|
| `id` | `TINYINT UNSIGNED` | Primary key |
| `name` | `VARCHAR(20)` | Unique; `admin`, `librarian`, `student` |
| `description` | `VARCHAR(255)` | Human-readable purpose |

**Relationships:** one role has many users.  
**Deletion:** `RESTRICT` if any user references the role.

#### `users`

Stores authentication and identity data for all three roles.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED` | Primary key |
| `role_id` | `TINYINT UNSIGNED` | FK → `roles.id` |
| `full_name` | `VARCHAR(120)` | Required |
| `email` | `VARCHAR(190)` | Unique, normalized to lowercase |
| `password_hash` | `VARCHAR(255)` | Output of `password_hash()` |
| `student_number` | `VARCHAR(40)` | Unique when present; required for students |
| `phone` | `VARCHAR(30)` | Optional |
| `department` | `VARCHAR(100)` | Optional |
| `status` | `ENUM('active','inactive')` | Default `active` |
| `last_login_at` | `DATETIME` | Updated on successful login |
| `created_by` | `INT UNSIGNED` | Optional self-reference |
| `created_at` | `TIMESTAMP` | Default current timestamp |
| `updated_at` | `TIMESTAMP` | Auto-updates |

**Relationships:** many users belong to one role; one user can create many users, requests, borrowings and logs.  
**Deletion:** accounts are deactivated, not deleted. Historical FKs use `RESTRICT` or `SET NULL`.

#### `categories`

Stores subject classifications for books.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED` | Primary key |
| `name` | `VARCHAR(80)` | Unique |
| `description` | `TEXT` | Optional |
| timestamps | | |

**Relationships:** one category has many books.  
**Deletion:** blocked if the category contains books.

#### `books`

Stores the bibliographic title, not an individual physical item.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED` | Primary key |
| `category_id` | `INT UNSIGNED` | FK → `categories.id` |
| `isbn` | `VARCHAR(20)` | Unique, normalized |
| `title` | `VARCHAR(255)` | Required |
| `author` | `VARCHAR(200)` | Required |
| `publisher` | `VARCHAR(150)` | Optional |
| `publication_year` | `SMALLINT UNSIGNED` | Optional; validated in PHP |
| `description` | `TEXT` | Optional |
| `cover_image` | `VARCHAR(255)` | Relative path or filename |
| `status` | `ENUM('active','archived')` | Default `active` |
| `created_by` | `INT UNSIGNED` | Staff member |
| timestamps | | |

**Relationships:** one book has many copies and many requests; one category has many books.  
**Deletion:** `RESTRICT`; archive instead.

#### `book_copies`

Stores one row for each physical book.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED` | Primary key |
| `book_id` | `INT UNSIGNED` | FK → `books.id` |
| `copy_code` | `VARCHAR(40)` | Unique label such as `CL-0007-02` |
| `status` | `ENUM('available','reserved','issued','maintenance','archived')` | Default `available` |
| `shelf_location` | `VARCHAR(40)` | Optional |
| `condition_note` | `VARCHAR(255)` | Optional |
| timestamps | | |

**Relationships:** many copies belong to one book; one copy can appear in many historical requests and borrowings.  
**Deletion:** `RESTRICT`; archive instead.

#### `borrow_requests`

Stores the student’s intent to borrow a title and the staff decision.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED` | Primary key |
| `user_id` | `INT UNSIGNED` | Student FK |
| `book_id` | `INT UNSIGNED` | Title FK |
| `assigned_copy_id` | `INT UNSIGNED` | Set when approved |
| `status` | `ENUM('pending','approved','rejected','cancelled','fulfilled')` | Default `pending` |
| `decision_note` | `VARCHAR(255)` | Optional staff note |
| `reviewed_by` | `INT UNSIGNED` | Staff FK |
| `reviewed_at` | `DATETIME` | Decision time |
| `active_request_key` | Generated `TINYINT` | `1` for pending/approved, otherwise `NULL` |
| `reserved_copy_id` | Generated `INT` | Copy ID while approved, otherwise `NULL` |
| timestamps | | |

**Unique constraints:**

- `(user_id, book_id, active_request_key)` — one active request per student per title.
- `(reserved_copy_id)` — one approved request per reserved copy.

**Check constraint:** an approved request must have an assigned copy.  
**Relationships:** belongs to one student, one book and optionally one copy; may become one borrowing.  
**Deletion:** `RESTRICT` once a borrowing exists; historical requests are retained.

#### `borrowings`

Stores completed circulation transactions.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED` | Primary key |
| `request_id` | `INT UNSIGNED` | Unique FK → `borrow_requests.id` |
| `user_id` | `INT UNSIGNED` | Student FK |
| `book_copy_id` | `INT UNSIGNED` | Physical copy FK |
| `issued_by` | `INT UNSIGNED` | Staff FK |
| `returned_by` | `INT UNSIGNED` | Staff FK |
| `issue_date` | `DATE` | Set at issuance |
| `due_date` | `DATE` | Issue date + loan period |
| `return_date` | `DATE` | `NULL` while active |
| `status` | `ENUM('active','returned')` | Default `active` |
| `active_copy_id` | Generated `INT` | Copy ID while active, otherwise `NULL` |
| `notes` | `VARCHAR(255)` | Optional |
| timestamps | | |

**Unique constraints:**

- `request_id` — one borrowing per request.
- `active_copy_id` — no two active borrowings for the same copy.

**Check constraints:** `due_date >= issue_date`; returned records must have a return date.  
**Relationships:** one borrowing belongs to one request, one student and one copy; it may have one fine.  
**Deletion:** `RESTRICT`; historical records are retained.

#### `fines`

Stores overdue charges calculated at return time. It is a record only; there is no payment processing.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED` | Primary key |
| `borrowing_id` | `INT UNSIGNED` | Unique FK |
| `days_overdue` | `SMALLINT UNSIGNED` | Greater than zero |
| `rate_per_day` | `DECIMAL(8,2)` | Rate snapshotted at return |
| `amount` | `DECIMAL(10,2)` | Days × rate |
| `calculated_at` | `TIMESTAMP` | Default current timestamp |

**Relationships:** one returned borrowing can have one fine.  
**Deletion:** `RESTRICT`.

#### `library_settings`

A single-row table for configurable policies. One row makes the settings easy for a student to understand and query.

| Column | Type | Notes |
|---|---|---|
| `id` | `TINYINT UNSIGNED` | Primary key; constrained to `1` |
| `library_name` | `VARCHAR(150)` | Displayed in the interface |
| `loan_period_days` | `TINYINT UNSIGNED` | Default `14`; 1–90 |
| `max_active_borrowings` | `TINYINT UNSIGNED` | Default `3`; 1–20 |
| `fine_per_day` | `DECIMAL(8,2)` | Default `10.00`; can be `0.00` |
| `currency_code` | `CHAR(3)` | Default `KES` |
| `updated_by` | `INT UNSIGNED` | Administrator FK |
| `updated_at` | `TIMESTAMP` | Auto-updates |

**Relationships:** updated by one administrator. No user can have multiple settings rows.

#### `activity_logs`

An append-only audit trail.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `user_id` | `INT UNSIGNED` | Actor; `NULL` for anonymous events |
| `action` | `VARCHAR(50)` | e.g. `login`, `approve_request`, `return_book` |
| `entity_type` | `VARCHAR(40)` | e.g. `book`, `request`, `user` |
| `entity_id` | `INT UNSIGNED` | Related record ID |
| `description` | `VARCHAR(500)` | Human-readable summary |
| `ip_address` | `VARCHAR(45)` | IPv4 or IPv6 |
| `user_agent` | `VARCHAR(255)` | Browser string |
| `created_at` | `TIMESTAMP` | Immutable timestamp |

**Relationships:** optional actor reference to `users`.  
**Deletion:** logs are retained; no routine user deletion of logs.

### 6.4 Integrity rules summary

| Rule | Enforcement |
|---|---|
| Email and student number are unique | Unique indexes |
| ISBN and copy code are unique | Unique indexes |
| One active request per student/title | Generated column + unique index |
| One approved request per copy | Generated column + unique index |
| One borrowing per request | Unique index |
| One active borrowing per copy | Generated column + unique index |
| Approved request has a copy | `CHECK` constraint + application validation |
| Returned borrowing has a return date | `CHECK` constraint |
| Fine days and amount are positive | `CHECK` constraints |
| Copy cannot be both available and issued | Single `status` enum |
| History is preserved | `RESTRICT` foreign keys and soft archival |

---

## 7. Application Architecture

### 7.1 Request lifecycle

Every page follows the same readable pattern:

1. Include `includes/init.php` (configuration, PDO connection, shared functions, session, auth/authorization helpers, CSRF helpers).
2. Establish the required authorization:
   - public page — no guard;
   - authenticated page — `require_login($pdo)`;
   - staff page — `require_staff($pdo)`;
   - administrator page — `require_role($pdo, 'admin')`;
   - student personal page — `require_role($pdo, 'student')` plus session-owned IDs.
3. Read and validate `$_GET` or `$_POST` input.
4. Process a POST action only after method and CSRF checks.
5. Run one focused database query or one transaction.
6. Use POST/Redirect/GET with a flash message after a successful state change.
7. Render escaped output through shared header, sidebar, alerts and footer includes.

### 7.2 Project structure

```text
campus-library/
├── config/
│   ├── config.php              Application constants, time zone, session settings
│   └── database.php            PDO connection using credentials from config
├── includes/
│   ├── init.php                Single shared bootstrap for every page
│   ├── auth.php                Login, logout, current user, password helpers
│   ├── authorization.php       Role, staff and ownership guards
│   ├── csrf.php                Token generation and verification
│   ├── functions.php           Escaping, flash messages, redirects, validation
│   ├── activity.php            Audit logging helper
│   ├── header.php              Document head and top bar
│   ├── sidebar.php             Role-aware navigation
│   ├── alerts.php              Success, warning and error messages
│   ├── pagination.php          Reusable pagination links
│   └── footer.php              Closing markup and JavaScript include
├── public/                     Web root
│   ├── index.php               Public landing page and featured catalogue
│   ├── catalogue.php           Search, filter, sort, paginate
│   ├── book.php                Public title detail + request form
│   ├── login.php               Login page
│   ├── register.php            Student registration
│   ├── logout.php              POST-only logout
│   ├── setup-admin.php         One-time guarded administrator setup
│   ├── 403.php                 Access denied
│   ├── 404.php                 Not found
│   ├── account/
│   │   └── profile.php         Own profile and password change
│   ├── api/
│   │   └── book-availability.php   JSON availability refresh
│   ├── student/
│   │   ├── dashboard.php
│   │   ├── request-book.php    POST-only request creation
│   │   ├── my-requests.php
│   │   ├── cancel-request.php  POST-only student cancellation
│   │   └── my-borrowings.php
│   ├── staff/
│   │   ├── dashboard.php       Librarian dashboard
│   │   ├── books.php           Catalogue management list
│   │   ├── book-form.php       Create/edit book + cover upload
│   │   ├── book-view.php       Staff book detail
│   │   ├── book-action.php     Archive/restore book (POST)
│   │   ├── copies.php          Physical copies for a book
│   │   ├── copy-form.php       Create/edit copy
│   │   ├── copy-action.php     Archive/restore/maintenance (POST)
│   │   ├── requests.php        Request queue and filters
│   │   ├── request-action.php  Approve/reject/cancel (POST)
│   │   ├── issue.php           Issue an approved request (POST)
│   │   ├── borrowings.php      Active/history list
│   │   ├── return.php          Process return (POST)
│   │   ├── overdue.php         Overdue list and estimated fines
│   │   └── reports.php         Printable operational reports
│   ├── admin/
│   │   ├── dashboard.php
│   │   ├── users.php           User list and filters
│   │   ├── user-form.php       Create/edit user
│   │   ├── user-action.php     Activate/deactivate (POST)
│   │   ├── categories.php      Category list
│   │   ├── category-form.php   Create/edit category
│   │   ├── category-action.php Delete category (POST)
│   │   ├── policies.php        Borrowing policy settings
│   │   └── activity-logs.php   Paginated/filterable audit log
│   ├── assets/
│   │   ├── css/style.css       Design system and responsive layout
│   │   ├── css/print.css       Print-only report styling
│   │   └── js/app.js           Validation, confirms, sidebar, availability
│   └── uploads/
│       └── covers/
│           ├── .htaccess       Prevent script execution and directory listing
│           └── .gitkeep
├── database/
│   └── database.sql            Schema, roles, settings, demo catalogue data
├── storage/
│   └── logs/
│       └── .htaccess           Deny direct web access
├── PHASE1_PLAN.md
└── documentation files         README and required Markdown guides (Phase 7)
```

### 7.3 Page inventory by role

| Area | Pages | Guard |
|---|---|---|
| Public | landing, catalogue, book detail, login, register | none |
| Setup | one-time admin setup | no admin exists + setup token |
| Account | own profile/password | authenticated |
| Student | dashboard, request, my requests, cancel, my borrowings | `student` |
| Staff | dashboard, books, copies, requests, issue, borrowings, return, overdue, reports | `admin` or `librarian` |
| Admin | dashboard, users, categories, policies, activity logs | `admin` |

### 7.4 Separation of responsibilities

- `config/` holds configuration and credentials, outside the public web root.
- `includes/auth.php` is authentication only.
- `includes/authorization.php` is authorization only.
- `includes/csrf.php` handles request-forgery protection.
- `includes/functions.php` holds small reusable helpers.
- Page files handle one feature area and remain readable.
- POST action files perform one operation and redirect.
- `database/database.sql` is the complete schema and seed script.
- CSS and JavaScript are static assets under `public/assets/`.

### 7.5 URL strategy

- Search, filtering, sorting, pagination and report dates use `$_GET`.
- All inserts, updates, approvals, rejections, issuances, returns, archives, deactivations and logouts use `$_POST`.
- IDs in URLs are validated as positive integers.
- Student personal pages never accept a student ID from the URL.
- Access-denied requests return HTTP 403 and render the project’s 403 page.
- Missing records return HTTP 404 and render the project’s 404 page.

---

## 8. Security Design

### 8.1 Requirement-to-implementation map

| Security requirement | Implementation |
|---|---|
| Password hashing | `password_hash()` with `PASSWORD_DEFAULT`; `password_verify()` only |
| SQL injection prevention | PDO prepared statements; bound parameters; whitelisted sort columns |
| XSS prevention | `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` through an `e()` helper |
| CSRF protection | Per-session token in every POST form; `hash_equals()` verification |
| Server-side validation | Every form re-validates type, length, format, range and ownership |
| Server-side authorization | Reusable role guards and session-owned record lookups |
| Secure sessions | `HttpOnly`, `SameSite=Lax`, strict mode, cookies only, custom session name |
| Session fixation protection | `session_regenerate_id(true)` after login; CSRF token rotated |
| Session timeout | Configurable idle timeout with session destruction |
| Upload validation | `finfo` MIME check, `getimagesize()`, extension whitelist, 2 MB limit, random filename |
| Unauthorized record modification | Ownership checks for student records; role checks for staff actions |
| Error handling | Exceptions caught; generic user message; details written to a protected log |
| Transactions | Explicit commit/rollback for request, approval, issuance, return and settings changes |
| Credentials | PHP configuration outside the public web root; no credentials in public pages |
| Database errors | Never printed to ordinary users; logged instead |
| State-changing requests | POST only, with CSRF; no GET delete/approve/issue/return links |
| Privilege escalation | Role assignment restricted to administrators; librarians cannot create admins |
| Audit trail | Successful security-sensitive actions appended to `activity_logs` |

### 8.2 Session security

Before `session_start()`:

```text
session.name = CAMPUSLIB_SESSION
session.use_strict_mode = 1
session.use_only_cookies = 1
session.cookie_httponly = 1
session.cookie_samesite = Lax
session.cookie_secure = 1 only when HTTPS is active
```

After successful authentication:

- regenerate the session ID with deletion of the old ID;
- rotate the CSRF token;
- store only the user ID and minimal session data;
- load the current user from the database on each request;
- require `status = 'active'` on that fresh record.

### 8.3 CSRF design

- `csrf_token()` creates a 32-byte random token stored in `$_SESSION`.
- `csrf_field()` renders a hidden input.
- `verify_csrf()` compares the submitted token with `hash_equals()`.
- Every POST handler calls `verify_csrf()` before reading data or changing state.
- Missing or invalid tokens produce a 403 response and a flash error.

### 8.4 Authorization design

- `require_login($pdo)` rejects anonymous users.
- `require_role($pdo, 'admin')` rejects non-administrators with 403.
- `require_staff($pdo)` accepts administrators and librarians.
- `require_student_owner($sessionUserId, $recordUserId)` prevents cross-student access.
- Student queries use `$_SESSION['user_id']` directly:
  `WHERE user_id = :session_user_id`.
- Staff action handlers verify the target record’s current state before changing it.
- Role IDs and statuses submitted by a browser are never trusted without checking the actor’s role and the allowed values.

### 8.5 Upload security

- Only `image/jpeg`, `image/png` and `image/webp` are accepted.
- Maximum size: 2 MB.
- The uploaded file must pass `getimagesize()`.
- The extension is derived from the detected MIME type, not the original filename.
- The stored filename is random, for example `cover_9f3c2a...webp`.
- `move_uploaded_file()` is required.
- `public/uploads/covers/.htaccess` disables script execution and directory listing.
- Replacing a cover deletes the old file only after validating that it is inside the covers directory.

### 8.6 Credentials and setup

- `config/config.php` and `config/database.php` are outside `public/`.
- `.htaccess` files deny direct access to `config/`, `includes/`, `database/` and `storage/` as defence in depth.
- `public/setup-admin.php` works only when no administrator exists.
- Setup also requires `SETUP_TOKEN` from configuration.
- After successful setup, the page reports that setup is complete and refuses further administrator creation.
- The installation guide instructs the operator to remove or rename the setup page after use.
- No public administrator password is included in `database.sql`.

### 8.7 Error handling

- `display_errors` is off for normal operation.
- Exceptions and database errors are written to `storage/logs/app.log`.
- Users see short, non-technical messages.
- Validation errors are shown beside the relevant fields.
- Unexpected failures are logged and paired with a generic retry message.
- A failed transaction is rolled back before the error response.

### 8.8 Threat model summary

| Threat | Defence |
|---|---|
| SQL injection through search, login or filters | Prepared statements and whitelisted ORDER BY |
| Stored/reflected XSS through names, titles or notes | Central escaping helper on output |
| CSRF on approval, return or user changes | POST + CSRF token verification |
| IDOR on student records | Session-owned queries |
| Privilege escalation | Admin-only role management; role guards on every page |
| Session fixation | ID regeneration after login |
| Session theft | HttpOnly, SameSite, HTTPS-ready cookie flag |
| Malicious uploads | MIME/image inspection, size limit, randomized names, execution disabled |
| Double issuance | Transactions, row locks, copy status, unique active-copy key |
| Brute-force login | Generic errors and optional simple attempt delay; no account enumeration |
| Database error leakage | Exceptions logged, not printed |
| Setup abuse | One-time token + no-existing-admin check |

Brute-force protection is intentionally modest in the initial scope. The application does not lock accounts automatically, because that can be used to deny service. If time permits during Phase 6, a session-based failed-login delay will be added without disclosing whether an email exists.

---

## 9. Interface and Design System

### 9.1 Direction

The visual language should feel like a real university library administration product:

- calm neutral background;
- deep academic green as the primary colour;
- restrained brass accent;
- serif display headings and a clean sans-serif interface;
- thin borders and subtle surfaces rather than large shadows;
- clear status badges and readable data tables;
- generous but not wasteful spacing.

No large gradient hero sections, floating glass cards, oversized statistics, decorative blobs or animation-heavy transitions.

### 9.2 Design tokens

```css
:root {
  --paper: #f4f1ea;
  --surface: #fffdf9;
  --surface-muted: #ece7dc;
  --ink: #1c2a33;
  --muted: #66727a;
  --line: #d9d2c6;
  --brand: #0f4c45;
  --brand-dark: #0a3732;
  --brand-soft: #dce8e4;
  --accent: #a6753b;
  --success: #2f6b4c;
  --success-soft: #e1efe6;
  --warning: #94651d;
  --warning-soft: #f5ead3;
  --danger: #9b3a32;
  --danger-soft: #f5dfdc;
  --info: #315f7d;
  --info-soft: #dfeaf1;
  --radius: 6px;
  --shadow: 0 1px 2px rgba(28, 42, 51, 0.06);
  --font-body: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
  --font-display: Georgia, "Times New Roman", serif;
}
```

### 9.3 Layout

- Public pages: compact top header, centred content width up to 1200px, footer.
- Authenticated pages: top bar, fixed sidebar, main content area.
- Sidebar sections:
  - **Overview** — dashboard.
  - **Library Operations** — books, copies, requests, borrowings, overdue, reports (admin/librarian).
  - **My Library** — dashboard, requests, borrowings (student).
  - **Administration** — users, categories, policies, activity log (admin).
- Active navigation uses a brass left border and soft background.
- Below 960px the sidebar becomes a collapsible drawer with an accessible toggle.
- Tables scroll horizontally on narrow screens instead of squeezing columns.

### 9.4 Reusable components

- Primary, secondary, danger and ghost buttons.
- Status badges for request, copy, borrowing and account states.
- Alert banners for success, warning, danger and information.
- Summary stat blocks with a small label and a clear value.
- Data tables with a header row, row actions and empty states.
- Two-column responsive forms with inline field errors.
- Pagination with previous, numbered pages and next.
- Confirmation dialog for irreversible or consequential actions.
- Printable report header and table.

### 9.5 JavaScript use

JavaScript enhances the interface but never replaces server-side checks:

- mobile sidebar toggle;
- password visibility toggle;
- client-side form validation as a convenience;
- confirmation dialogs before approve, reject, issue, return, archive and deactivate actions;
- print button for reports;
- a small Fetch API calls `api/book-availability.php` to refresh a title’s available-copy count on the book detail page.

The availability endpoint returns only public counts, never personal data. Without JavaScript the page still works using server-rendered availability.

### 9.6 Accessibility

- Semantic landmarks (`header`, `nav`, `main`, `footer`).
- A visible label for every form control.
- Keyboard-accessible navigation and buttons.
- Visible focus outlines.
- Sufficient contrast.
- Status text is not conveyed by colour alone; badges include words.
- Tables use proper `thead`, `th` and `caption` where useful.
- Error summary at the top of long forms with links to fields where practical.

### 9.7 Print design

- `print.css` hides the header, sidebar, buttons, pagination and flash messages.
- Reports show the library name, report title, date range and generation time.
- Tables use solid borders and black text.
- Print action uses `window.print()` and does not change server state.

---

## 10. Testing and Quality Assurance Plan

Testing will be performed against a real MySQL database and a running XAMPP server during Phase 6. Results will be recorded honestly in `TESTING.md`; no feature will be marked complete based only on reading the code.

### 10.1 Functional test areas

| Area | Examples |
|---|---|
| Registration | Valid registration; duplicate email; duplicate student number; weak password; mismatched confirmation |
| Login/logout | Each role; wrong password; inactive account; session destroyed after logout |
| Password storage | Database contains a hash, never the plain password; verification succeeds |
| Authorization | Student blocked from `/staff/` and `/admin/`; librarian blocked from `/admin/`; anonymous blocked from personal pages |
| IDOR | Student cannot view another student’s requests or borrowings by editing an ID |
| Catalogue | Search by title/author/ISBN; category filter; availability filter; sorting; pagination |
| Books | Create, edit, upload cover, archive, restore; reject invalid uploads |
| Copies | Add, edit, maintenance, archive; duplicate copy code rejected |
| Requests | Valid request; duplicate active request blocked; limit blocked; inactive account blocked |
| Approval | Reserve copy; reject with note; cancel approved request releases copy |
| Issuance | Create borrowing; correct due date; double issue prevented |
| Returns | Copy available again; return date recorded; late fine calculated |
| Overdue | Active overdue list accurate; days overdue correct; estimated fine correct |
| Policies | Changed loan period/limit/fine affects new operations, not historical records |
| Reports | Date filters, totals, most-borrowed list, monthly grouping and printable output |
| Activity log | Actions recorded; only admin can view |
| Setup | Works once; rejects wrong token; refuses once an administrator exists |
| Database integrity | Foreign keys, unique keys, check constraints and transaction rollback |
| Error handling | No stack traces or SQL errors shown to users |

### 10.2 Security test cases

- SQL injection strings in login, search, filters and sort parameters.
- Stored XSS in a book title, author, user name and decision note.
- POST action without a CSRF token.
- POST action with another session’s token.
- Direct URL access to every role-protected page.
- Student altering a URL ID to another student’s record.
- Librarian attempting to open administrator routes or submit role changes.
- Upload of a PHP script renamed as `.jpg`.
- Upload over 2 MB.
- Double-click and two-tab issuance of the same copy.
- Approval of the same copy by two staff sessions.
- Session ID before and after login.
- Cookie flags inspection.
- Deactivated user with an existing session.
- Last-admin deactivation/demotion attempt.

### 10.3 Phase gates

| Phase | Gate before proceeding |
|---|---|
| 1 — Planning | Requirements, schema, roles, workflows and structure approved |
| 2 — Foundation | Database imports; connection works; registration/login/logout and guards work |
| 3 — Library operations | Category/book/copy CRUD, uploads and catalogue search verified |
| 4 — Borrowing | Request → approve → issue → return → overdue → fine verified with transactions |
| 5 — Dashboards/reports | Role dashboards, statistics, reports and logs verified |
| 6 — Security/testing | Security checklist and functional test matrix recorded |
| 7 — Finalization | Documentation, demo data, installation steps and acceptance checklist complete |

---

## 11. Documentation Plan

The final project will include:

| File | Purpose |
|---|---|
| `README.md` | Project summary, quick start, features and screenshots/structure |
| `PROJECT_OVERVIEW.md` | Purpose, modules, users, workflows and architecture |
| `DATABASE_DESIGN.md` | Every table, relationship, constraint, index and deletion policy |
| `SYSTEM_REQUIREMENTS.md` | Functional and non-functional requirements with acceptance criteria |
| `USER_ROLES_AND_PERMISSIONS.md` | Role matrix, authorization rules and forbidden actions |
| `SECURITY.md` | Implemented controls, threat model and verification notes |
| `LEARNING_GUIDE.md` | PHP, PDO, session, validation, CSRF and JavaScript concepts with real file references |
| `TESTING.md` | Test checklist, commands, actual results and unresolved issues |
| `database.sql` | Importable schema, roles, settings and demonstration data |

The installation section will explain:

1. Start Apache and MySQL in XAMPP.
2. Place `campus-library/` in `C:\xampp\htdocs\`.
3. Import `database/database.sql` through phpMyAdmin.
4. Configure database credentials in `config/config.php` and `config/database.php`.
5. Set the setup token.
6. Open `http://localhost/campus-library/public/`.
7. Create the first administrator through the guarded setup page.
8. Remove or rename the setup page.
9. Log in and create or use demonstration accounts.

---

## 12. Delivery Phases and Current Status

### Phase 1 — Planning (complete)

- Requirements defined.
- Scope and exclusions defined.
- Role permissions defined.
- Borrowing, return and archive workflows defined.
- Database entities, relationships and integrity rules defined.
- Security architecture defined.
- Interface direction defined.
- Project structure defined.
- Testing and documentation plans defined.
- No application code written.

### Phase 2 — Foundation (next, after approval)

- Create directory structure.
- Create `database.sql` with schema, constraints and seed data.
- Create configuration and PDO connection.
- Create shared includes and design-system foundation.
- Create authentication, sessions, CSRF and authorization.
- Create registration, login, logout, profile and setup-admin.
- Test connection, registration, login, logout and role guards.

### Phase 3 — Library operations

- Categories, books, copies and cover uploads.
- Staff book/copy management.
- Public catalogue, search, filters, sorting and pagination.
- Student book detail and availability.

### Phase 4 — Borrowing

- Student requests and cancellation.
- Staff approval, rejection and cancellation.
- Issuance and returns.
- Overdue detection and fines.
- Transaction and concurrency tests.

### Phase 5 — Dashboards and reports

- Administrator, librarian and student dashboards.
- Statistics, reports, date filters and printing.
- Activity logs.
- Optional small JavaScript charts or CSS bar charts.

### Phase 6 — Security and testing

- Full security review.
- Functional, authorization and database-integrity tests.
- Error-handling checks.
- Record actual results in `TESTING.md`.

### Phase 7 — Finalization

- Complete all required documentation.
- Add realistic demonstration data.
- Verify installation from a clean database import.
- Final acceptance checklist.

---

## 13. Decisions Requiring Approval

The following defaults are recommended. They can be changed before Phase 2 begins.

| Decision | Recommended default | Reason |
|---|---|---|
| Loan period | 14 days | Common academic loan period |
| Maximum active borrowings | 3 | Simple and defensible |
| Fine rate | KES 10 per day | Demonstrates fine calculation without payment integration |
| Fine enforcement | Record and display only | Required scope; no M-Pesa |
| Currency | KES | Matches the intended local context |
| Time zone | `Africa/Nairobi` | Consistent date handling |
| Request model | Request a title; staff assigns a copy | Prevents students manipulating copy IDs |
| Approval and issuance | Separate steps | Matches the required workflow |
| Student registration | Immediate active account | No email infrastructure required |
| Administrator setup | One-time web page with token | Works cleanly under XAMPP |
| Demo accounts | Seeded librarian and student accounts with documented local-only passwords | Makes assessment demonstration easier |
| Project location | `campus-library/` with `public/` as web root | Keeps secrets outside the public web root |
| Navigation | Role-aware sidebar | Clear and professional |

### Explicit non-goals to avoid scope creep

- No payment gateway or M-Pesa integration.
- No email or SMS service.
- No barcode hardware.
- No multi-campus transfers.
- No reservation queue.
- No renewals.
- No third-party authentication.
- No JavaScript framework.
- No Composer requirement.

---

## 14. Phase 1 Exit Checklist

- [x] Project purpose and scope defined.
- [x] Technology constraints respected.
- [x] Three roles and permission boundaries defined.
- [x] Authentication and authorization separated.
- [x] Borrowing workflow defined end to end.
- [x] Return, overdue and fine behaviour defined.
- [x] Book-title versus physical-copy model defined.
- [x] Database tables, relationships and deletion policies defined.
- [x] Database integrity and concurrency rules defined.
- [x] Security controls mapped to concrete implementation.
- [x] Project structure and page inventory defined.
- [x] Navigation, design system and responsive direction defined.
- [x] Testing and documentation plans defined.
- [x] No application code written.
- [ ] Phase 2 approval received.



