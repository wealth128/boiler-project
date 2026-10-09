# Project: AFP Hospital Patient Encoding & Reporting System

A centralized system that replaces Google Sheets and manual tallying. Offices **encode** patient visits, and Admin **generates** monthly, quarterly and annual reports automatically. It runs on the hospital's local network only.

## Folders
- `planning/`: source of truth for every decision. **Read before coding.** Don't edit unless asked.
- `prototype/prototype.html`: clickable prototype (reference for screens and behavior). Don't edit.
- `system/`: the Laravel application. **All code goes here.**

## Key Planning Docs (read in this order)
1. `planning/mvp-scope.md`: fields, counting rules, scope
2. `planning/rbac.md`: roles and permission matrix
3. `planning/database-schema.md`: tables, columns, delete rules
4. `planning/architecture.md`: stack and backup
5. `planning/setup-and-structure.md`: setup, route map, file structure
6. `planning/phase5-foundation.md`: current build plan

## Stack
Laravel (latest) + official **React starter kit** (Inertia, TypeScript, Tailwind) + **MySQL** (`patient_census`). Monolith: no separate API and no Sanctum. Timezone `Asia/Manila`. Testing with Pest.
Packages allowed: `maatwebsite/excel`, `barryvdh/laravel-dompdf`. **Don't add other packages** (no Spatie) without asking.

## Rules That Must Not Be Broken
- **Login is by username.** There's **no public sign-up**; Admin or System Admin creates accounts. An account locks after **5 wrong passwords** until unlocked. There's no idle auto-logout.
- **Roles:** `encoder`, `admin` (only ONE active), `system_admin` (user accounts only, no patient data), `viewer` (CO: reports only).
- **Soft delete** (`SoftDeletes` + `deleted_by` + `delete_reason`) on visits, patients, users, diagnoses, ranks, age_brackets. **Hard delete only from Trash, by Admin**, after typing the ID to confirm. Hard delete is blocked when: the visit's month is closed; a patient still has visits; a diagnosis or rank is used by a visit; a user has visits or audit entries.
- **Closed month:** no edits or deletes until reopened. Closing saves a report snapshot.
- **Every** create, update, delete, restore, permanent delete, close/reopen, unlock and list change writes to `audit_logs`.
- Reports count **each patient once per category per period**. Deleted visits are excluded.
- Patient fields: auto Patient ID, last/first name, M.I., sex (Male/Female), **birthdate**, **age (auto from birthdate, editable)**, branch, rank (filtered by branch), diagnosis (+ Other: specify), category, remarks. **No AFP serial number.**

## File Structure Rule
Code used by **2+ features** goes in global folders. Code used by one feature stays in that feature's folder.
- Global frontend: `resources/js/components/ui`, `components/shared`, `layouts`, `hooks`, `lib`, `types`
- Feature frontend: `resources/js/features/<feature>/`, `resources/js/pages/<feature>/`
- Global backend: `app/Services`, `app/Enums`, `app/Http/Middleware`, `app/Policies`, `app/Observers`
- Feature backend: `app/Http/Controllers/<Feature>/`, `app/Http/Requests/<Feature>/`

## How to Work
- Build in this order: **1. Database/config → 2. File structure + global components → 3. Login → 4. RBAC → 5. Features (later).**
- **Stop after each step.** Summarize in plain language what was built and how to check it, then wait for approval.
- Run `php artisan test` and `npm run build` (or `npm run types`) before saying a step is done. Fix errors first.
- Never put real passwords in committed files. Use `.env`.
- **Tests run ONLY on `patient_census_test`.** Never run `php artisan test` against `patient_census`: RefreshDatabase wipes every table. If the test database is unreachable, stop and tell me instead of switching databases.
