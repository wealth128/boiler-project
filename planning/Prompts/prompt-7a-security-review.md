# Prompt 7a for Claude Code: Security review (end of Phase 6)

_Paste after 6i-3 is merged and pushed. Rules: `planning/security.md` (sections 1–3 and the Phase 8 checklist in 4), `planning/rbac.md`. This step mostly CHECKS and adds tests; it fixes only what it finds. The spare-PC test of install/update/restore is separate and still required before Phase 8._

```
Step 7a – Security review of the finished system (planning/security.md section 3). Create branch step-7a-security-review. Goal: prove every rule in planning/security.md and planning/rbac.md with tests or checks, fix what fails, and write a short report. Don't add packages. Don't change features or report numbers.

1. Route × role matrix (one test file, tests/Feature/Security/RouteAccessMatrixTest.php):
   - Build the list from the real route list (Route::getRoutes()), so a new route without an entry FAILS the test.
   - For every route and every role (signed out, encoder, admin, system_admin, viewer, a user who must change their password, a deactivated user): expected result = allowed, 403, or redirect to login / choose-password. Expected values come from planning/rbac.md.
   - Use a valid request for each route (real IDs from factories) so "allowed" means the action really works, not just "not 403".
   - List any route whose access you had to guess, in the report.

2. Tamper tests (browser checks removed): for every form/POST/PUT/DELETE, send wrong types, missing fields, too-long text, values not in the allowed lists, extra fields (mass assignment, e.g. role, is_active, must_change_password, encoded_by, visited_at), another user's record ID, a record in Trash, a record in a closed month, and a changed hidden field. Expected: 422 with a message, 403, or the existing refusal message, never a 500 and never a change in the database. Group by screen (Encode, Records, Lists, Reports/Months, Trash, Users, Hospital settings, Backups, Password pages).

3. Audit coverage: one test per kind of change (create, edit, Trash, restore, permanent delete, close/reopen, delete month report, user changes, unlock, password reset/change, settings, backup actions, test restore, USB copy) checking an audit entry is written with the right user. One test that no audit entry, log line (storage/logs) or shared Inertia prop ever contains a password, a password hash, remember_token, APP_KEY, DB_PASSWORD or RESTORE_TEST_PASSWORD.

4. Code scans (report each with file:line; fix real problems):
   - dangerouslySetInnerHTML anywhere in resources/js; {!! !!} in Blade views.
   - Raw SQL with variables (DB::raw, whereRaw, selectRaw, statement, unprepared) not using bindings.
   - Models with $guarded = [] or missing $fillable.
   - redirect()/to_route using a value from the request without a known-path check.
   - Files written under public/; any route that serves a file without a role check.
   - Process / exec / shell calls: arguments must be arrays, no user input in commands.
   - Secrets: grep for hard-coded passwords/keys; .env.example has no real values; _private/ is git-ignored.

5. Production settings (the Phase 8 checklist), made safe by default and tested:
   - With APP_ENV=production: no _inertia/devtools routes and no storage/{path} route (make 'serve' => false in config/filesystems.php depend on the environment, so production never registers it). Test with route:list in a production-config test.
   - With APP_DEBUG=false: a forced 500 shows the friendly error page with no stack trace, SQL or file path. Test.
   - Session cookie: http_only true, same_site lax, secure taken from SESSION_SECURE_COOKIE (default false; set true if HTTPS is chosen). Document in docs/operations-guide.md and planning/deployment-handover.md.
   - Security headers on every response (middleware): X-Frame-Options SAMEORIGIN, X-Content-Type-Options nosniff, Referrer-Policy same-origin, and a Content-Security-Policy that works with the built assets in production (no inline-script breakage; if a strict CSP is not possible with Inertia/Vite, use the strictest that works and explain). Tests for the headers.
   - System health already flags APP_DEBUG/APP_ENV; confirm.

6. Dependencies: run composer audit and npm audit (production dependencies). List every finding with severity and whether it affects this app (offline LAN). Fix by updating within the allowed packages only if it doesn't change behaviour; otherwise list it for me to decide. Don't run npm audit fix --force.

7. Login and session checks (tests): 5 wrong passwords lock the account; the 10-per-minute login limit; temporary password forces choose-password on every route; 8 PM cutoff signs out; deactivated/locked/trashed users are signed out on their next click; reset ends all sessions; remember-me is always off; CSRF is required on every state-changing route (one test that a POST without the token is refused).

8. Files and uploads: logo/signature upload refuses SVG, renamed files, too-large files and dimensions, and re-encodes the image; frozen and current pictures are served only to admin and viewer; no path traversal in any {period}/{who}/{type}/{id} parameter (tests with ../ and odd values).

9. Write docs/security-review-2026-10.md: what was checked, the results (pass/fixed/open), every finding with severity, what was fixed and how, and what is left for Phase 8 (HTTPS decision, BitLocker, firewall, the spare-PC test). Keep it plain English.

10. When I approve, before the commit: update planning/security.md (section 1 table with new protections, section 3 marked done with a link to the report), planning/project-history.md (section 0: 7a done, next is the spare-PC test, then Phase 7 parallel run; section 5; decisions log), and planning/deployment-handover.md if any server setting changed.

Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize: counts of routes checked, tests added, findings by severity, fixes made, anything left open for me. Don't commit until I say okay.
```
