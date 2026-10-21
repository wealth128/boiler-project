# RBAC: AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-13 · Status: Confirmed by user_

## Roles
| Role | Count | Purpose |
|---|---|---|
| ER Encoder | Many | Encodes patients for any category (OPD / ER / Admission) |
| OPD / Admission Encoder | Many | Encodes for own category only. **Switched off in v1.0.** |
| Admin | **One only** | Owns the data and reports. Also encodes. |
| System Admin | **One only** | Manages user accounts as a backup to Admin |
| Viewer (Commanding Officer) | Few | Reports only |

## Permission Matrix
| Permission | ER Encoder | OPD / Adm Encoder (off in v1) | Admin | System Admin | CO |
|---|---|---|---|---|---|
| Encode a visit | ✓ any category | ✓ own category | ✓ any category | ✗ | ✗ |
| Search returning patients | ✓ | ✓ | ✓ | ✗ | ✗ |
| Edit own entry (same day) | ✓ | ✓ | ✓ | ✗ | ✗ |
| Edit any entry | ✗ | ✗ | ✓ | ✗ | ✗ |
| View Patient Records list | Own today only | Own today only | ✓ | ✗ | ✗ |
| Generate / export reports | ✗ | ✗ | ✓ | ✗ | ✓ |
| Close / reopen a month | ✗ | ✗ | ✓ | ✗ | ✗ |
| Manage dropdown lists | ✗ | ✗ | ✓ | ✗ | ✗ |
| Manage user accounts (add, deactivate, reset password, unlock) | ✗ | ✗ | ✓ | ✓ | ✗ |
| View audit log | ✗ | ✗ | ✓ | ✗ | ✗ |
| Delete visits / patients / dropdown items (to Trash) | ✗ | ✗ | ✓ | ✗ | ✗ |
| Delete user accounts (to Trash) | ✗ | ✗ | ✓ | ✓ | ✗ |
| Delete a month report (all its visits, to Trash) | ✗ | ✗ | ✓ | ✗ | ✗ |
| Restore from Trash, delete permanently, empty Trash | ✗ | ✗ | ✓ | ✗ | ✗ |
| Import visits written during a downtime (download the template, upload, confirm, undo an import) · Gate `import-visits` | ✗ | ✗ | ✓ | ✗ | ✗ |

## Rules for Everyone
- **Login lock:** an account locks after **5 wrong passwords**. Admin or System Admin unlocks it.
- **Daily sign-out at 8:00 PM:** there is no idle timeout. Every day at **8:00 PM (Asia/Manila)**, everyone who logged in before 8:00 PM must log in again on their next click. ER night shift simply logs in again. Otherwise a session ends only when the user clicks Log out.
- **Removing access works right away:** if a signed-in user is deactivated, locked or moved to Trash, their session ends on their next click. They go to the login page with the same message the login form shows. This is written to the audit log (`user.session_ended`, with the reason).
- **Password rule:** at least **8 characters, with letters and numbers**. No symbol is required. The same rule applies everywhere, and it works without internet.
- **One Admin:** the system blocks creating a second active Admin account.
- **One System Admin:** the system blocks creating a second active System Admin account, the same way as for the Admin.
- **Closed month:** no one can edit or delete its records until Admin reopens it.
- **Deleted records:** go to Trash and are never counted in reports. Admin restores or permanently deletes them. Nobody can delete their own account or the Admin account.
- **Delete reason:** moving anything to Trash (visit, patient, user account, list item, month report) needs a reason picked from a dropdown with a fixed list for that record type. Choosing "Other (specify)" asks for the reason in 10 to 200 characters. The server refuses any reason not on the list. The lists are in planning/database-schema.md, section 5.
- **Own role:** nobody can change their own role.
- **Admin account is protected:** the System Admin cannot edit the Admin account, change its role, deactivate it or reset its password. (The System Admin can still unlock it after 5 wrong passwords.)
- **Admin forgot the password:** IT resets it on the server with `php artisan census:reset-admin-password`. It asks for the new password twice, clears a login lock, and writes `user.password_reset` to the audit log. The new password is temporary: the Admin chooses their own right after logging in.
- **Audit log:** every encode, edit, delete, restore, permanent delete, close/reopen, unlock and account change is recorded with the user and time.
- **Passwords are never visible.** They are stored hashed (one-way), so no one can see any user's password, including Admin and System Admin. The system can only set a new one.
- **Forgot password:** there is no self-service reset (no "Forgot password" link, no email). The user asks Admin or System Admin. Both follow the same rule: before setting a new password, they must re-enter **their own** password to confirm. The Admin's own password is the exception: see "Admin forgot the password".
- **Initial password must be changed:** a password set by Admin or System Admin, for a new account or a reset, is temporary. Right after logging in with it, the user must choose their own new password before they can use the system. Until then, they can only open the "Choose your new password" page or Log out. The new password can't be the same as the temporary one. This is written to the audit log (`user.password_changed`). A password set by IT with `census:reset-admin-password` is temporary too. Admin and System Admin therefore never know a user's working password.

## Routes That Are Not a Role Screen (decided in Step 7a, 2026-10-21)
These belong to the framework or to no screen. `rbac.md` did not cover them. The answers below are tested in `system/tests/Feature/Security/RouteAccessMatrixTest.php` for every kind of sender (signed out, the four roles, a user with a temporary password, a deactivated user).

| Route | Signed out | Signed in (any role) | Temporary password / deactivated |
|---|---|---|---|
| `/` | Sent to login | Sent to the role's own page | Sent to "Choose your new password" / sent to login |
| `/login` (page and send) | Works | Sent away from the login page | Same as the column before |
| `/logout` | Sent to login | Works | Works with a temporary password; deactivated is sent to login |
| `/user/confirm-password`, `/user/confirmed-password-status` | Sent to login | Works | Sent to "Choose your new password" / sent to login |
| `/settings` | Sent to login | Sent on to the profile page | Same as the column before |
| `/up` (health check) | Works | Works | Works. The only page open to everyone, with no data in it |
| `/storage/{path}` (GET and PUT) | Refused (403) | Refused (403) | Refused. **Does not exist when `APP_ENV=production`** |
| Any other address | The friendly "not found" page (404) | The friendly "not found" page (404) | Sent to "Choose your new password" / sent to login |

## Decisions
- **System Admin and patient data** (confirmed 2026-10-08): **no access** to patient records, reports or the audit log. User accounts only.
- **Password reset** (confirmed 2026-10-08): Admin and System Admin have the same right and the same rule. They re-enter their own password, then set a temporary password. Passwords are hashed and never shown.
- **Temporary passwords** (confirmed 2026-10-08): the user must change a password set by Admin or System Admin right after logging in with it. This applies to new accounts and to resets.
- **Downtime import** (confirmed 2026-10-20, built in Step 6j): **Admin only**, Gate `import-visits` plus `role:admin` on every import route. Encoder, Viewer (CO) and System Admin get 403 on all of them (System Admin has no access to patient data). Imported visits are saved with the Admin as `encoded_by` and the staff name from the file as "Written by".
