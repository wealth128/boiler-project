# Prompt 5b for Claude Code: close the open items and review fixes

_Paste the block below into Claude Code after Step 5 is committed and merged into main._

```
Close the open items from Step 5 and the general review. Rules are in planning/rbac.md and planning/database-schema.md.

1. Temporary password must be changed: when Admin or System Admin creates an account or resets a password, mark it temporary (must_change_password boolean on users, default false). After logging in with it, the user can only reach a "Choose your new password" page (and Log out) until they set their own. The new password can't be the same as the temporary one. Log user.password_changed. census:reset-admin-password also makes the Admin's new password temporary.
2. Sign out removed access right away: on every request, if the signed-in user has been deactivated, locked or moved to Trash, end the session and send them to login with the matching message (same wording as the login messages). Log user.session_ended with the reason.
3. planning/rbac.md: replace "No idle auto-logout." with the daily 8:00 PM session cutoff rule (no idle timeout; everyone signed in before 8:00 PM Asia/Manila logs in again on their next click; ER night shift simply logs in again). Add items 1, 2, 4 and 6 to "Rules for Everyone".
4. Password rule (works offline): remove ->uncompromised() (the hospital network has no internet). Same rule in every environment: at least 8 characters, with letters and numbers. No symbol requirement.
5. Patient and Trash: a patient can be moved to Trash only when none of their visits are active (all their visits are already in Trash). Message: "Move this patient's visits to Trash first." Restoring a visit whose patient is in Trash is blocked: "Restore the patient first." (This matches prototype/prototype.html.)
6. Only one active System Admin, enforced the same way as the one-Admin rule.
7. Logging: switch to one log file per day, kept 30 days (LOG_STACK=daily, LOG_DAILY_DAYS=30 in .env and .env.example).
8. Run npm audit fix (developer-only package). Don't use --force. Tell me if anything remains.
Pest tests for 1, 2, 4, 5 and 6. Run php artisan test (patient_census_test only), npm run types:check and npm run build. Fix errors. Stop and summarize.
```

**Not in this prompt on purpose:**
- Server settings (`APP_ENV=production`, `APP_DEBUG=false`, `BACKUP_PATH`, Task Scheduler, folder permissions): Phase 8 checklist.
- HTTPS on the hospital network: decide in Phase 8.
- Logging changes to hospital name and signatories: built with the Settings screen in Phase 6.
