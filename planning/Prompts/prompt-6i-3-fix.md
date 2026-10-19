# Prompt 6i-3-fix for Claude Code: Close button (x) on warning bars + fixes from the 6i-3 code review

_Paste while 6i-3 is still uncommitted on `step-6i-3-backup-care`. The same branch, then approve all of 6i-3._

```
Before I approve 6i-3 (stay on step-6i-3-backup-care), two parts.

PART A – Close button (x) on every warning bar, so nothing blocks the view
Applies to the Admin bars: SchedulerAlert (components/shared/scheduler-alert.tsx) and every BackupAlerts bar (components/shared/backup-alerts.tsx), including the new test restore / USB bars.
1. Add an "x" button at the right of each bar (a real <button>, aria-label "Close this warning", keyboard focusable, visible focus ring). Keep each bar one line where it fits.
2. Closing only HIDES the bar. It never marks, fixes or changes anything on the server.
3. When it comes back:
   - Yellow (warn) bars: hidden until the next day (Asia/Manila date).
   - Red (error) bars, including the scheduler bar: hidden until the user's next sign-in. For this, share a value that changes on every login (e.g. auth.loginAt = the session's login time, set when the user logs in) and store it with the dismissal.
   - Any bar comes back right away if its message text changes (e.g. a new failure or a new date).
4. Remember dismissals per user in the browser (localStorage key including the user id and a hash of the message). Wrap every read/write in try/catch; if storage fails, the bar simply shows. Clear stale entries (older than 7 days) when writing.
5. The Backups page and the System health page always show the full status, with no x.
6. Make it one shared component (e.g. components/shared/dismissible-alert.tsx) used by both bars.
7. Add to docs/keyboard-shortcuts.md (Tab to the x, Enter/Space closes) and a short line in docs/operations-guide.md and the custodian section: "The x only hides a warning. It comes back tomorrow (yellow) or at your next sign-in (red) until the problem is fixed."
8. Tests: Pest for the loginAt value (changes on a new login, shared only when signed in); for the client logic, put the "should this bar show?" rule in a small pure function (lib/alert-dismissal.ts) and list the browser checks in the summary (there is no JS test runner; don't add one).

PART B – Fixes from the 6i-3 code review
1. Count mismatch false failures (BackupService ~98-104): counts are taken before mysqldump, outside its snapshot, so an encode during the backup makes a good backup fail its test restore. Count again right after the dump; if both counts match save them, otherwise save the min and max per item and let the test restore's compare() accept any value between them. Test.
2. USB copy safety (UsbCopyService ~209-213, 276-279): copy each file to "<name>.part", verify the size, then rename to the final name. Never overwrite an existing good copy before the new one is verified. Ignore and delete leftover *.part files in census-backups. Don't count .part files among the 8 kept. Tests.
3. Throttle POST backups/run, backups/test-restore and backups/usb-copy (e.g. 6 per minute per user), with the friendly 429 message.
4. Lock vs run time (TestRestoreService ~59): make the lock last longer than the longest possible run (load timeout + counting + unzip + margin), or refresh it during the run. Same check for the backup lock.
5. USB copy with no successful backup: write the backup.usb_copy audit entry with the error too.
6. Add integer casts for the count columns on BackupRun (and TestRestoreRun if it has counts).
7. MySQL login temp file (MysqlAdmin ~296-304): on start of each run, delete any leftover census login files older than 1 hour in the temp folder (only files matching our own name pattern).

Then do item 6 of the original 6i-3 prompt (update planning/project-history.md section 0, section 5 and the decisions log: close button rules, count range, .part copies; planning/architecture.md section 4; planning/security.md; planning/deployment-handover.md maintenance calendar: monthly test restore is automatic, weekly USB copy is a button).
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize, with browser test steps for the x (yellow hides until tomorrow, red until next sign-in, comes back on a new message, Backups and System health pages unchanged). Don't commit until I say okay.
```
