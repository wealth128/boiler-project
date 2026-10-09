# Review: Steps 1–2 (Database/Config + File Structure)

_Reviewed: 2026-10-08 · Code read from `system/` (Laravel 13, React starter kit with Fortify)_

## Verdict
**Good foundation. Fix 5 items before Step 3.** The tables, models, seeders, route map and folder structure match the planning docs. The problems are leftovers from the starter kit that conflict with our decisions.

## ✅ Matches the Plan
- All 12 tables match `database-schema.md`: soft-delete columns (`deleted_by`, `delete_reason`), foreign keys, report indexes, `month_deletion_id`
- Patient and Visit IDs are generated automatically (`P-000001`, `V-000001`)
- Audit log entries can't be edited or deleted (enforced in the model)
- Enums for Role / Office / Category / Sex. Timezone is Asia/Manila. Short audit names via the morph map.
- Seeder refuses empty passwords and won't create a second active Admin. No real passwords in `.env.example`.
- Route map is complete, with format rules for `{period}` and `{id}`
- Folder structure follows the global vs feature rule. `STRUCTURE.md` explains it.
- Frontend permission map matches `rbac.md`, and the sidebar shows only allowed items
- Global components (PageHeader, DataTable, ReasonConfirm, TypeToConfirm, CategoryChip, Chip, EmptyState, Toast), date/age helpers and model types are in place

## ❌ Must Fix (before or with Step 3)
| # | Problem | Why it matters | Fix |
|---|---|---|---|
| 1 | **Email verification is on** (`MustVerifyEmail`, `Features::emailVerification()`, `verified` middleware) | Accounts have no email, so Admin gets stuck on "verify your email" when opening Security/Appearance | Remove all three |
| 2 | **Starter kit extras still on:** password reset by email, two-factor, passkeys (with their pages, routes, migrations, tests) | Users have no email, so reset-by-email can't work. 2FA/passkeys weren't planned and add things to maintain. | Remove them. Admin / System Admin resets passwords. |
| 3 | **Profile page edits email** | Users shouldn't manage email. Username and role are set by Admin. | Profile = name + change own password only |
| 4 | **`DatabaseSeeder` uses `WithoutModelEvents`** | Model events don't run during seeding, so patients/visits created by seeders get **no Patient ID / Visit No.** | Remove `WithoutModelEvents` |
| 5 | Fortify login field is still `email` | Planned for Step 3 | Change to `username` in Step 3 |

## ⚠️ Should Fix
| # | Problem | Fix |
|---|---|---|
| 6 | Tests run on **SQLite in memory**, but the real database is **MySQL**. Report queries could pass tests and still fail on MySQL. | Create `patient_census_test` in MySQL and point `phpunit.xml` at it (before Phase 6 reports at the latest) |
| 7 | Every page receives the **whole user record** (lock counter, delete info…) | Share only id, name, username, role, office |
| 8 | `/` shows the starter kit's public **welcome page** | Redirect `/` to login, or to the user's home page by role (Step 4) |
| 9 | Login throttle (5 per minute) and our 5-wrong-password lock both trigger at 5 | Raise the throttle (e.g. 10/min) so the clear "account locked" message shows |
| 10 | Leftover `database/database.sqlite` | Delete it |

## ❓ Couldn't Verify From Here
Run these in `system/` and paste the last lines of each:
```
php artisan migrate:status
php artisan test
npm run types:check
npm run build
```
