# Project History & Handover: AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-16 (Step 6e done) · Read this first in any new Claude conversation about this project._

This file carries over what was discussed in the first planning-and-review conversation (Oct 3–15, 2026), so a new conversation can continue without starting over. The official rules are still in `CLAUDE.md` and the other `planning/` files. This file explains **what was decided, why, where we are, and how to work with me.**

---

## 0. Quick Start (read this first; it may be all you need)
| | |
|---|---|
| **Current step** | 6g Backup screen (6f Audit log is done and merged) |
| **Next step** | 6h Settings (start a **new conversation** for each step) |
| **Next prompt file** | Not written yet (6g Backup screen). Last prompt: `planning/Prompts/prompt-6f.md` |
| **Last merged commit** | Step 6f Audit log merge into main (2026-10-16, not pushed yet; hash: see `git log -1`) |
| **Open decisions** | HTTPS vs HTTP on the hospital LAN (Phase 8) |

**Token-saving rules (agreed 2026-10-16):**
- Start a **new conversation for each major step** (6f, 6g, 6h, security review…). Give it only this file plus the step's prompt; read other files or chat history only when I say so.
- The reviewer checks Claude Code's summary by default, and reads the actual code only when I say **"check the code"** (recommended for steps that count numbers, delete data or touch security).
- **At the end of every major step**, update this file before the step's commit: section 0 (Quick Start), section 5 (status table), and the decisions log for anything newly decided. Claude Code does this as part of the commit.

---

## 1. The Project in Short
- **Problem:** the hospital's Admission/Admin office tallies patients by hand from Google Sheets every month for the Commanding Officer (CO).
- **Goal:** one centralized system. Offices **encode** each patient visit (OPD / ER / Admission), and the system **generates** monthly, quarterly, annual and custom-range reports automatically.
- **Where it runs:** one Windows server PC on the hospital LAN (Laragon), opened from office PCs by browser. No internet.
- **Repo:** https://github.com/wealth128/boiler-project (private). Folders: `planning/` (decisions), `planning/Prompts/` (every prompt sent to Claude Code), `prototype/prototype.html` (final screen reference), `system/` (Laravel app), `docs/` (keyboard shortcuts so far).

## 2. How We Work (roles)
| Who | Does |
|---|---|
| **Me (the developer)** | Decides. Pastes prompts into Claude Code, runs the browser checks, commits and pushes. |
| **Claude Code (VS Code)** | Builds one step at a time from a prompt, runs tests, stops and summarizes. Never commits until I say "okay". |
| **Claude chat (reviewer/planner)** | Writes each step's prompt from the planning docs and prototype, reviews Claude Code's summary **and the actual code**, explains things in plain language, flags anything new. |

**Loop for every step:** reviewer writes `planning/Prompts/prompt-6x.md` → I paste it into Claude Code → Claude Code builds on a new branch, runs `php artisan test` (test DB only), `npm run types:check`, `npm run build`, and summarizes → reviewer checks the summary and code → I do the browser checks → I tell Claude Code to commit and merge → I run `git push origin main`.

## 3. How I Want to Be Helped (important)
- **Don't decide for me.** Present a recommendation, say clearly which parts are **new** versus **already agreed**, and let me choose. (I was upset once when birthdate was removed without asking.)
- **Plain language with real examples** from this system. I'm a Systems Analyst and know Laravel MVC, Python, MySQL and SQLite, but I want the "why".
- **Short and practical.** No over-engineering. Give me the exact message to paste into Claude Code.
- **Check the real code**, not only Claude Code's summary.
- **English** in technical sessions.

## 4. Decisions Log (what and why)

### Scope (Phase 1)
- Encoding fields: auto Patient ID, auto date/time, last name, first name, M.I., sex, **birthdate (encoded)**, **age (auto from birthdate, still editable)**, branch, rank (filtered by branch; "Others" = Dependent, Retiree, Civilian Employee, Civilian, Other (specify)), diagnosis (pick list + Other (specify)), category, remarks.
- **AFP serial number removed** (too personal).
- **Only ER encodes in v1.0.** OPD and Admission accounts are off. ER can choose any category.
- **Transfers:** no transfer screen. A transfer is a new visit in the new category, with a remark like "From ER, admitted to ward".
- **Counting:** each patient counted **once per category per period**; total visits shown as a separate line. A visit counts in the month it was encoded.
- **Diagnosis table is per visit and lists every diagnosis by name, typed Other text included; decided 2026-10-15.** It replaces "Top 10 + Others". Sex, Age range and Branch/rank stay per patient (latest visit).
- **Reports:** per category: total patients and visits, sex, age brackets, diagnosis (per visit, every diagnosis by name), Branch × Rank. Formats: screen, PDF, Excel, print. Header: hospital name/logo, period, Prepared by, Noted by: Commanding Officer.
- No import of old Google Sheets. Not in v1.0: discharge, transfer tracking, bed census, dashboards.

