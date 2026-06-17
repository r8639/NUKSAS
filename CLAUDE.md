# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Running the Application

```bash
npm start          # Start Express server on port 3000
node server.js     # Equivalent alternative
```

Access the app at:
- Express: `http://localhost:3000/pages/login.html`
- Apache (XAMPP): `http://localhost/scholarship/pages/login.html`

No test or lint scripts are configured.

## Architecture

This is a **dual-backend scholarship management system** with two parallel API implementations:

- **Express.js** (`server.js`) — primary Node.js API on port 3000
- **PHP** (`api/index.php`) — secondary Apache-served API (legacy/compatibility layer)

Both backends connect to the same MySQL database (`scholarship_system` on localhost:3306). Database credentials live in `.env` (for Node) and `config.php` (for PHP).

The frontend is plain HTML/CSS/vanilla JS in `pages/`. Authentication is entirely client-side using `sessionStorage`; there are no backend sessions or JWTs.

## Key Files

| File | Purpose |
|---|---|
| `server.js` | Express app — all Node.js routes, DB pool, file upload handlers |
| `api/index.php` | Full PHP duplicate of the API (~1,173 lines) |
| `pages/auth.js` | Shared client-side auth helper (reads/writes sessionStorage) |
| `pages/login.html` | Application entry point |
| `.env` | DB and port config for Node.js |
| `config.php` | DB config for PHP |

## Database Schema

Core tables and their relationships:

- **User** (id, name, email, type) — base record for all user roles
- **Student / Teacher / Organization / System_Administrator** — role-specific extensions of User
- **Scholarship** (name PK, amount, identity_restriction, is_published, published_by, start_date, end_date)
- **Scholarship_Organization** — many-to-many between Scholarship and Organization
- **Application** (student_id FK, scholarship_name FK, apply_state: Pending/Under Review/Approved, score, gpa, family_income, recommendation_id FK)
- **Recommendation** (teacher_id FK, content, file_path)
- **Identity_Proof** (student_id FK, file_path) — uploaded ID documents
- **Announcement** (admin_id FK, title, content, publish_date)
- Identity-specific tables: **Overseas_Student, Aboriginal_Student, Low_Income_Student, Disabled_Student**

Student identity categories: 僑生 / 原住民 / 低收入戶 / 身心障礙

## User Roles

Four roles with separate dashboards under `pages/`:

| Role | Key Pages |
|---|---|
| Student | `student_dashboard.html`, `student_apply.html`, `student_results.html` |
| Teacher | `teacher_recommender.html`, `teacher_students.html` |
| Organization | `organization_*.html` |
| SystemAdministrator | `admin_scholarships.html`, `admin_accounts.html`, `admin_announcements.html`, `admin_identity_proof.html` |

## API Route Conventions (Express)

All routes are prefixed `/api/`. Dynamic SQL building is used in update routes — when adding fields, follow the existing pattern in `server.js` where field arrays are assembled conditionally before the query executes.

File uploads go to:
- `uploads/identity-proofs/` — student identity documents (5 MB max, images/PDF)
- `uploads/recommendations/` — teacher recommendation files (5 MB max, PDF/Word)

## Database Migrations

Schema changes are handled ad-hoc via standalone scripts — not a migration framework:
- `migrate.php` — adds columns to Recommendation
- `do_update.php` — adds publishing columns to Scholarship
- `update_db.sql` — raw SQL for schema updates

Run PHP scripts via browser (`http://localhost/scholarship/migrate.php`) or CLI (`php migrate.php`).

## Language

All UI text, code comments, and error messages are in **Traditional Chinese**. Keep this consistent when modifying or adding user-facing strings.
