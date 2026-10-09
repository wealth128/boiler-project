# Folder Structure: Global vs Feature

**Rule:** code used by **two or more features** goes in a global folder. Code used by **one feature** stays in that feature's folder. When feature code starts being used by a second feature, move it to the matching global folder.

Features: `encode`, `records`, `reports`, `lists` (dropdown lists at /settings/lists), `users`, `audit`, `trash`, `backups`.

## Backend (`app/`)

| Folder                        | Global or feature | What goes there                                                                  |
| ----------------------------- | ----------------- | -------------------------------------------------------------------------------- |
| `Enums/`                      | Global            | Fixed value lists: Role, Office, Category, Sex, BackupStatus                     |
| `Models/`                     | Global            | One model per table. `Models/Concerns/MovesToTrash` holds the soft-delete logic. |
| `Services/`                   | Global            | Shared logic: AuditLogger, ReportBuilder, TrashService, BackupService            |
| `Policies/`                   | Global            | VisitPolicy, PatientPolicy, UserPolicy, TrashPolicy                              |
| `Observers/`                  | Global            | Write audit entries on create, update, delete and restore                        |
| `Http/Middleware/`            | Global            | EnsureRole (`role:` route rule), HandleInertiaRequests (props every page gets)   |
| `Http/Controllers/<Feature>/` | Feature           | Controllers for one feature, e.g. `Encode/VisitController.php`                   |
| `Http/Requests/<Feature>/`    | Feature           | Form validation for one feature's forms                                          |

## Frontend (`resources/js/`)

| Folder                | Global or feature | What goes there                                                                            |
| --------------------- | ----------------- | ------------------------------------------------------------------------------------------ |
| `components/ui/`      | Global            | Basic parts from the starter kit: Button, Input, Select, Dialog… Don't edit unless needed. |
| `components/shared/`  | Global            | App parts used by several screens (list below)                                             |
| `layouts/`            | Global            | AppLayout (top bar and role-based menu), AuthLayout (login panel)                          |
| `hooks/`              | Global            | `use-permissions`, `use-toast`, plus the starter kit's hooks                               |
| `lib/`                | Global            | Helpers: `dates`, `age`, `format`, `permissions`, `utils`                                  |
| `types/`              | Global            | TypeScript types for every model (`models.ts`) and shared page props                       |
| `features/<feature>/` | Feature           | Parts used by one feature only, e.g. `features/encode/patient-search.tsx`                  |
| `pages/<feature>/`    | Feature           | One file per screen, e.g. `pages/encode/index.tsx`                                         |

### Global shared components (`components/shared/`)

| Component     | File                  | Use it for                                                             |
| ------------- | --------------------- | ---------------------------------------------------------------------- |
| PageHeader    | `page-header.tsx`     | Title, one-line description and buttons at the top of a screen         |
| DataTable     | `data-table.tsx`      | Plain tables with an empty message and an optional line under each row |
| ReasonConfirm | `reason-confirm.tsx`  | "Move to Trash?" and other confirms that need a reason                 |
| TypeToConfirm | `type-to-confirm.tsx` | Permanent delete: the user types the item's ID first                   |
| CategoryChip  | `category-chip.tsx`   | OPD / ER / Admission label in its color                                |
| Chip          | `chip.tsx`            | Other small labels: "New patient", "Closed", counts                    |
| EmptyState    | `empty-state.tsx`     | What an empty list or table shows                                      |
| Toast         | `toast.tsx`           | Short messages at the bottom of the screen. Mounted once in `app.tsx`. |

## Naming

- File names are kebab-case (`page-header.tsx`), and components inside are PascalCase (`PageHeader`). This follows the starter kit.
- An Inertia page name is its path under `pages/`, e.g. `Inertia::render('encode/index')`.
- Colors come from the theme in `resources/css/app.css`. Use `bg-navy`, `text-ok`, `bg-bad-soft` and so on instead of raw hex values.
- Dates on screen use `lib/dates` (DD-Mon-YYYY, Asia/Manila time).

## Starter kit leftovers

`components/` (top level, outside `ui/` and `shared/`), `layouts/settings/`, and `pages/auth/` and `pages/settings/` come from the starter kit. They cover the login, confirm-password, profile (name only), change-password and appearance screens. Email verification, reset by email, two-factor and passkeys were removed. Step 3 (Login) reworks them. Don't add new code there.