### Roles & access
- Roles: **encoder**, **admin** (only one active), **system_admin** (accounts only, no patient data, only one active), **viewer** (CO, reports only).
- Encoder edits **own visits, same day only**. Admin edits any. Nobody edits a **closed month**.
- Login by **username**; 5 wrong passwords lock the account; 10 tries/minute limit.
- **Temporary password** after create/reset must be changed at first login. Resetting asks for the resetter's own password.
- Deactivated / locked / deleted users are signed out on their next click.
- **Everyone is signed out daily at 8:00 PM** (Asia/Manila). No idle timeout. ER night shift logs in again.
- Password rule: at least 8 characters, letters and numbers (works offline; no internet check).
- Admin account protected: System Admin can't edit/reset/deactivate it; nobody changes their own role; IT has `php artisan census:reset-admin-password`.

### Data safety
- **Soft delete → Trash** for visits, patients, users, diagnoses, ranks, age brackets. Permanent delete only from Trash, by Admin, typing the ID.
- Permanent delete is blocked when: the month is closed; a patient still has visits; a diagnosis/rank is used by a visit; a user has visits or audit entries.
- A patient goes to Trash only after all their visits are in Trash. A visit can't be restored while its patient is in Trash.
- **Delete reason is a dropdown per record type** (added 2026-10-14 so people can't type "asd"):
  - Visit: Duplicate entry · Wrong patient selected · Encoded by mistake · Test / training entry · Other (specify)
  - Patient: Duplicate patient record · Encoded by mistake · Test / training entry · Other (specify)
  - User: Resigned / separated · Transferred out · Created by mistake · Other (specify)
  - List item: Duplicate item · Misspelled, replaced with a corrected one · No longer used · Other (specify)
  - Month report: Encoded in the wrong month · Test / training data · Other (specify)
  - "Other" needs 10–200 characters, saved as "Other: <text>". Checked in TrashService on the server.
- **Close month** (only after the month ends) saves a report snapshot and locks edits. Reopen removes it. **Delete month report** moves all its visits to Trash as one item.
- **Audit log** records every change; can't be edited; never logs passwords.
- **Daily backup** at 02:00, kept 30 days, each run recorded. Logs: one file per day, kept 30 days.

### Stack
- Laravel 13 (PHP 8.3) + official React starter kit (Inertia, TypeScript, Tailwind), Fortify, Wayfinder, Pest, MySQL. Monolith. **No Sanctum, no Spatie.** Allowed packages: maatwebsite/excel, barryvdh/laravel-dompdf. vite-plus 0.3.3 (when updating it, update the `vite` alias in package.json to match).
- Databases: `patient_census` (dev), `patient_census_test` (**tests only**). User `census_app`.

### Screens & design
- **Claude Design dropped (2026-10-13).** `prototype/prototype.html` is the final screen reference. Claude Design may be used later only for visual polish after everything works.
- **Encode (6a):** returning-patient search (Trash excluded); editing a returning patient's name/birthdate corrects all their visits; **duplicate-patient guard** (same name + birthdate asks "Use this patient" or "save as new"); Enter saves, Ctrl+Enter saves from Remarks.
- **Records (6b):** Admin only; Visits and Patients tabs; 10 rows per page by default (10 / 20 / 50 dropdown); default range first day of last month to today; filters kept in the URL; Edit opens Encode and returns to the same filters; a closed-month delete shows its message in the row.
- **Lists (6c):** no duplicate names (case-insensitive; ranks per branch); a name in Trash says "Restore it from Trash instead"; **Edit is for spelling only** (renames show on all past visits); **ranks have Move up / Move down** for seniority order (used in Encode and the Branch × Rank report table); **diagnoses alphabetical**, "Other (specify)" last and fixed; age brackets can't overlap; blank To = "and above".
- **Trash (6e):** Admin only, one table of everything in Trash, newest first, 10 per page by default (10 / 20 / 50 dropdown). A deleted month report shows as one row and its visits are not listed separately. Restore is one click; a refusal shows in that row (never a 403 page). Delete permanently and Empty Trash use the inline "type to confirm" row, and the server checks the typed text too. **Age brackets accept `18-25` (hyphen) or `18–25` (en dash)** because the en dash is not on the keyboard; every other type needs an exact match (surrounding spaces ignored). **A row that was already restored or deleted in another window shows "That item is no longer in Trash."** instead of a 404. Empty Trash deletes what is allowed (visits first, so a patient waiting only on its own trashed visit goes too) and says "Deleted N items. M items stay in Trash because they're still needed." A rank name can't clash on restore because the database already blocks a duplicate name in a branch, even in Trash.
- **Shared paging (6e, changed in 6f):** `App\Services\TablePaging`, `components/shared/pagination.tsx` (with `Paged` / `PageMeta` in `types/ui.ts`) and `lib/paging.ts` are used by Records (both tabs), Trash and the Audit log, and by every later long table.
- **Rows per page (2026-10-16): Records, Trash and Audit log: 10 rows per page by default, with a 10 / 20 / 50 dropdown.** The choice is kept in the URL (`?per_page=`), each Records tab keeps its own, changing it goes back to page 1 and keeps the filters, and Edit-return, delete, Restore, Delete permanently, Empty Trash and the last-page redirect keep it. The server accepts only 10, 20 or 50; anything else is 10. The dropdown is hidden when there are 10 rows or fewer in total. The User Accounts list has no paging.
- **Audit log (6f):** Admin only (`view-audit`), read-only, no create/edit/delete routes, and viewing it is not logged. Columns: When, User (username + full name), Action, Record, PC. Filters in the URL: From / To (default first day of last month to today; empty = no limit), User (every account, including deactivated and in Trash), Action (grouped), Record (exact Visit No., Patient ID or username; a permanently deleted record is found by the ID or name saved in its entry). Bad filter values fall back to the defaults (no error page). **Details** shows "Field: old → new" with names instead of IDs and dates as DD-Mon-YYYY; a delete reason shows as saved. Any key containing "password" or "remember_token" is stripped before the page gets the data. Action labels live in one map (`App\Services\AuditActions`); an unknown code shows as itself.
- **Audit log labels (6f):** list items have **separate labels** ("Diagnosis added", "Rank / type added", "Age bracket added") instead of one "List item added", so the Action dropdown has no duplicates. **One Trash action group** holds every "moved to Trash", "restored" and "deleted permanently" action for all record types; the Visits, Patients and Lists groups hold only add and edit actions. A system sign-out is labelled by its reason (e.g. "Signed out (account deactivated)").
- **Audit log detail-line order (6f):** MySQL stores JSON keys in its own order, so the detail lines come in a **fixed order** (known fields in the order of `AuditRows::FIELDS`, the rest by name).
- **Audit log index (6f):** a new migration adds an index on `audit_logs (user_id, created_at)`; `created_at` was already indexed.
- **Security plan:** `planning/security.md`. Every step follows its section 2. HTTPS vs HTTP on the LAN is **still to decide** with hospital IT/CO (Phase 8).
- **Documentation phase 7.5** added: developer guide, database guide, how-to, learning path, operations guide, user manual, keyboard shortcuts (collected in `docs/keyboard-shortcuts.md` as each screen is built).

## 5. Where We Are (2026-10-16)
| Step | Status |
|---|---|
| 1–5, 5b, 5c (foundation, login, RBAC, audit, Trash service, backup, temp passwords) | Done, merged |
| 6a Encode | Done, merged |
| 6b Records (+ delete-reason dropdown, security plan) | Done, merged |
| 6c Lists | Done, merged |
| 6d Reports (6d-1 counts, 6d-2 close/reopen/delete month, 6d-3 PDF/Excel/Print) | Done, merged and pushed (2026-10-16) |
| 6e Trash (restore, delete permanently, Empty Trash) | Done, merged (2026-10-16, not pushed) |
| 6f Audit log (+ rows per page 10 / 20 / 50 on Records, Trash, Audit log) | Done, merged (2026-10-16, not pushed) |
| **6g Backup screen** | **Next** |
| 6h Settings | After 6g |
| Security review (`planning/security.md` section 3) | End of Phase 6 |
| Phase 7 parallel run (1 month beside Google Sheets) · 7.5 Docs · 8 Deploy · 9 Training | Later |

## 6. Notes from Reports (6d, done)
- Screen per prototype "Reports": period type (Monthly / Quarterly / Annual / Custom), period picker, category (All / OPD / ER / Admission), Generate. Admin and Viewer (CO).
- Counting SQL is described in `planning/database-schema.md` (each patient once per category per period, using their latest visit in the period; Trash excluded).
- Sections: totals, sex, age brackets (with a "No bracket" line if an age fits none), diagnosis per visit (every diagnosis by name, no Top 10, no Others line, total equals Visits), Branch × Rank in the Lists seniority order.
- Closed months show the saved snapshot. Close / Reopen / Delete month report are Admin only.
- Exports: PDF (dompdf), Excel (maatwebsite), Print. Same permission as the screen; Excel cells starting with = + - @ written as text.
- Hospital name/logo and signatories come from Settings (6h); use the settings table values / placeholders for now.
- **Agreed 2026-10-15: Reports is split into three prompts**, each built, reviewed and committed on its own: 6d-1 counts on screen (`planning/Prompts/prompt-6d-1.md`, written), 6d-2 close/reopen/delete month, 6d-3 PDF/Excel/Print.
- Tests should use small known data sets and exact expected numbers.
- 6d-3: Print, PDF and Excel must show the full Diagnosis list (the 10-row scroll box is screen only).
- **6d-1 built (2026-10-15):** counting in `App\Services\ReportBuilder` (latest visit per patient per category via NOT EXISTS; grouped SQL). Changed the same day: the Diagnosis table counts every visit and lists every diagnosis (typed Other text merged ignoring case and spaces), and saved copies are `ReportBuilder::VERSION` 3. Month/year lists start at **January 2026** (my decision: `ReportFilters::FIRST_YEAR`); earlier dates only via Custom range. Report header reads settings keys `hospital_name`, `report_office`, `prepared_by`, `prepared_by_title`, `noted_by`, `noted_by_title` (defaults used when empty), so **the Settings screen (6h) must let Admin edit all six**.

## 7. Reviewer Checklist (used on every step)
1. Does it match the prompt item by item, and the prototype?
2. Read the actual controller / request / policy / routes, not just the summary.
3. Role checked twice (route group + Gate/Policy); every form rule repeated on the server.
4. Trash excluded wherever lists or counts appear; closed-month rule respected.
5. No raw SQL with user input; no unsafe HTML; redirects only to known paths.
6. Audit entries come from observers; no passwords logged.
7. Tests cover each role (allowed or 403) and each rule; run only on `patient_census_test`.
8. Flag anything Claude Code decided on its own; say if it's fine or should change.
9. Give the exact "approve / fix" message, then commit → merge → push.

## 8. Phase 8 (deployment) notes
- Server PHP must be **8.3 or newer** (maatwebsite/excel 4 requires it).
- Required PHP extensions include **gd, intl, zip, mbstring, pdo_mysql, fileinfo, openssl**. `gd` is off by default in a fresh `php.ini`; PhpSpreadsheet (Excel export) will not install or run without it.
- The hospital network has **no internet**. Run `composer install --no-dev` and `npm run build` on a dev PC, then copy the `vendor` folder and the built `public/build` assets to the server.
- `storage/` (and `bootstrap/cache/`) must be **writable** by the web server: dompdf font cache, logs and backups live there.
- PDF exports run with remote resources and PHP execution switched off (`isRemoteEnabled` and `isPhpEnabled` false), so the server needs no outside access for them.

## 9. Lessons Learned
- **VS Code can overwrite files** changed from outside (it saved old copies over `project-phases.md` and `prompt-6c.md`). Close a file's tab before someone else edits it; choose **Don't Save** if asked.
- **Tests only on `patient_census_test`.** RefreshDatabase wipes every table; it once ran on the dev database.
- **PowerShell splits commit messages on quotes.** Claude Code writes the message to a temporary file instead.
- Claude Code tends to add useful extras (e.g. the redirect fix in 6b). Accept them only after review.
