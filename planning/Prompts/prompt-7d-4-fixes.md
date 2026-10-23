# Prompt 7d-4 for Claude Code: Fixes from the general check (after 7d-3)

_Paste on main after 7d-3 is pushed, before 7e._

```
Step 7d-4 – Fixes from the general check. Create branch step-7d-4-fixes. Don't change any report number or rule.
1. Report cache stamp (ReportCache::stamp, ~line 70): CHECKSUM TABLE reads every row of 8 tables on every report request and briefly locks them, so encoders wait while someone opens a report, and it gets slower every year. Replace it with a cheap stamp: a single "report data version" counter (e.g. a row in settings or the cache) bumped by model events on Visit, Patient, Branch, Rank, Diagnosis, AgeBracket, MonthClosure, ReportSnapshot and ImportBatch (create, update, delete, restore, force delete), and by census:restore, import confirm/undo and any bulk update that skips model events. Reading it must be one tiny query. Keep the existing behaviour: a change anywhere means a fresh report; restore still empties the cache. Tests: each kind of change bumps the version; a report request runs no CHECKSUM TABLE; a bulk/raw update path bumps it too.
2. Cache table growth: old report cache rows expire but are never deleted. Schedule a daily clean-up of expired rows in the cache table (and cache_locks), and exclude the cache, cache_locks and sessions tables' DATA from the backup dump (keep their structure: mysqldump --ignore-table for data, or dump structure separately) so backups don't grow with throwaway rows. Restore must still work (tables exist, empty). Tests.
3. TablePaging (~line 67): clamp the page number to 1–100000 before use, so ?page=99999999999999999999 can't cause a 500. Test on System health and one database list.
4. RedactSecretsInLogs (~line 62): also catch JSON-style keys ("password":"x", "DB_PASSWORD":"x", "RESTORE_TEST_PASSWORD":"x", "token":"x") and nested context arrays; never crash on non-string values. Tests with a password in log context.
5. Public logo route (/hospital-logo): don't start a session or set a cookie for it (remove session/cookie/CSRF middleware for this one route, keep the throttle and security headers). Test: no Set-Cookie on the response.
6. HSTS: add Strict-Transport-Security only when the request is HTTPS ($request->isSecure()), so it is ready if we choose HTTPS. Test both.
7. Old v3 snapshots: add php artisan census:rebuild-snapshots (Admin/IT, asks to type REBUILD) that rebuilds the saved copy of every closed month, quarter and year at the current ReportBuilder::VERSION, keeping each period's frozen signature block. Audit entry snapshots.rebuilt (count). Not needed on the fresh server, but useful after a future version bump. Document in docs/operations-guide.md. Tests.
When I approve, before the commit, update planning/project-history.md (section 0: 7d-4 done, next is 7e from planning/Prompts/prompt-7e-audit-retention.md, then 7f theme, 7g performance; section 5; decisions log) and planning/security.md (HSTS, logo route without session, log redaction).
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize. Don't commit until I say okay.
```
