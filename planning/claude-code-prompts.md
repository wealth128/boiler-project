# Prompts for Claude Code (VS Code)

Paste **one prompt at a time** into Claude Code. Wait until it finishes and summarizes, check the result, then paste the next one.
Claude Code automatically reads `CLAUDE.md` in the boiler-project folder, so it already knows the project rules.

---

## Before Step 1 (do this yourself, once)
Create the database in MySQL (Claude Code shouldn't handle your root password):
```
mysql -u root -p
CREATE DATABASE patient_census CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'census_app'@'localhost' IDENTIFIED BY 'ChangeThisPassword!';
GRANT ALL PRIVILEGES ON patient_census.* TO 'census_app'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## Prompt 1: Project setup + database config
```
Read CLAUDE.md and all files in planning/ first.

Step 1 – Database setup and config:
1. Check php -v, composer -V, node -v. Stop and tell me if anything is below what Laravel needs.
2. Create the Laravel app in ./system using the official React starter kit (composer create-project laravel/react-starter-kit system), then npm install.
3. Configure system/.env for MySQL: DB_DATABASE=patient_census, DB_USERNAME=census_app, DB_PASSWORD=ChangeThisPassword!, APP_TIMEZONE=Asia/Manila, APP_NAME="Patient Encoding & Reports". Make sure config/app.php uses the timezone.
4. Write migrations for EVERY table in planning/database-schema.md (extend the existing users migration: username, role, office, is_active, failed_attempts, locked_at, soft delete columns). Use foreign keys and the listed indexes.
5. Create the Enums (Role, Office, Category) and Models with relationships, casts and SoftDeletes where the schema says.
6. Seeders: branches + AFP ranks (draft lists from prototype/prototype.html), sample diagnoses, sample age brackets, settings, one Admin (username: admin) and one System Admin (username: sysadmin) with passwords read from .env (SEED_ADMIN_PASSWORD, SEED_SYSADMIN_PASSWORD).
7. Write routes/web.php entries for the full route map in planning/setup-and-structure.md as stubs (controllers can return placeholder pages for now), grouped by feature, with auth middleware.
8. Run php artisan migrate:fresh --seed and php artisan test. Fix any errors.
Stop and summarize what you built and how I can check it in MySQL.
```

## Prompt 2: File structure + global components
```
Step 2 – File structure (follow the File Structure Rule in CLAUDE.md and section 4 of planning/setup-and-structure.md):
1. Create the folders: app/Services, app/Policies, app/Observers, app/Http/Controllers/<Feature>/, resources/js/components/shared, resources/js/features/<feature>/, resources/js/pages/<feature>/, hooks, lib, types.
2. Keep the starter kit's components/ui as the global base components.
3. Build the GLOBAL shared components: PageHeader, DataTable, ReasonConfirm (reason required), TypeToConfirm (type the ID to confirm), CategoryChip, EmptyState, Toast. Match the look of prototype/prototype.html (white, navy accent, plain).
4. Build AppLayout (top bar + left sidebar; menu items depend on the user's role, see planning/rbac.md) and AuthLayout.
5. Add global helpers in lib/ (date format DD-Mon-YYYY, age from birthdate) and TypeScript types in types/ for every model.
6. Add a short system/STRUCTURE.md explaining which folder is global vs feature.
7. Run npm run build and fix errors.
Stop and summarize, listing each global component and where it lives.
```

## Prompt 2.5: Fixes from the review (run before Prompt 3)
```
Read planning/review-steps-1-2.md and fix every item in "Must Fix" and "Should Fix" except #5 and #8 (those are done in Steps 3 and 4):
1. Remove email verification: MustVerifyEmail on User, Features::emailVerification(), the 'verified' middleware, the verify-email page and its tests.
2. Remove password reset by email, two-factor authentication and passkeys: Fortify features, their pages, components, routes, migrations (two_factor columns, passkeys table), hooks, npm package @laravel/passkeys, and their tests. Admin / System Admin will reset passwords instead.
3. Profile page: name only (no email). Keep "change my own password".
4. Remove WithoutModelEvents from DatabaseSeeder.
6. Create a MySQL test database patient_census_test (tell me the SQL to run as root for the grant) and point phpunit.xml at it instead of SQLite.
7. HandleInertiaRequests: share only id, name, username, role, office of the user.
9. Raise the Fortify 'login' rate limit to 10 per minute.
10. Delete database/database.sqlite.
Then run php artisan migrate:fresh --seed, php artisan test, npm run types:check and npm run build. Fix errors. Stop and summarize.
```

## Prompt 3: Login
```
Step 3 – Login (UI + backend):
1. Change login to USERNAME + password (not email). Remove/disable the public registration routes and pages, and remove password reset by email (Admin resets passwords).
2. Lock the account after 5 wrong passwords (failed_attempts, locked_at). Show "X attempts left" and a locked message. Reset the counter on success. Block deactivated, locked and soft-deleted users.
3. Write audit_logs entries for login lock and successful login.
4. Style the login page like prototype/prototype.html using AuthLayout.
5. Pest tests: correct login, wrong password count, lock at 5, locked user can't log in, deleted user can't log in, no /register route.
Run php artisan test and npm run build. Stop and summarize how I can test it in the browser.
```

## Prompt 4: RBAC
```
Step 4 – RBAC (follow planning/rbac.md exactly):
1. EnsureRole middleware (role:admin,encoder,...) and apply it to every route group in routes/web.php per the matrix.
2. Gates: manage-users (admin, system_admin), manage-lists, manage-trash, close-month, view-audit (admin), view-reports (admin, viewer).
3. Policies: VisitPolicy (encoder edits own visit same day only; admin any; nobody when the month is closed), UserPolicy (can't delete self or the Admin; only one active Admin), TrashPolicy.
4. Share the user's role and permissions with the frontend (Inertia shared props) and add a usePermissions hook. The sidebar shows only allowed pages.
5. Friendly 403, 404 and 500 pages.
6. Create test users for each role in a dev-only seeder.
7. Pest tests: every role vs every route group (allowed / 403), closed-month edit blocked, same-day edit rule.
Run php artisan test and npm run build. Stop and summarize, with a table of role → pages they can open.
```

---
**After Step 4:** come back to this chat (or keep going in Claude Code) for Step 5: Features.
