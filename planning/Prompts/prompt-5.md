# Prompt 5 for Claude Code

_Paste the block below into Claude Code._

## Prompt 5: Finish the Phase 5 foundation
```
Step 5 – Finish the foundation (planning/phase5-foundation.md steps 7, 8, 10). No new screens yet.
1. Audit observers (app/Observers): Visit, Patient, User, Diagnosis, Rank, AgeBracket, MonthClosure write created / updated (with old and new values) / deleted / restored / force-deleted entries through App\Services\AuditLogger. Never log password values. Wrong-password counter updates must stay out of the log (they use plain queries already).
2. App\Services\TrashService: move to Trash (reason required), restore, delete permanently, empty Trash, and "Delete month report" (moves all visits of an open month to Trash as one MonthDeletion). Use the existing TrashPolicy rules for when permanent delete is blocked. Closing a month saves a ReportSnapshot; reopening removes it (ReportBuilder can be a stub that returns counts for now).
3. Backup: App\Services\BackupService + command `php artisan backup:run`. Runs mysqldump (path from .env: MYSQLDUMP_PATH), saves to BACKUP_PATH (from .env), keeps the last 30 days, records each run in backup_runs (success or failed with the error). Schedule it daily at 02:00 in routes/console.php. Tests must not call the real mysqldump (fake it).
4. Protect the Admin account (planning/rbac.md): System Admin cannot reset the password of, deactivate, edit or change the role of the Admin. Nobody can change their own role. Add `php artisan census:reset-admin-password` for IT on the server (asks for the new password, writes user.password_reset to the audit log). Update planning/rbac.md "Rules for Everyone" with these rules.
5. Pest tests for all of the above.
Run php artisan test (patient_census_test only), npm run types:check and npm run build. Fix errors. Stop and summarize.
```

---
**After Prompt 5:** Phase 5 is done. Next is designing the final screens in Claude Design (Dropped 2026-10-13: screens are built by Claude Code to match prototype/prototype.html.) (Phase 2), then building the features (Phase 6).
