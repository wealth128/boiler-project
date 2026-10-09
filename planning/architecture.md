# Architecture & Tech Stack (Phase 3): AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-07 · Status: Draft for review_

## 1. Decisions
| Area | Choice |
|---|---|
| Structure | **Monolith**: one Laravel app with React pages through **Inertia.js** (Laravel's official React starter kit) |
| Backend | Laravel (PHP), MVC |
| Frontend | React + Tailwind. Screens are built from the Claude Design output. |
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
| 2. Backup file | Second PC on the network | **Daily**, automatic (`mysqldump` via the Laravel scheduler), keep 30 days |
| 3. Backup file | External USB drive, stored in a different room or locked cabinet | **Weekly**, plugged in and copied by Admin |

- Data is lost only if **all three** fail at the same time.
- If the backup fails, it shows on the Admin screen.
- **Test restore once a month** onto a spare PC to prove the backups work.

## 5. Server PC Setup (outline, detailed in Phase 8)
1. Install Laragon, then copy the app and run the setup commands.
2. Give the server PC a **fixed IP address** and allow port 80 through Windows Firewall.
3. Set Laragon to start automatically with Windows. The PC stays on 24/7.
4. Add a Windows Task Scheduler entry that runs Laravel's scheduler every minute (this drives the daily backup).

## 6. Open
- None. Dropdown lists are dynamic (add, edit, remove, restore); closed months keep a saved report copy.
