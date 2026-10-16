# Prompt 6x for Claude Code: Fixes from the code review

_Paste after 6d-4 is merged and pushed. Decisions: quarterly/annual reports get their own saved copy when all their months are closed (Option A); the login "tries left" message stays._

```
Step 6x – fixes from a code review. Create branch step-6x-review-fixes.
1. Password reset ends other sessions: when Admin/System Admin resets a user's password (UserAccountController::resetPassword) and when census:reset-admin-password runs, delete that user's sessions (database session driver). When a user changes their own password (Settings > Security or Choose your new password), end their other sessions but keep the current one. Audit as today.
2. Close month race: in MonthService::close, re-check "already closed" inside the transaction with a row lock (or an atomic lock per period), and only release the frozen signature folder if this request created it. A second Close on the same month shows "<Month> is already closed." and leaves the saved copy and pictures untouched.
3. Freeze quarterly and annual reports: when closing a month makes all months of its quarter (or year) closed, also save a snapshot of that quarter (and year), with its signature block frozen at that time. Quarterly/annual reports use the snapshot when it exists; reopening any month in it removes that quarter/year snapshot (and its frozen pictures). If no snapshot exists (some month open, or older data), count live and show the "Not final" note with no signature pictures. Don't change any counting rules.
4. Add Gate::authorize('encode-visits') to EncodeController::index and PatientSearchController.
5. Add throttle to GET reports/export/pdf, reports/export/excel, reports/print (e.g. 20 per minute) and patients/search (e.g. 60 per minute), with a friendly "Too many requests, wait a minute" message.
6. Typed "Other" rank: clean spaces and merge with a matching listed rank in the same branch, ignoring case and spaces, the same way diagnoses do.
7. Same new patient saved at the same moment by two encoders: prevent the duplicate (lock or recheck inside the save transaction) and show the existing duplicate message.
Pest tests for each: reset ends the user's sessions; own password change keeps the current session and ends others; double close keeps pictures; quarter snapshot created when its last month closes, removed on reopen, numbers unchanged after a patient correction; quarter with an open month shows Not final; 403 from the Gate on encode page/search for viewer and system_admin; throttle returns 429; rank merge; duplicate patient.
When I approve, before the commit, update planning/project-history.md (section 0, section 5, decisions log) and planning/security.md if relevant.
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize, with browser test steps. Don't commit until I say okay.
```
