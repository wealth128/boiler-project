# Security Review, October 2026 (Step 7a)

_Done 2026-10-21 on branch `step-7a-security-review`. Plan: `planning/security.md`, section 3. Rules checked: `planning/security.md` and `planning/rbac.md`._

## In short

- Every route was tried with every kind of sender. **No role could reach anything it should not.**
- Every form was tried with wrong, missing, too long, hand-made and borrowed values. **No data was changed or leaked. Six forms gave a server error (a plain "500" page) instead of a message.** All six are fixed.
- Every kind of change leaves an audit entry with the right user. No password, hash, key or token appears in the audit log, the log files or any page.
- The production settings are safe by default and are tested. The new security headers work in a real browser with the built files.
- `composer audit`: nothing. `npm audit` for the production packages: nothing. One finding in a developer tool, left for you to decide (finding 14).

**Counts:** 70 routes checked against 7 kinds of sender (490 cases). 1,585 new automated tests (the whole suite is now 3,394 tests, all passing). Findings: 0 high, 1 medium, 12 low or informational, all fixed or explained. Left for you: 5 decisions (end of this page).

## How to run it again

```
php artisan test tests/Feature/Security
```

All the Step 7a tests are in `tests/Feature/Security/`. Run them after every change to routes, roles, forms or settings. A new route that is not in the access matrix **fails** the test until someone adds it, with the answer `planning/rbac.md` gives for each role.

## 1. Who can reach what (route by role)

`tests/Feature/Security/RouteAccessMatrixTest.php`. The list of routes is read from the running system, so nothing can be forgotten.

- **Senders:** signed out, Encoder, Admin, System Admin, Viewer (CO), an Admin who still has a temporary password, and a deactivated Admin (the strongest role, to prove that the state of the account beats the role).
- **Each request is a real one** with real records, so "allowed" means the action happened (the record is saved, the month is closed, the picture is served), not only "not refused". Refused requests are checked to change nothing in the database.
- **Result: pass.** 490 cases and 4 checks of the list itself (every route has an entry, no entry without a route, the roles in the table are the roles on the route, every route except the public ones is behind login).
- The routes of the System Admin (users only), the Viewer (reports and pictures only) and the Encoder (encode and own visits only) match `planning/rbac.md` exactly.
- Two layers hold: the role rule on the route and the check inside the controller. Loosening only the route (a test of that) is still refused by the controller.

**Routes whose answer I had to work out myself** (they are not in `planning/rbac.md`, they belong to the framework or are not a screen):

| Route | Answer used |
|---|---|
| `/` | Signed in: sent to the role's own page. Signed out: sent to login. |
| `/login` (open and send) | Signed out: works. Signed in: sent away from the login page. |
| `/logout` | Any signed-in user, also one with a temporary password. |
| `/user/confirm-password`, `/user/confirmed-password-status` | Any signed-in user. |
| `/settings` | Signed in: sent on to the profile page. |
| `/up` | Open to everyone (the health check), the only page that is. |
| `/storage/{path}` (GET and PUT) | Refused for everyone (no signed link exists). **Does not exist in production.** |
| Any other address | The friendly "not found" page, also when signed out. |

## 2. Forms with the browser checks removed

Files `TamperEncodeRecordsTest`, `TamperListsMonthsTrashTest`, `TamperUsersPasswordsTest`, `TamperSettingsBackupsImportTest`. Every request is sent without the browser, so only the server's own checks count.

