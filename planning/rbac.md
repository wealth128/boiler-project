# RBAC: AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-08 · Status: Confirmed by user_

## Roles
| Role | Count | Purpose |
|---|---|---|
| ER Encoder | Many | Encodes patients for any category (OPD / ER / Admission) |
| OPD / Admission Encoder | Many | Encodes for own category only. **Switched off in v1.0.** |
| Admin | **One only** | Owns the data and reports. Also encodes. |
| System Admin | One | Manages user accounts as a backup to Admin |
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

## Rules for Everyone
- **Login lock:** an account locks after **5 wrong passwords**. Admin or System Admin unlocks it.
- **No idle auto-logout.**
- **One Admin:** the system blocks creating a second active Admin account.
- **Closed month:** no one can edit or delete its records until Admin reopens it.
- **Deleted records:** go to Trash and are never counted in reports. Admin restores or permanently deletes them. Nobody can delete their own account or the Admin account.
- **Audit log:** every encode, edit, delete, restore, permanent delete, close/reopen, unlock and account change is recorded with the user and time.
- **Passwords are never visible.** They are stored hashed (one-way), so no one can see any user's password, including Admin and System Admin. The system can only set a new one.
- **Forgot password:** there is no self-service reset (no "Forgot password" link, no email). The user asks Admin or System Admin. Both follow the same rule: before setting a new password, they must re-enter **their own** password to confirm.
- **Initial password must be changed:** a password set by Admin or System Admin, for a new account or a reset, is temporary. Right after logging in with it, the user must choose their own new password before they can use the system. Admin and System Admin therefore never know a user's working password.

## Decisions
- **System Admin and patient data** (confirmed 2026-10-08): **no access** to patient records, reports or the audit log. User accounts only.
- **Password reset** (confirmed 2026-10-08): Admin and System Admin have the same right and the same rule. They re-enter their own password, then set a temporary password. Passwords are hashed and never shown.
- **Temporary passwords** (confirmed 2026-10-08): the user must change a password set by Admin or System Admin right after logging in with it. This applies to new accounts and to resets.
