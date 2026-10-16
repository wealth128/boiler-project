# Security Plan: AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-18 · Applies to every build step and to deployment_

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
| **Request limits:** Print, PDF and Excel share 20 a minute per user, the patient search 60 a minute; a 429 shows "Too many requests, wait a minute" | Step 6x |
| **Races:** Close month runs under a lock and re-checks "already closed" on the locked row; a new patient saved twice at the same moment is stopped by a lock and a second duplicate check (the same duplicate message) | Step 6x |
| The Encode page and the patient search check the `encode-visits` Gate inside the controller as well as the route role | Step 6x |
| The login says how many attempts are left before the account locks (kept on purpose, see project-history) | Step 3 |
| Everyone signed out daily at 8:00 PM | Step 5 |
| Every page checks the role on the server, twice (route group + controller/policy) | Step 4 onward |
| Only one active Admin and one active System Admin; Admin account protected | Steps 5, 5b |
| Every change written to the audit log, which can't be edited or deleted | Step 5 |
| Delete goes to Trash first, with a reason; permanent delete needs the ID typed | Step 5 |
| Daily backup at 02:00, kept 30 days, each run recorded | Step 5 |
| Pages share only the user's id, name, username, role and office | Step 2.5 |
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
13. **New password or reset = end the old sessions** (`App\Services\UserSessions`). Any new feature that sets or resets a password must call it.
14. **Anything that can be pressed twice at once** (close, save a new record that must be unique) takes a lock for that record and checks the rule again inside it. Anything that builds a file or searches as the user types gets a named limiter in `AppServiceProvider::configureRateLimits()`.

## 3. Security Review (end of Phase 6, before the parallel run)
A full check of the finished system, by Claude Code and then by me:
- Every route × every role: allowed or 403 (one test file covering the whole route list).
- Try each form with the browser checks removed (wrong values, missing values, someone else's record).
- Confirm every change appears in the audit log, and that passwords never do.
- `composer audit` and `npm audit`: no known vulnerable packages.
- Restore a backup into a spare database and check the record counts match.

## 4. Server Setup (Phase 8 checklist)
**Laravel**
- `APP_ENV=production`, `APP_DEBUG=false`, a new `APP_KEY` made on the server.
- Run `php artisan route:list` and confirm no `_inertia/devtools` or `storage/{path}` routes (`'serve' => false` in `config/filesystems.php`).
- Don't run the dev seeder. Set the first Admin and System Admin passwords on the server; both must change them at first login.
- Session cookie: `http_only` on, `same_site=lax`; `secure` on if HTTPS is used.

**HTTPS decision:** on a closed LAN, plain HTTP still lets anyone on the same network read traffic, including passwords. Options: (a) HTTPS with a certificate from an internal certificate authority or a self-signed one installed on each office PC, or (b) accept HTTP on a separate, wired, staff-only network. Decide with the hospital IT/CO.

**MySQL**
- Listens on `127.0.0.1` only (not reachable from other PCs).
- `census_app` gets only SELECT, INSERT, UPDATE, DELETE on `patient_census` for daily use. Migrations run with a separate account during updates. The `root` password is strong and kept by IT only.

**Windows server PC**
- Firewall: allow only the web port (80 or 443) from the hospital LAN. Block MySQL's port 3306 from outside.
- Windows account for the server is not shared; screen locks after 5 minutes; Windows updates and antivirus on.
- The `system` folder, `.env` and backup folder are readable only by the server's account and IT.
- Disk encryption (BitLocker) if available, so a stolen PC or drive doesn't expose patient data.
- PC kept in a locked room, on a UPS.

**Backups**
- Copied to a second location (another PC or external drive) daily, not only the server's own disk.
- Backup folder is not shared on the network for everyone.
- A test restore is done monthly and recorded.

## 5. People and Routine (Phase 9 handover)
- One account per person. No shared logins, no passwords on sticky notes.
- Lock the PC (Windows + L) when leaving it.
- Deactivate an account the same day the person leaves or transfers.
- Monthly: Admin reviews the user list and skims the audit log for unusual deletes or edits.
- Exported reports are aggregate counts (no names); printed reports are filed, not left on desks.
- Who to call when something looks wrong: written in the operations guide (Phase 7.5).