| Screen | What was tried (all refused with a message, 403 or 404, nothing changed) |
|---|---|
| Encode | 40 wrong forms (empty, wrong types, lists instead of text, too long, dates that do not exist or are in the future, a rank of another branch, a rank or diagnosis in Trash, a turned-off branch, a patient in Trash, a duplicate patient); another encoder's visit, yesterday's visit, a closed month, a visit in Trash; extra fields (`encoded_by`, `visited_at`, `visit_no`, `deleted_at`, `role`...) are ignored; a `return_to` address on another site never leaves the system. |
| Records | Every bad delete reason, a visit in a closed month, a visit or patient in Trash, odd filters and page numbers on every list. |
| Lists | Bad names, a second rank or diagnosis with the same name in other letters, the fixed "Other" rows, items in Trash, wrong age brackets (overlap, end before start), a bad move direction, extra fields (`is_other`, `sort_order`, `deleted_at`). |
| Reports / Months | A month that has not ended, a closed month, a month that was never closed, odd month names in the address, odd filters for the screen, print, PDF and Excel. |
| Trash | Restoring or deleting something that is not in Trash, wrong or missing confirm words, odd types and numbers in the address, and the six blocks: a visit in a closed month, a patient with visits, a diagnosis or rank in use, a user with visits or audit entries. |
| Users | 31 wrong account forms, a second Admin or System Admin, changing your own role, the System Admin touching the Admin account, deleting or deactivating yourself, an account in Trash, bad reset forms, extra fields (`must_change_password`, `is_active`, a password...). |
| Hospital settings | Too much text, text sent as a list, other setting names sent along. |
| Backups | 14 odd drive names, drives that are not removable USB drives, odd mark names. |
| Import | Wrong file types, a file over 2 MB, files that are not what their name says, 18 hand-made confirm forms, someone else's file, an import that was already undone. |
| Password pages | Wrong, weak, unconfirmed and list-shaped passwords on all three password forms, extra fields, a user with no temporary password using the choose page. |
| Login | Missing and list-shaped fields, very long values, SQL text. |

**Found: six forms answered with a server error instead of a message** (findings 1 to 6). All fixed.

## 3. Audit log

`AuditCoverageTest` (32 tests) makes each kind of change through the real route and checks the entry and the user: encode, edit, move to Trash with the reason, restore, delete permanently (each record type), Empty Trash, close and reopen a month, delete a month report (and restore, delete permanently), account changes, switch on or off, unlock, password reset, password change, choosing a new password, settings, logo and signatures, import and undo, report export, sign in, wrong passwords, lock, sign out, sign-out by the system, manual backup, test restore, USB copy and both "done" marks. The automatic test restore is saved with no user and says so. **Result: pass.** (The IT commands `census:reset-admin-password`, `census:update` and `census:restore` are checked by their own earlier tests.)

`NoSecretsTest` does real work with known secrets (new account, reset, change, wrong logins, a backup that fails and repeats every secret in its error text, a database error that holds a password hash) and then searches the audit log, a log file made by the test, the stored backup error and every page for every role (the whole page, including the values shared with all pages). **Found two small leaks** (finding 7). Fixed. Now nothing is found.

## 4. Code scans

`CodeScansTest`. A use that is safe is listed in the test with its reason. A new use that is not listed fails the test.

