# Phase 5: Foundational Features (Build Plan)

_Last updated: 2026-10-07 · Stack: Laravel + Inertia React + MySQL · Status: Plan for approval_

**Goal:** a working skeleton with login, roles, the data tables, the audit log, Trash and backup, and no business screens yet.
**Done when:** each test user (ER Encoder, Admin, System Admin, CO) can log in and only reach what their role allows, and the automated tests pass.

| # | Step | What gets built |
|---|---|---|
| 1 | Project setup | New Laravel project with the official **React starter kit** (Inertia + Tailwind). MySQL database `patient_census`. Public sign-up turned off. |
| 2 | Migrations | All tables from `database-schema.md`: users (extended), patients, visits, branches, ranks, diagnoses, age_brackets, month_closures, report_snapshots, month_deletions, audit_logs, backup_runs, settings |
| 3 | Models | Relationships plus `SoftDeletes` on visits, patients, users, diagnoses, ranks and age_brackets |
| 4 | Seeders | AFP branches and ranks (draft), sample diagnoses and age brackets, 1 Admin, 1 System Admin, hospital settings |
| 5 | Login | Login by **username** (not email). Locks after **5 wrong passwords**. Deactivated, locked or deleted accounts can't log in. |
| 6 | Roles / RBAC | `Role` enum (encoder, admin, system_admin, viewer), `role:` route middleware, Gates (manage users, manage lists, view reports, manage Trash), and a Visit Policy (own same-day edit, Admin any, **closed month blocks all edits**) |
| 7 | Audit log | One `AuditLogger` service plus model observers. Every create, update, delete, restore and permanent delete is logged automatically. |
| 8 | Trash core | Restore and permanent delete for every type, including the "can't permanently delete" rules and Delete month report |
| 9 | Error handling | Clear validation messages, friendly 403 (not allowed) / 404 / 500 pages, errors written to the log file |
| 10 | Backup | `php artisan backup:run`: `mysqldump` to the second-PC folder, recorded in `backup_runs`, keeps 30 days, scheduled daily at 2:00 AM. Last backup status shown to Admin. |
| 11 | Tests | Automated tests (Pest) for: login lock, role access per page, closed-month lock, permanent-delete rules, audit entries written |

**Not in Phase 5** (comes in Phase 6): the encoding form, records, reports and exports, and the final Claude Design screens.
