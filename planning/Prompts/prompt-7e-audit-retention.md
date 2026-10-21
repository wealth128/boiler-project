# Prompt 7e for Claude Code: Audit log retention (1 year)

_Paste after 7d is merged and pushed. Decided 2026-10-21: audit entries older than 1 year are deleted automatically. Recommended (keep unless I say otherwise): an archive file is written first._

```
Step 7e – Audit log retention of 1 year. Create branch step-7e-audit-retention. This changes the rule "the audit log can't be edited or deleted": nobody can delete entries by hand; only this automatic job removes entries older than 1 year.
1. Scheduled job (php artisan census:prune-audit), daily at 04:30 Asia/Manila, withoutOverlapping, using the backup lock so it never runs during a backup or test restore:
   a. Select audit_logs older than 365 days (config census.audit.retention_days = 365; not editable in the UI).
   b. FIRST write them to an archive file in BACKUP_PATH: audit-archive_YYYY-MM-DD.csv.gz (UTF-8, all columns, details as JSON, text never starting with = + - @). Verify the file (row count) before deleting.
   c. Then delete exactly those rows in chunks of 1,000 inside transactions.
   d. Write one audit entry "audit.pruned" (count, oldest and newest date removed, archive file name). This entry itself is subject to the same 1-year rule later.
   e. If writing the archive fails, delete nothing and show a red Admin bar ("Audit log clean-up failed: <short error>") until a later run succeeds.
2. Archive files are kept (they are small); the 30-day backup cleanup must NOT delete audit-archive_* files. Copy to USB also copies the newest archive file.
3. Audit log page: a short note under the title "Entries older than 1 year are removed automatically (archived to the backup folder)." The From filter cannot go older than the retention date.
4. System health: a check "Audit log clean-up" (OK when it ran in the last 2 days, Problem when the last run failed).
5. Data Privacy note: add to planning/deployment-handover.md and docs/operations-guide.md: "Confirm the 1-year audit retention with the hospital's Data Protection Officer; the archive files keep older entries if needed."
6. Tests: entries older than 365 days archived then deleted, newer kept; archive written before delete; failure deletes nothing and shows the bar; backup cleanup keeps archives; audit.pruned written; no manual delete route exists; lock respected.
7. When I approve, before the commit, update planning/project-history.md (section 0, section 5, decisions log), planning/security.md (section 1: audit retention), planning/architecture.md (backups section: archive files) and planning/rbac.md if needed.
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize. Don't commit until I say okay.
```