| Scan | Result |
|---|---|
| `dangerouslySetInnerHTML`, `innerHTML`, `eval`, `javascript:` in `resources/js` | None. |
| `{!! !!}` and `<?=` in the pages (Blade) | None. |
| Raw SQL (`DB::raw`, `whereRaw`, `selectRaw`, `statement`, `unprepared`...) | 22 uses. Reports (`ReportBuilder.php`), Import (`ImportController.php:269`), Trash (`TrashRows.php:53`), Records search (`RecordSearch.php:119, 149`) and sessions (`UserSessions.php:38`) use fixed text or a bound value (`?`). The 16 lines in `Setup/MysqlAdmin.php` (111-140, 224-225, 310-313, 328-336) build database and account names into the text, but only for setup, restore and the test restore, never from a web page: the names are checked against a fixed pattern (`^[A-Za-z0-9_]{1,32}$`) or are constants, and passwords go through `PDO::quote`. A test feeds them backticks, quotes, spaces and long names. **Safe.** |
| Models without a fillable list or with `$guarded = []` | None. All 16 models have a fillable list. `User` allows only `name, username, password, role, office, is_active`, and no controller hands a whole request to a model (scanned). |
| Redirects to an address taken from the request | 3 uses, all checked first: `VisitController.php:148` (`ReturnToRecords`: only `/records/visits` on this site), `RecordSearch.php:69` (the same Records page only), `TablePaging.php:67` (the same page, new page number). Tests try 8 hostile addresses. |
| Files written under `public/` | None. No code writes there, no script makes the storage link, `public/storage` does not exist. Pictures and imports are in `storage/app/private`. |
| Routes that send a file | Logo, signatures, frozen signatures, import template, PDF, Excel: every one has a role rule (tested), none is in `public/`. Backups have no download route at all. |
| Programs started (`Process`, `exec`...) | 5 places (`BackupService.php:116`, `Setup/AppMaintenance.php:49`, `Setup/MysqlAdmin.php:274, 447`, `UsbCopyService.php:318`). All take a list of arguments, never a string, never through a shell, nothing a user typed. No `exec`, `shell_exec`, `system` or backticks anywhere (checked with PHP's own tokenizer). |
| Secrets in files | None. `.env` is ignored by Git, `_private/` is ignored and has no tracked file, `.env.example` has no value for any password, key or secret. One placeholder in a planning document (finding 13). |

## 5. Production settings

`ProductionSettingsTest` and `SecurityHeadersTest` start the system again in its own process with `APP_ENV=production`, the way the server does.

- **No developer routes in production:** no `_inertia/devtools` and no `storage/{path}` route. While developing both exist, so the test can fail. `'serve'` in `config/filesystems.php` now follows `APP_ENV` by itself. **Phase 8 no longer needs the manual step** (handover list updated).
- **`APP_DEBUG=false`:** a forced 500 (an exception, a database error, a type error), as a page, as an Inertia request and as JSON, shows the friendly page with no stack trace, SQL, file path, class name or the secret in the message. With debug on the details do show, so the test can fail.
- **Session cookie:** HttpOnly always, SameSite=Lax always (no longer read from `.env`), `Secure` from `SESSION_SECURE_COOKIE` (default off, right for plain HTTP; set `true` when HTTPS is chosen). No remember-me cookie is ever made. Written in `docs/operations-guide.md` (section 9) and `planning/deployment-handover.md`.
- **Headers on every response** (new `SecurityHeaders` middleware, also on the health check, errors, redirects, downloads and 429 pages): `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, and a `Content-Security-Policy`:
  `default-src 'self'; script-src 'self' 'nonce-<new on every response>'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'`
  - **Strict for scripts.** No `unsafe-inline`, no `unsafe-eval`, no other site. The one inline script (dark mode) and every Vite script carry the nonce. The built files contain no `eval` and no call to another site (tested).
  - **One loosening, on purpose:** styles allow `unsafe-inline`. The interface sets style attributes while running and a toast library adds style text; a stricter style rule would break them and gives little: styles cannot run code. Everything else is strict.
  - `img-src` allows `blob:` and `data:` for the logo cropper and the pictures in reports.
  - A response that sets its own, stricter policy (the logo and signature pictures) keeps it.
  - While `npm run dev` runs, the Vite server is added to the policy; never when `APP_ENV=production`.
  - **Checked in a real browser:** Chrome, headless, against the production build with this policy: the login page loaded and drew its form, with zero policy violations.
- **System health** already flags `APP_ENV` and `APP_DEBUG`. Confirmed by 4 new cases (production and debug off is OK, the other three are a red problem).

## 6. Packages

| Check | Result |
|---|---|
| `composer audit` | No advisories. |
| `npm audit --omit=dev` (what the system ships) | 0 vulnerabilities. |
| `npm audit` (with the developer tools) | 1 high: `vite-plus` (needs `launch-editor`, three advisories). **Does not affect the hospital system:** it is the build and dev-server tool, used only on the developer PC, never installed or run on the server, and the flaw is in the "open this file in my editor" feature of the dev server. The only fix is `npm audit fix --force` (vite-plus 1.3.0, a breaking change; the Vite alias in `package.json` must be moved with it). **Not done. Your decision** (finding 14). |
| Updates available (not security) | Laravel 13.35 to 13.38, Wayfinder 0.1.21 to 0.1.23, Pest and PHPUnit major versions. Not changed. |

## 7. Login and sessions

`LoginSessionChecksTest`, all through the real login form and routes. **All pass.**

- 5 wrong passwords lock the account; the right password is then refused; Admin unlock opens it; a right password resets the counter; an unknown name gets the same message as a wrong password.
- 10 tries a minute per name and address, then 429 with a wait time; other names are not held back; it opens again after the minute.
- A temporary password sends **every** route (every method, 57 requests, page and JSON) to "Choose your new password", except that page and Log out.
- 8:00 PM sign-out for all four roles: still in at 7:59 PM after 11 hours with no click, out at 8:00 PM, and again the next evening. No idle timeout.
- Deactivated, locked, in Trash and permanently deleted accounts are signed out on the next click, with the reason in the audit log. A form post or JSON call of such an account is refused and changes nothing.
- A reset ends every session of that user and no other session.
- Remember-me: never a cookie, whatever the form says; the token changes with each password; an old cookie does not sign in.
- **CSRF:** on all 37 routes that change something, a request with no token, a wrong token, or marked "cross-site" is refused (419) and changes nothing. The right token, or a request the browser marks "same-origin", is accepted. (Laravel 13 accepts "same-origin" without a token; the session cookie is SameSite=Lax as well.)

## 8. Files and uploads

`FilesAndUploadsTest`, 89 tests. **All pass.**

- The logo and both signatures refuse: SVG (also renamed .png or .jpg), PHP, HTML, text, an empty file, GIF, WebP, BMP, PDF and zip (also renamed), files over 1 MB, pictures over 1000 pixels in either direction, several files at once. Nothing is stored and no setting changes.
- A good picture is **decoded and saved again** with a name the server picks (`logo.png`, `logo.jpg`), never the name sent. PHP and script text added after the image, and text hidden inside a JPG, are gone from the saved copy. The type follows the file, not its name. It is stored in `storage/app/private`, never in `public/`.
- The current and the frozen pictures go to Admin and Viewer only (everyone else, signed out included, a deactivated Admin and an Admin with a temporary password get nothing), with `nosniff`, a strict policy and `private` caching.
- **Path traversal:** 22 hostile values (`../`, encoded and double-encoded, null bytes, wrappers such as `php://` and `file://`, URLs, wildcards, 3,000 characters) were put into every parameter of every route that has one (period, span, who, type, id, list, user, visit, patient, batch), with every method. 25 routes and parameters, none served a file, none changed anything, none gave a server error. A canary file inside the storage folder and one in the project folder were never served.

## 9. Findings

Severity: **Medium** = a protection was missing. **Low** = a flaw with little effect on the hospital LAN. **Info** = noted, nothing to fix or a choice.

| # | Severity | Finding | Who could do it | Result |
|---|---|---|---|---|
| 1 | Low | `GET /patients/search?q[]=x` gave a server error. | Encoder, Admin | **Fixed** (`PatientSearchController.php`): a non-text value is an empty search. |
| 2 | Low | A number of 19 or more digits in an address (`/trash/visits/99999999999999999999/restore`, `/settings/diagnoses/99999999999999999999`, import undo) gave a server error. | Admin | **Fixed** (`routes/web.php`): ids and batch numbers are at most 18 digits, anything longer is "not found". |
| 3 | Low | Add or change an account with name, username or role sent as a list gave a server error. | Admin, System Admin | **Fixed** (`UserFormRules.php`): only text is tidied, the rules refuse the rest. |
| 4 | Low | "Your own password" on the reset form and "current password" on the password page sent as a list gave a server error. | Any signed-in user | **Fixed** (`PasswordValidationRules.php`): the type is checked first. |
| 5 | Low | The login form with the username as a list gave a server error (the 10-a-minute limit read the name before the form was checked). Only the public page. | Anyone on the LAN | **Fixed** (`FortifyServiceProvider.php`). |
| 6 | Low | Import "cancel" with the file code as a list gave a server error. | Admin | **Fixed** (`ImportController.php`). |
| 7 | Low | A failed backup saved its error text as written and logged it as written. mysqldump does not repeat the password, but if it did, the database password would be in the database and the log. A database error message also holds the values of the statement, for example a password hash, and unhandled errors are written to the log. | Nobody directly | **Fixed:** the backup error is cleaned before it is saved (`BackupService.php`); every log line on every file channel is cleaned of the `APP_KEY`, database and restore-test passwords, bcrypt hashes and `password=` / `remember_token=` values (`RedactSecretsInLogs.php`, set in `config/logging.php`). |
| 8 | **Medium** | No `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` or `Content-Security-Policy` on any page. The system could be shown inside a frame on another page (click tricks) and scripts injected into a page would not have been stopped. | Anyone on the LAN who can lure an Admin to a page | **Fixed:** `SecurityHeaders` middleware (section 5). |
| 9 | Low | `/storage/{path}` (GET and PUT, signed links only) and developer routes were registered outside production, and removing the storage route on the server was a manual Phase 8 step. | Nobody (no signed link is ever made) | **Fixed:** off whenever `APP_ENV=production`, tested. Handover list updated. |
| 10 | Info | The HttpOnly and SameSite flags of the login cookie could be changed in `.env`. | IT only | **Fixed:** fixed in the code. `Secure` stays a setting for the HTTPS decision. |
| 11 | Info | The profile page saved everything the form validated. Today that is only the name. | Nobody | **Tightened** (`ProfileController.php`): saves only the name, whatever the rules become. |
| 12 | Info | A user with a temporary password who asks for a record number that does not exist gets "not found" instead of the redirect to the choose page (the record is looked up first). Tells a signed-in user whether an id exists, nothing more. | A signed-in user | **Left as is.** No data is exposed. |
| 13 | Info | `planning/setup-and-structure.md` line 60 shows an example database password line ending in `ChangeThisPassword!`, taken from the first setup. Not a real password. | Anyone who reads the folder | **Fixed** on request (the line now reads "set in .env; never written in planning files"). The same example word still appears in an SQL example (`planning/setup-and-structure.md` line 37) and in an old prompt (`planning/Prompts/claude-code-prompts.md` lines 13 and 28). Not changed. |
| 14 | Info | `vite-plus` (developer tool): 1 high advisory in `launch-editor`. Not on the server. | Nobody on the hospital system | **Open, your decision.** See section 6. |
| 15 | Info | On this Windows PC, antivirus removes an uploaded file that looks like a web shell before PHP can read it. The upload then ends with a server error instead of "must be a PNG or JPG". Harmless (the file is gone) and not reproducible without that antivirus. | An attacker, with no effect | **Noted.** |

No finding allowed access to another role's data, a change without an audit entry, or a change to a closed month.

## 10. What is left, and not for this step

For you to decide or do (also Phase 8 in `planning/security.md`, section 4):

1. **HTTPS or plain HTTP** on the LAN. If HTTPS: set `SESSION_SECURE_COOKIE=true` and then think about the `Strict-Transport-Security` header (not added now: on plain HTTP it does nothing).
2. **BitLocker** on the server PC and the backup PC.
3. **Windows firewall** rules (web port from the LAN only, 3306 blocked) and the server account, screen lock and update settings. `install.bat` sets the firewall; check it on the real server.
4. **The spare-PC test:** a full install on a second PC, then a restore from a real backup. The automatic test restore proves the backup loads, but not the whole install.
5. **`vite-plus` update** (finding 14) and, if you like, the Laravel and Wayfinder patch updates. Both need a rebuild and a full test run.

Not covered by tests, and worth a human look at the real server: that office PCs reach the system only through the web port, that the backup folder and `.env` are readable only by the server account, and that the monthly "Copy to USB" is really done.
