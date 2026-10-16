# Prompt 6g for Claude Code: Backup screen

_Paste after 6f is merged and pushed._

```
Step 6g – Backup screen (Admin only). Create branch step-6g-backups. There is no Backup screen in prototype/prototype.html, so follow the style of the Trash and Audit log pages (sidebar page, panel, table, shared pagination with the 10 / 20 / 50 rows-per-page dropdown). Rules: planning/architecture.md section 4 (backup plan), planning/database-schema.md (backup_runs), planning/security.md (sections 2 and 4, Backups), planning/rbac.md. Reuse the existing App\Services\BackupService and the backup:run command; don't duplicate their logic. Routes from planning/setup-and-structure.md: GET /backups, POST /backups/run.
1. Navigation: "Backups" in the sidebar for Admin only, like Trash and Audit log.
2. Status panel at the top: last successful backup (DD-Mon-YYYY HH:mm, file name, size in MB), the next scheduled run (daily 02:00), how many days of backups are kept (30), and the backup folder path from BACKUP_PATH. If the latest run failed, or there has been no successful backup in the last 26 hours, show a red warning: "The last backup failed on <date time>: <short error>. Tell IT." or "No successful backup since <date time>. Tell IT."
3. The same warning (short version, with a link to /backups) shows at the top of every page for Admin only, so a failed backup isn't missed.
4. History table: every backup_runs row, newest first: Started, Finished, Status (Success / Failed), File, Size, and the error message for failed runs (escaped by React). Paged with the shared pagination (default 10, dropdown 10 / 20 / 50, kept in the URL).
5. "Run backup now" button with a confirm step ("Run a backup now? It may take a minute."). It runs BackupService once; a lock prevents two backups running at the same time ("A backup is already running."). Afterwards show "Backup finished: <file>, <size> MB" or "Backup failed: <short error>". Write an audit entry "backup.run_manual" with the result.
6. No download and no restore from the browser: backup files contain all patient data, so they stay in the backup folder and are copied by IT (weekly USB copy, monthly test restore, as in architecture.md). Show this as a short hint under the status panel, with the reminder: "Copy the latest backup to the USB drive every week. Test a restore once a month."
7. Server: role:admin plus the view-backups Gate on both routes; encoder, viewer and system_admin get 403. Never show passwords or the database connection details on the page.
8. Add any new keyboard shortcuts to docs/keyboard-shortcuts.md (or note that there are none).
9. Pest tests (fake mysqldump, as the existing backup tests do): status panel shows the last success; the warning appears when the last run failed and when there is no success in 26 hours, and not otherwise; the Admin-wide warning shows on another page for Admin only; Run backup now records a run and an audit entry, success and failure; the lock refuses a second run; history paging and per_page; no route serves a backup file; encoder, viewer and system_admin get 403.
10. When I approve, before the commit, update planning/project-history.md: section 0 (Quick Start), section 5 (status table) and the decisions log for anything newly decided.
Run php artisan test (patient_census_test only), npm run types:check and npm run build. Fix errors. Stop and summarize, with steps to test it in the browser. Don't commit until I say okay.
```
