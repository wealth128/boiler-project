# Project History & Handover: AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-18 (Step 6d-4 done) · Read this first in any new Claude conversation about this project._

This file carries over what was discussed in the first planning-and-review conversation (Oct 3–15, 2026), so a new conversation can continue without starting over. The official rules are still in `CLAUDE.md` and the other `planning/` files. This file explains **what was decided, why, where we are, and how to work with me.**

---

## 0. Quick Start (read this first; it may be all you need)
| | |
|---|---|
| **Current step** | 6d-4 Reports split by category is done and merged (2026-10-18; 6h Settings is also done) |
| **Next step** | **Prompt 6i: Self-setup and self-care** (includes adding the logo and signature files in `storage/app/private` to the daily backup), then the Security review (`planning/security.md` section 3). Start a **new conversation** for each step. |
| **Next prompt file** | Prompt 6i (not written yet). Last prompt: `planning/Prompts/prompt-6h.md` |
| **Last merged commit** | Step 6d-4 Reports split by category merge into main (2026-10-18, not pushed yet; hash: see `git log -1`) |
| **Open decisions** | HTTPS vs HTTP on the hospital LAN (Phase 8). The logo and signature files are not backed up yet (fix in 6i, see section 8). |

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
- **Backup screen (6g, decided 2026-10-16):**
  - **Red warning** when the last run failed, or there is no successful backup in the last **26 hours** (daily run plus a 2-hour margin). It shows on the Backups page in full, and as a short line with a link at the top of every Admin page. Never shown to other roles.
  - **No download and no restore from the browser.** Backup files hold all patient data, so they stay in the backup folder and IT copies them (weekly USB copy, monthly test restore).
  - **USB copy and test restore marks** (new table `backup_marks`: who, when). Admin marks each with a confirm step; each mark is audited. A yellow warning shows on every Admin page when no USB copy was marked in **8 days** or no test restore in **35 days** (never marked counts as overdue). They are **reminders only and never block any work.**
  - The warning banner is **hidden on /backups** itself, because the status panel shows the same warnings in full.
  - **Run backup now** runs `BackupService` once behind a lock shared with the scheduler and `backup:run` ("A backup is already running."). It writes the audit entry `backup.run_manual`.
  - Error text on screen hides the database password and login. `mysqldump` gets `SystemRoot` explicitly on Windows, or it fails with "Can't create TCP/IP socket (10106)" when started from the web server.

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
- **Settings (6h, built 2026-10-18):** `GET/PUT /settings/hospital`, Admin only (`manage-settings` Gate plus `role:admin`); Encoder, Viewer and System Admin get 403. Sidebar item "Hospital settings". One form saves the six report texts (Hospital name 2–150 characters, Office line 150, names and titles 100; text is trimmed; an empty office line or title is saved as its default) plus the logo and two signature pictures. Every save writes `settings.updated` with old → new for each changed field only; picture changes write `settings.logo_changed`, `settings.signature_changed`, `settings.logo_removed`, `settings.signature_removed` (no image data). Labels are in `AuditActions` (new "Settings" group). The log subject is the signed-in Admin, because settings keys are words, not IDs.
- **Pictures (6h):** the logo and each signature are optional PNG or JPG, 1 MB at most and 1000 × 1000 px at most. The real file type is checked (SVG and renamed files are refused), and the server **saves the picture again** with GD, so anything hidden in the file is dropped. Stored on the private `local` disk under fixed names (never the uploaded name), outside `public/`, and served only by signed-in routes. **The Viewer (CO) may load the pictures** because the Reports screen shows them (my decision, instead of the 403 first asked for); Encoder and System Admin get 403. Each picture has a Remove button (logo: `settings.logo_removed`; signature: `settings.signature_removed`). A file too big for the server shows a friendly "File too large" page (413) and the browser checks the size first.
- **Crop before upload (6h): built-in canvas cropper, no package** (`react-easy-crop` was tried and removed on 2026-10-18; do not add a crop library). Drag, zoom slider, Rotate 90°, preview, Save, Cancel, Esc, arrow keys to move and + / - to zoom. Logo crop is square (saved 600 × 600), signature crop is a 3:1 strip (900 × 300). Signatures have a **"Remove white background"** checkbox (near-white pixels become transparent, saved as PNG). If the PNG would be over 1 MB it is drawn smaller, as a last step saved as JPG.
- **Signature pictures only on final reports (6h):** a closed month, and a quarterly or annual report only when every month in it is closed (screen, Print and PDF). Open months and custom ranges show names and titles over a blank signature line with a small note, "Not final – month still open" or "Not final – custom range" (the note shows even when no picture is uploaded). **Excel never has pictures.** The live preview on Hospital settings always shows the pictures.
- **Signature block frozen at close (6h):** closing a month saves the Prepared by / Noted by names and titles and copies of the two signature pictures with the month's snapshot (`report_snapshots.signature_block`, pictures in `storage/app/private/frozen-signatures/{period}/`). The closed month's screen, Print, PDF and Excel names use the frozen copy, even after Settings change. Reopening removes the copy with the snapshot; closing again freezes the new block. Months closed before this change, and quarterly and annual reports, use the current settings. Report numbers are not touched. Frozen pictures are served by `GET /reports/signatures/{period}/{who}` (Admin and Viewer only). Details are in `planning/database-schema.md`.
- **Sidebar (6h):** the "ADMINISTRATION" heading is a collapsible group (button with an arrow icon, Enter / Space, `aria-expanded`). Open or closed is remembered per browser in localStorage (default open, wrapped in try/catch) and the group opens by itself when you are on one of its pages. The app has no icon-only sidebar; on a narrow screen the menu is one row with no headings, so all links are always shown there.
- **Button visibility (6h):** Log out in the sidebar is a red outlined button, the Encode "Clear" button and the logo file button are solid or outlined in navy, so they read as clickable.
- **Reports split by category (6d-4, built 2026-10-18): display only.** The Reports screen has tabs **OPD | ER | Admission | Summary**, one at a time. Nothing is counted differently and the saved month snapshot format is unchanged: the report always holds every category and the tab only chooses what is shown, so the numbers are the same whichever tab is open (tests check this for open and closed months). **Default tab is ER.** The tab is kept in the URL with the period filters (`?category=er`, any letter case); a bad value, an old `?category=` (the former "All") or an array falls back to ER. **The Category dropdown was removed** from the filter panel (the tabs replace it); Generate keeps the open tab. An empty category says "No visits encoded for <category> in this period." Summary holds the grand totals and each category's patients. Saved copies are shown with categories in the usual order (OPD, ER, Admission), because MySQL JSON reorders keys (order only, never a number).
- **Print and PDF (6d-4):** a choice **"This category"** (the open tab; "This page (Summary)" on the Summary tab) or **"All categories"** (`?scope=all`): the Summary and then OPD, ER and Admission, **each on its own page with its own header and signature block**, following the same signature rules as 6h (pictures only when final, "Not final" note otherwise, frozen block for a closed month). **Print opens a print view in a new tab** (`GET /reports/print`, Admin and Viewer, `ReportPrintController` and `pages/reports/print.tsx`) and starts the browser's Print dialog there; Ctrl+P on the Reports screen still prints the open tab. **Excel always has every sheet** (Summary and one per category) whichever tab is open, and its file name ends in `_all`; PDF names end in the tab (`_er`, `_summary`) or `_all`. **`report.exported` records the tab ("OPD", "ER", "Admission", "Summary") or "All"** (every-page PDF, and Excel). Tabs use arrow keys / Home / End to move focus and Enter or Space to open (see `docs/keyboard-shortcuts.md`).
- **Security plan:** `planning/security.md`. Every step follows its section 2. HTTPS vs HTTP on the LAN is **still to decide** with hospital IT/CO (Phase 8).
- **Documentation phase 7.5** added: developer guide, database guide, how-to, learning path, operations guide, user manual, keyboard shortcuts (collected in `docs/keyboard-shortcuts.md` as each screen is built).

