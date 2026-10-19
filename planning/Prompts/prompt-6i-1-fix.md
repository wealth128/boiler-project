# Prompt 6i-1-fix for Claude Code: Fixes from the second code review

_Paste after 6i-1 is merged and pushed, before 6i-2._

```
Step 6i-1-fix – fixes from the second code review. Create branch step-6i-1-fix.
1. "Remember me" is off: the login page has no checkbox, but a hand-made login POST with remember=1 gets a long-lived cookie that survives a password reset and the 8 PM sign-out. Always log in with remember = false (ignore the field). Also change remember_token (Str::random(60)) in User::setTemporaryPassword and whenever a user changes their own password. Tests: login with remember=1 sets no recaller cookie; reset changes remember_token; an old recaller cookie no longer logs in after a reset.
2. Restore checks everything before dropping the database: before DROP DATABASE, (a) open and read the dump file, (b) check its last lines contain mysqldump's "-- Dump completed" line (refuse a cut-off file: "This backup file is incomplete. Choose another one."), (c) find the mysql client and run "mysql --version" (if not found, ask for its path, the same way install asks for mysqldump), (d) check the picture zip opens if present. Only then take the safety backup, go down and drop. Tests for each refusal (nothing dropped, site stays up).
3. Install never runs over a live system by mistake: if .env exists with APP_KEY set, or the patient_census database already exists, refuse with "This server already has the system installed. Use census:install --repair, or census:restore." even when the database login fails. Only --force (with a typed confirmation INSTALL OVER) allows it. Tests.
4. After a restore, also clear the cache and cache_locks tables (as well as sessions), so old backup locks and the old scheduler heartbeat don't come back. Test.
5. Month close/reopen lock per year: use one lock "month-close:YYYY" in both close and reopen, so two months of the same quarter closed at the same time still create the quarter/year snapshot, and a reopen can't race a close. Test.
6. Duplicate-patient lock key: build it the way MySQL compares names (case- and accent-insensitive, e.g. Str::ascii + lowercase + collapsed spaces), so "Peña" and "Pena" share one lock. Test.
7. planning/project-history.md section 0: fix the stale lines ("not pushed yet", "Prompt 6i-2 not written yet": it is in planning/Prompts/prompt-6i.md).
When I approve, before the commit, update planning/project-history.md (section 0, section 5, decisions log: remember-me off) and planning/security.md.
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize. Don't commit until I say okay.
```
