# Setup, Routes & File Structure (Phase 5, Steps 1–2)

_Last updated: 2026-10-08 · Status: For review_

## Build Order (as agreed)
1. **Database setup / config** and the route map (how screens connect to the backend)
2. **File structure**, with reusable/global components separated from feature code
3. **Login** (UI + backend). Accounts are created by Admin / System Admin; there is no public sign-up page.
4. **RBAC**
5. **Features** (encoding, records, reports, lists, Trash): later

## 1. Project Folder Layout
```
boiler-project/
├─ planning/      ← all planning docs (this file, schema, RBAC…)
├─ prototype/     ← prototype.html (reference only)
└─ system/        ← the Laravel application (all code lives here)
```

## 2. One-Time Setup on Your PC
You have PHP, Composer, Node and MySQL, so Laragon is **not needed for development**. It's only for the hospital server later (Phase 8).

**a. Check versions** (in Command Prompt or PowerShell):
```
php -v          (needs 8.2 or higher)
composer -V
node -v         (needs 20 or higher)
mysql --version
```

**b. Create the database and an app user:**
```
mysql -u root -p
CREATE DATABASE patient_census CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'census_app'@'localhost' IDENTIFIED BY 'ChangeThisPassword!';
GRANT ALL PRIVILEGES ON patient_census.* TO 'census_app'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

**c. Create the Laravel project with the official React starter kit:**
```
cd %USERPROFILE%\Desktop\boiler-project
composer create-project laravel/react-starter-kit system
cd system
npm install
```

**d. Point it at MySQL.** Open `system\.env` and set:
```
APP_NAME="Patient Encoding & Reports"
APP_TIMEZONE=Asia/Manila
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=patient_census
DB_USERNAME=census_app
DB_PASSWORD=ChangeThisPassword!
```

**e. Run it once:**
```
php artisan migrate
composer run dev
```
Open **http://127.0.0.1:8000**. If the starter kit's welcome page loads, setup worked.

**f. Tell me "done".** I'll read the `system` folder and add our code on top of that exact version.

## 3. Route Map: How the Screens Connect to the Backend
With Inertia there's no separate API. Each URL below is a Laravel route. **GET** routes return a React page with its data; the others are form actions sent from React with Inertia's `useForm` / `router`. The only JSON endpoint is the live patient search.

| Area | Method | URL | Does | Who |
|---|---|---|---|---|
| Auth | GET / POST | `/login` | Show login / sign in (5-try lock) | Everyone |
| | POST | `/logout` | Sign out | Logged in |
| Encode | GET | `/encode` | Encoding form + "My entries today" | Encoder, Admin |
| | GET | `/patients/search?q=` | **JSON**: returning-patient search | Encoder, Admin |
| | POST | `/visits` | Save a visit (+ new patient) | Encoder, Admin |
| | PUT | `/visits/{visit}` | Edit a visit (own same-day / Admin any) | Encoder, Admin |
| Records | GET | `/records/visits` · `/records/patients` | Lists with filters | Admin |
| | DELETE | `/visits/{visit}` · `/patients/{patient}` | Move to Trash (reason) | Admin |
| Reports | GET | `/reports` | Report on screen | Admin, CO |
| | GET | `/reports/export/pdf` · `/reports/export/excel` | Download | Admin, CO |
| | POST | `/months/{period}/close` · `/months/{period}/reopen` | Close / reopen month | Admin |
| | DELETE | `/months/{period}` | Delete month report (to Trash) | Admin |
| Lists | GET | `/settings/lists` | Diagnoses, ranks, age brackets | Admin |
| | POST / PUT / DELETE | `/settings/{list}` · `/settings/{list}/{id}` | Add / edit / delete item | Admin |
| Users | GET / POST | `/users` | List / add account | Admin, System Admin |
| | PUT · DELETE | `/users/{user}` | Edit / move to Trash | Admin, System Admin |
| | POST | `/users/{user}/unlock` · `/reset-password` · `/toggle-active` | Account actions | Admin, System Admin |
| Audit | GET | `/audit-log` | Read-only log | Admin |
| Trash | GET | `/trash` | Trash list | Admin |
| | POST | `/trash/{type}/{id}/restore` | Restore | Admin |
| | DELETE | `/trash/{type}/{id}` · `/trash` | Delete permanently / Empty Trash | Admin |
| Backup | GET · POST | `/backups` · `/backups/run` | Last backup status / run now | Admin |

Every route goes through `auth` plus a `role:` middleware. The detailed rules (same-day edit, closed month) are checked in Policies.

## 4. File Structure
**Rule:** anything used by **two or more features** goes in the global folders. Anything used by only one feature stays inside that feature's folder.

```
system/
├─ app/
│  ├─ Enums/            Role, Office, Category (fixed value lists)
│  ├─ Models/           User, Patient, Visit, Branch, Rank, Diagnosis, AgeBracket,
│  │                    MonthClosure, ReportSnapshot, MonthDeletion, AuditLog, BackupRun, Setting
│  ├─ Http/
│  │  ├─ Controllers/   one folder per feature: Auth/, Encode/, Records/, Reports/,
│  │  │                 Settings/, Users/, Audit/, Trash/, Backup/
│  │  ├─ Requests/      form validation, one per form
│  │  └─ Middleware/    EnsureRole.php (role:admin,encoder…)
│  ├─ Policies/         VisitPolicy, PatientPolicy, UserPolicy, TrashPolicy
│  ├─ Services/         ★ shared backend logic: AuditLogger, ReportBuilder, TrashService, BackupService
│  ├─ Observers/        auto-write audit log on create/update/delete/restore
│  └─ Console/Commands/ BackupRun.php
├─ database/
│  ├─ migrations/       one file per table
│  └─ seeders/          ranks, lists, first Admin + System Admin, settings
├─ routes/web.php       the route map above
└─ resources/js/
   ├─ components/
   │  ├─ ui/            ★ GLOBAL basic parts (from the starter kit): Button, Input, Select, Dialog, Table…
   │  └─ shared/        ★ GLOBAL app parts: PageHeader, DataTable, ReasonConfirm, TypeToConfirm,
   │                      CategoryChip, EmptyState, Toast
   ├─ layouts/          ★ GLOBAL: AppLayout (top bar + sidebar by role), AuthLayout
   ├─ hooks/            ★ GLOBAL: usePermissions, useToast
   ├─ lib/              ★ GLOBAL helpers: dates, age from birthdate, formatting
   ├─ types/            ★ GLOBAL TypeScript types for the models
   ├─ features/         feature-only parts, e.g. features/encode/PatientSearch.tsx,
   │                    features/reports/BranchRankTable.tsx
   └─ pages/            one page per route: auth/Login, encode/Index, records/Visits,
                        records/Patients, reports/Index, settings/Lists, users/Index,
                        audit/Index, trash/Index, errors/Error
```
★ = reusable / global