## 5. Where We Are (2026-10-18)
| Step | Status |
|---|---|
| 1–5, 5b, 5c (foundation, login, RBAC, audit, Trash service, backup, temp passwords) | Done, merged |
| 6a Encode | Done, merged |
| 6b Records (+ delete-reason dropdown, security plan) | Done, merged |
| 6c Lists | Done, merged |
| 6d Reports (6d-1 counts, 6d-2 close/reopen/delete month, 6d-3 PDF/Excel/Print) | Done, merged and pushed (2026-10-16) |
| 6e Trash (restore, delete permanently, Empty Trash) | Done, merged (2026-10-16, not pushed) |
| 6f Audit log (+ rows per page 10 / 20 / 50 on Records, Trash, Audit log) | Done, merged (2026-10-16, not pushed) |
| 6g Backup screen (status, history, run now, USB copy / test restore reminders) | Done, merged (2026-10-16, not pushed) |
| 6h Settings (hospital name, office line, signatories, logo, signature pictures; crop; frozen signature block) | Done, merged (2026-10-18, not pushed) |
| 6d-4 Reports split by category (OPD / ER / Admission / Summary tabs; Print / PDF this category or all; Excel all sheets) | Done, merged (2026-10-18, not pushed) |
| **6i Self-setup and self-care** (includes backing up the logo and signature files) | **Next** |
| Security review (`planning/security.md` section 3) | End of Phase 6 |
| Phase 7 parallel run (1 month beside Google Sheets) · 7.5 Docs · 8 Deploy · 9 Training | Later |

