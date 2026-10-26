# Security Plan: AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-23 · Applies to every build step and to deployment_

The system holds patient names, birthdates and diagnoses, so it is treated as sensitive data (Philippine Data Privacy Act of 2012). It runs on the hospital LAN only, with no internet. The main risks are not outside hackers. They are: someone using another person's account, a user reaching data their role shouldn't see, records changed or deleted without a trace, data lost when the server PC fails, and the server PC itself being copied or stolen.

## 1. Already In Place
| Protection | Where |
|---|---|
| Login by username, 5 wrong passwords lock the account, 10 tries per minute limit | Step 3 |
| No public sign-up; accounts made by Admin / System Admin | Step 2.5 |
| Temporary passwords must be changed at first login | Step 5b |
| Password rule: at least 8 characters, letters and numbers; stored hashed, never logged | Steps 3, 5b |
| Deactivated, locked or deleted users are signed out on their next click | Step 5b |
| **A new password ends other logins:** resetting a password (Admin, System Admin, or IT with `census:reset-admin-password`) signs that user out everywhere; changing your own password signs out your other sessions and keeps the current one. Needs `SESSION_DRIVER=database` | Step 6x |
| **No "remember me":** the login always uses remember = false and ignores the field, so a hand-made `remember=1` gets no long-lived cookie; `remember_token` changes with every new or changed password, so an old cookie never works after a reset | Step 6i-1-fix |
| **Request limits:** Print, PDF and Excel share 20 a minute per user, the patient search 60 a minute; a 429 shows "Too many requests, wait a minute" | Step 6x |
| **Races:** Close month runs under a lock and re-checks "already closed" on the locked row; a new patient saved twice at the same moment is stopped by a lock and a second duplicate check (the same duplicate message) | Step 6x |
| The Encode page and the patient search check the `encode-visits` Gate inside the controller as well as the route role | Step 6x |
| The login says how many attempts are left before the account locks (kept on purpose, see project-history) | Step 3 |
| Everyone signed out daily at 8:00 PM | Step 5 |
| Every page checks the role on the server, twice (route group + controller/policy) | Step 4 onward |
| Only one active Admin and one active System Admin; Admin account protected | Steps 5, 5b |
| Every change written to the audit log, which can't be edited by hand. **Entries older than 1 year are archived to the backup folder (`audit-archive_*.csv.gz`) and then removed automatically, daily at 04:30, and only by that job (no route or button); a failed archive deletes nothing and shows a red Admin bar** | Step 5, 7e |
| Delete goes to Trash first, with a reason; permanent delete needs the ID typed | Step 5 |
| Daily backup at 02:00, kept 30 days, each run recorded | Step 5 |
| Every backup also zips the picture files; a run succeeds only if both files were written | Step 6i-1 |
| **Downtime import (Admin only, Gate `import-visits`):** the upload is checked again at confirm (the browser only sends row numbers), formulas in cells are kept as text and never calculated, the Encode rules and the closed-month rule apply, and Excel dropdown text can't run as a formula | Step 6j |
| **Import file handling:** kept in `storage/app/private/imports` under a random 40-letter name, only for the user who uploaded it, at most an hour (cleanup every 15 minutes), never in `public/` or in a backup. 2 MB, 2,000 rows, and an .xlsx that is huge once unpacked is refused before it is read (no out-of-memory error) | Step 6j |
| **Import races:** the file is claimed atomically (one import per file), the month locks `month-close:YYYY` are held for every year of the rows and the closed months are read again inside the transaction, branch / rank / diagnosis are re-read with a lock at save, undo takes the same month locks and locks the batch's patients. Audit entries for an import hold counts and the file name, no patient data | Step 6j |
| Import page limit 60 a minute; template, upload, confirm, cancel and undo 10 a minute (per user) | Step 6j |
| Red bar for Admin when the scheduler stops (no backup, no 8 PM sign-out); System health page and `census:health` | Step 6i-1 |
| `census:restore` checks everything before it drops anything (dump readable and complete, mysql program starts, picture zip opens), then clears sessions, cache and locks after loading | Step 6i-1-fix |
| `census:install` refuses on a server that already has the system (`.env` with an `APP_KEY`, users, or the database) unless `--force` and the typed words `INSTALL OVER` | Step 6i-1-fix |
| `census:install`, `census:update`, `census:restore`: root password typed hidden, never stored or logged; restore needs `RESTORE` typed, takes a safety backup first and refuses files outside `BACKUP_PATH` | Step 6i-1 |
| **Automatic test restore and Copy to USB:** scratch database with its own account (`census_restore_test`, rights on that database only, fixed name), USB copy to removable drives only with `.part` files and size checks, the three heavy Backups buttons limited to 6 a minute per user, MySQL login files cleaned up after a crash | Step 6i-3 |
| Pages share only the user's id, name, username, role, office and a per-login value (`loginAt`) | Step 2.5, 6i-3 |
| **Security headers on every response (Step 7a):** `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, and a `Content-Security-Policy` (scripts only from this server plus one inline script with a new nonce on every response, no `unsafe-eval`; styles keep `unsafe-inline` because the interface sets styles while running). Checked in Chrome against the production build. Since 7d-4, `Strict-Transport-Security: max-age=31536000` is added only when the request is HTTPS (`isSecure()`), so it is ready if HTTPS is chosen and does nothing on plain HTTP | Step 7a, 7d-4 |
| **Session cookie:** HttpOnly and SameSite=Lax fixed in the code (not read from `.env`); `Secure` from `SESSION_SECURE_COOKIE` (default off for plain HTTP; `true` when HTTPS is chosen) | Step 7a |
| **No developer or storage routes in production:** `'serve'` in `config/filesystems.php` follows `APP_ENV`, so `storage/{path}` and `_inertia/devtools` do not exist when `APP_ENV=production`. Tested by starting the app in production mode | Step 7a |
| **Friendly 500 page:** with `APP_DEBUG=false` a server error shows no stack trace, SQL, class name or file path, as a page, an Inertia request or JSON. System health flags `APP_ENV` and `APP_DEBUG` | Step 7a |
| **Log files hide secrets:** every file log channel cleans the `APP_KEY`, database and restore-test passwords, bcrypt hashes and the value of any name containing password, passwd, token or secret (`password`, `DB_PASSWORD`, `RESTORE_TEST_PASSWORD`, `remember_token`, `token`), written as `name=value`, `name: value` or JSON (`"password":"x"`), also JSON inside JSON text and nested context arrays; odd value types cannot make it fail (`App\Services\RedactSecretsInLogs`); a failed backup's error text is cleaned before it is saved | Step 7a, 7d-4 |
| **Route x role matrix and tamper tests:** every route is in `tests/Feature/Security/RouteAccessMatrixTest.php` (a new route without an entry fails); every form is tested with wrong, missing, too long, list-shaped and extra values, other people's records, records in Trash and closed months. Code scans (raw SQL, redirects, files under `public/`, programs started, secrets, `$fillable`) fail when a new unlisted use appears | Step 7a |
| **Ids in addresses are at most 18 digits** (`Route::pattern`), so a huge number is "not found", not a server error | Step 7a |
| **Public logo route (Step 7c):** `GET /hospital-logo` has no sign-in because the login page needs the logo. It is read only, serves only the logo file (it takes no file name or other input, so it can never serve a signature or any other file), is cached for a day, answers 404 when no logo is set, has a 120 a minute limit, and sends `nosniff` and a `default-src 'none'` policy. Since 7d-4 it runs without the session, cookie, CSRF and app middleware, so it never starts a session or sets a cookie (the throttle and the security headers stay); tested for no `Set-Cookie`. The signatures stay behind sign-in and their role rule. The logo is meant to be seen by anyone who can open the login page, so it must not hold anything private. Tested in `PublicLogoTest` and `RouteAccessMatrixTest` | Step 7c |
| AFP serial number not collected | Phase 1 |

## 2. Rules for Every New Screen (Claude Code follows these)
1. **Never trust the browser.** Every rule shown in a form (required fields, allowed choices, who may click a button) is checked again on the server: role middleware, Policy or Gate, and a Form Request. Checks shared by several screens (like delete reasons) may live in one service instead of a Form Request, as long as the server runs them.
2. **Database:** use Eloquent or query bindings. Never put user input directly into SQL text.
3. **Output:** let React escape text. Never use `dangerouslySetInnerHTML`.
4. **Forms:** keep Laravel's CSRF protection on (Inertia sends the token). Models use `$fillable`; never `$guarded = []`.
5. **Redirects** from a URL value (like `return=`) only go to known paths on this system.
6. **Errors:** show friendly messages; never stack traces, SQL or file paths.
7. **Exports (PDF / Excel):** same permission check as the screen. In Excel, a cell that starts with `=`, `+`, `-` or `@` is written as text, so a name can't run as a formula.
8. **Downloads and files** (backups, exports) are never placed in `public/`. They are sent through a controller that checks the role.
9. **Logs:** no passwords, no full patient lists. Audit entries for permanent deletes keep only the ID or name.
10. **Tests:** every new route gets a test for each role (allowed or 403).
11. **No new packages** without asking.
12. Delete reasons are picked from a fixed list per record type; Other needs at least 10 characters.
13. **New password or reset = end the old sessions** (`App\Services\UserSessions`). Any new feature that sets or resets a password must call it. It must also set a new `remember_token` (`User::setTemporaryPassword()` does).
14. **Anything that can be pressed twice at once** (close, save a new record that must be unique) takes a lock for that record and checks the rule again inside it. Anything that builds a file or searches as the user types gets a named limiter in `AppServiceProvider::configureRateLimits()`.
15. **Locks that cover related records share one key:** closing and reopening a month use one lock per year (`month-close:YYYY`); a new patient's lock key is built from the name the way MySQL compares it (case and accents ignored), so different spellings of one person cannot get different locks.

## 3. Security Review (end of Phase 6, before the parallel run)
**Done in Step 7a (2026-10-21): see `docs/security-review-2026-10.md`** (15 findings, all fixed or explained; the open items for the developer are at the end of that report). The list below is what was checked. Re-run `php artisan test tests/Feature/Security` after any change to routes, roles, forms or settings.

A full check of the finished system, by Claude Code and then by me:
- Every route × every role: allowed or 403 (one test file covering the whole route list).
- Try each form with the browser checks removed (wrong values, missing values, someone else's record).
- Confirm every change appears in the audit log, and that passwords never do.
- `composer audit` and `npm audit`: no known vulnerable packages.
- Restore a backup into a spare database and check the record counts match.
- **Downtime import (6j):** every import route × every role (only Admin allowed); a hand-made confirm with rows that have errors, rows not in the file or someone else's token; a file with a formula, a huge formatted sheet, a CSV in another encoding; confirm twice; confirm while a month is closing; audit entries contain no patient data.

## 4. Server Setup (Phase 8 checklist)
**Laravel**
- `APP_ENV=production`, `APP_DEBUG=false`, a new `APP_KEY` made on the server.
- Run `php artisan route:list` and confirm no `_inertia/devtools` or `storage/{path}` routes. Nothing to set by hand: `config/filesystems.php` turns `serve` off whenever `APP_ENV=production` (Step 7a).
- Don't run the dev seeder. Set the first Admin and System Admin passwords on the server; both must change them at first login.
- Session cookie: `http_only` and `same_site=lax` are fixed in the code (Step 7a); set `SESSION_SECURE_COOKIE=true` in `.env` if HTTPS is used (never on plain HTTP: nobody could log in). If HTTPS: the `Strict-Transport-Security` header is already sent automatically over HTTPS (7d-4, one year, this host only); switch to HTTPS only after it works on every office PC, because browsers remember the rule.

**HTTPS decision (decided 2026-10-26: HTTPS, built in Step 7h, `planning/Prompts/prompt-7h-https.md`):** on a closed LAN, plain HTTP still lets anyone on the same network read traffic, including passwords. Options: (a) HTTPS with a certificate from an internal certificate authority or a self-signed one installed on each office PC, or (b) accept HTTP on a separate, wired, staff-only network. Decide with the hospital IT/CO.

**MySQL**
- Listens on `127.0.0.1` only (not reachable from other PCs).
- `census_app` gets only these rights, on `patient_census` only (created by `php artisan census:install`, step 6i-1): **SELECT, INSERT, UPDATE, DELETE** for daily use, plus the least `mysqldump` needs for the backup: **LOCK TABLES, SHOW VIEW, TRIGGER**. No CREATE, ALTER, DROP, GRANT or global rights. Its password is random (32 letters and numbers) and lives only in `.env`.
- `census_restore_test@localhost` (Step 6i-3, for the automatic test restore) has **ALL privileges on `patient_census_restore_test.*` only**: it can create and drop that one scratch database and nothing else (no rights on `patient_census`, no global rights). Created by `census:install`, or `census:install --repair` on an older server (asks for the root password once). Its random password (32 letters and numbers) is `RESTORE_TEST_PASSWORD` in `.env` only, never in a log or the audit log, and is scrubbed from stored error messages. **The test restore never touches `patient_census`:** the database name is a constant and `MysqlAdmin` throws on any other name.
- **MySQL login files** (loading or dumping a backup): named `census-login-<random>.cnf`, owner-only, in the system temp folder (never under `storage/app/private`), deleted in a `finally` block. At the start of every run, leftovers of that exact name pattern older than 1 hour (a crashed run) are deleted; nothing else in the temp folder is touched.
- **Copy to USB** writes only to a removable drive that Windows reports itself (the drive letter sent by the browser is checked against that list again), only inside `census-backups`, and deletes only census backup file names there. Nothing is downloaded to a browser. Run backup now, Run test restore now and Copy to USB are Admin only (403 for everyone else) and limited to 6 a minute per user.
- **Warning bars:** the x on an Admin warning bar only hides it in that browser (localStorage, per user and message). It changes nothing on the server. Yellow bars come back the next day (Asia/Manila), red bars at the next sign-in (`auth.loginAt`, new on every login), and any bar when its text changes.
- Migrations run with `root` during `census:install`, `census:update` and `census:restore`. The `root` password is strong and kept by IT only. The commands ask for it with hidden typing and keep it in memory only: it is never written to `.env`, a log, the audit log or the config cache. (Loading a backup needs a temporary MySQL login file with owner-only rights, deleted right after.)

**Windows server PC**
- Firewall: allow only the web port (80 or 443) from the hospital LAN. Block MySQL's port 3306 from outside.
- Windows account for the server is not shared; screen locks after 5 minutes; Windows updates and antivirus on.
- The `system` folder, `.env` and backup folder are readable only by the server's account and IT.
- Disk encryption (BitLocker) if available, so a stolen PC or drive doesn't expose patient data.
- PC kept in a locked room, on a UPS.

**Backups**
- Copied to a second location (another PC or external drive) daily, not only the server's own disk.
- Backup folder is not shared on the network for everyone.
- A test restore is done monthly **automatically** (1st at 03:00) and recorded; a failure shows a red bar for Admin.
- A weekly **Copy to USB** (button on the Backups page, USB plugged into the server) puts the newest 8 backups on a drive kept in another room.

## 5. People and Routine (Phase 9 handover)
- One account per person. No shared logins, no passwords on sticky notes.
- Lock the PC (Windows + L) when leaving it.
- Deactivate an account the same day the person leaves or transfers.
- Monthly: Admin reviews the user list and skims the audit log for unusual deletes or edits.
- Exported reports are aggregate counts (no names); printed reports are filed, not left on desks.
- Who to call when something looks wrong: written in the operations guide (Phase 7.5).
