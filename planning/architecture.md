# Architecture & Tech Stack (Phase 3): AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-07 · Status: Draft for review_

## 1. Decisions
| Area | Choice |
|---|---|
| Structure | **Monolith**: one Laravel app with React pages through **Inertia.js** (Laravel's official React starter kit) |
| Backend | Laravel (PHP), MVC |
| Frontend | React + Tailwind. Screens are built by Claude Code to match prototype/prototype.html. |
| Database | MySQL |
| Login | Laravel session login from the starter kit. Public sign-up is turned off: Admin or System Admin creates accounts. Sanctum is not needed. |
| Roles / RBAC | A `role` column on `users` plus Laravel **Gates, Policies and route middleware** (no extra package) |
| Account lock | Custom `failed_attempts` and `locked_at` fields. The account locks at 5 wrong passwords until unlocked. |
| Audit log | Our own `audit_logs` table, written on every encode, edit, delete, restore, permanent delete, close/reopen, unlock and list change |
| Excel export | `maatwebsite/excel` |
| PDF export | `barryvdh/laravel-dompdf` |
| Server software | **Laragon** on one Windows PC (PHP + MySQL + web server) |

## 2. How It Fits Together
```
Office PCs (browser)  ──LAN──▶  Server PC (Laragon)
                                 ├─ Laravel app (routes → controllers → models)
                                 │    └─ React pages via Inertia
                                 ├─ MySQL database (live data)
                                 └─ Scheduled backup ──▶ Second PC + External drive
```
- Office PCs open `http://<server-ip>/` and need nothing installed.
- No internet connection is needed or used.

## 3. Data Flow
**Encoding:**
1. The encoder submits the form.
2. Laravel checks login and role (middleware), then validates the fields (Form Request).
3. In one database transaction, it saves the patient (if new) and the visit, and writes an audit log entry.
4. The page returns with "Saved" and today's entries list updated.

**Reports:**
1. Admin or CO picks a period and category.
2. Laravel runs count queries on visits, excluding deleted (Trash) records, filtered by period and category.
3. The result shows on screen, or is exported to PDF or Excel.

## 4. Backup Plan (3 copies, 2 other places)
| Copy | Where | How often |
|---|---|---|
| 1. Live database | Server PC (MySQL) | Always |
| 2. Backup files | Second PC on the network | **Daily**, automatic (via the Laravel scheduler), keep 30 days. Each backup is **two files with the same date stamp**: the database dump (`patient_census_YYYY-MM-DD_HHMMSS.sql`, from `mysqldump`) and the picture zip (`patient_census_files_YYYY-MM-DD_HHMMSS.zip`: logo, signatures, frozen signature folders from `storage/app/private`) |
| 3. Backup files | External USB drive, stored in a different room or locked cabinet | **Weekly**: Admin plugs the USB into the **server PC** and clicks **Copy to USB** on the Backups page. The server copies both files itself (nothing is downloaded to the office PC) |

- Data is lost only if **all three** fail at the same time.
- A backup counts as **Success only if both files were written**. If it fails, it shows on the Admin screen.
- The scheduler saves a "last ran" time every minute. If it is more than 5 minutes old, Admin sees a red bar (no backup and no 8 PM sign-out would happen). The **System health** page (Admin) and `php artisan census:health` check backups, disk space, the scheduler and the server settings.
- Restore with `php artisan census:restore <file>`: it takes a safety backup first, loads the dump and unzips the matching picture zip.
- **Test restore, automatic, once a month** (Step 6i-3). On the 1st at 03:00 (after the 02:00 backup) the scheduler runs `backup:test-restore`: it checks the latest dump is complete and its picture zip opens, loads the dump into a scratch database `patient_census_restore_test` (never the live one), compares the patient, visit, user and closed-month counts with the **range** saved on the backup run, opens the picture zip in a temp folder, then always drops the scratch database and deletes the temp folder. A pass records the "Test restore" mark by "System (automatic)" (clears the 35-day reminder). A failure is kept in `test_restore_runs` and shows a red bar for Admin (every page, the Backups page and System health) until a later test passes. Admin can also click **Run test restore now** (confirm step). A restore on a spare PC is now only practice (once a year, or when moving servers).
  - **Counts as a range:** a backup counts the four items just before and just after `mysqldump` and saves the lowest and highest per item (`*_count`, `*_count_max`), because `mysqldump --single-transaction` reads one snapshot somewhere in between. The restored count passes if it lies in the range. A backup made before this existed has no counts: the test says so and asks for a new backup.
  - **Account:** `census_restore_test@localhost` has ALL on `patient_census_restore_test.*` only (created by `census:install`, or by `census:install --repair` on older servers; its password is `RESTORE_TEST_PASSWORD` in `.env`). The scratch database name is fixed; `MysqlAdmin` refuses any other name.
- **Copy to USB** (Step 6i-3). The server lists Windows **removable** drives plugged into itself (never network, system or the backup drive), Admin picks one and confirms. It copies the latest successful backup (`.sql` and picture `.zip`) to `census-backups` on the drive: each file goes to `<name>.part`, its size is checked, and only when both are good they are renamed (an existing good copy is never overwritten before the new one is verified; leftover `.part` files are deleted). Then only the newest **8** backups stay on the USB (older census backup files in that folder only). Success records the "USB copy" mark. Failures (no drive, drive full, size mismatch) show a plain message and record no mark; every try is audited.
- **One at a time:** Run backup now, Run test restore now, the 03:00 test and Copy to USB share the backup lock (`BackupService::LOCK`, held at most `LOCK_SECONDS` = 2 × the 1-hour program timeout, so a crash frees it). The three buttons share a limit of 6 a minute per user (429 "Too many requests, wait a minute").

## 5. Server PC Setup (outline, detailed in Phase 8)
1. Install Laragon, then copy the app and run the setup commands.
2. Give the server PC a **fixed IP address** and allow port 80 through Windows Firewall.
3. Set Laragon to start automatically with Windows. The PC stays on 24/7.
4. Add a Windows Task Scheduler entry that runs Laravel's scheduler every minute (this drives the daily backup).

## 6. Open
- None. Dropdown lists are dynamic (add, edit, remove, restore); closed months keep a saved report copy.