## 6. Notes from Reports (6d, done)
- Screen per prototype "Reports": period type (Monthly / Quarterly / Annual / Custom), period picker, category (All / OPD / ER / Admission), Generate. Admin and Viewer (CO).
- Counting SQL is described in `planning/database-schema.md` (each patient once per category per period, using their latest visit in the period; Trash excluded).
- Sections: totals, sex, age brackets (with a "No bracket" line if an age fits none), diagnosis per visit (every diagnosis by name, no Top 10, no Others line, total equals Visits), Branch × Rank in the Lists seniority order.
- Closed months show the saved snapshot. Close / Reopen / Delete month report are Admin only.
- Exports: PDF (dompdf), Excel (maatwebsite), Print. Same permission as the screen; Excel cells starting with = + - @ written as text.
- Hospital name/logo and signatories come from Settings (6h, done): see the 6h notes in section 4.
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
- **Set `'serve' => false` in `config/filesystems.php`** (the `local` disk) so Laravel's `storage/{path}` route is not registered (also in `planning/security.md` section 4). Backups are not under that disk, but the route should not exist on the server.
- **PHP upload limits:** set `upload_max_filesize` and `post_max_size` to **at least 2 MB** (the default `upload_max_filesize` is 2M, `post_max_size` 8M). The logo and signature pictures are saved at 1 MB at most; a smaller limit would refuse a valid picture.
- **Backups cover the database only (open item, fix in 6i):** the logo and signature picture files in `storage/app/private` (`logo.*`, `signature-*.*` and the `frozen-signatures/` folder) are **not backed up yet**. If that folder is lost, the database still names the files but the pictures are gone. In 6i, include that folder in the daily backup (and in the restore notes).
- **Backup folder:** set `BACKUP_PATH` in `.env` to the shared folder on the **second PC** (e.g. `//BACKUP-PC/census-backups`, forward slashes). The default `storage/app/backups` is on the same disk as the database, so it is for testing only. The server's Windows account needs write access, and `MYSQLDUMP_PATH` must point to the real `mysqldump.exe`.

## 9. Lessons Learned
- **VS Code can overwrite files** changed from outside (it saved old copies over `project-phases.md` and `prompt-6c.md`). Close a file's tab before someone else edits it; choose **Don't Save** if asked.
- **Tests only on `patient_census_test`.** RefreshDatabase wipes every table; it once ran on the dev database.
- **PowerShell splits commit messages on quotes.** Claude Code writes the message to a temporary file instead.
- Claude Code tends to add useful extras (e.g. the redirect fix in 6b). Accept them only after review.
