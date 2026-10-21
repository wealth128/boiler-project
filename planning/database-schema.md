# Database Schema (Phase 4): AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-14 · MySQL · Status: Draft for review_

Laravel's standard tables (`sessions`, `cache`, `jobs`, `migrations`) are created by the framework and not listed here.

## 1. Table Overview
| Group | Table | Holds |
|---|---|---|
| People | `users` | System accounts and roles |
| | `patients` | One row per person (name, sex, birthdate) |
| Encoding | `visits` | One row per visit: what gets counted in reports |
| | `import_batches` | One row per downtime import file (who, when, counts, undone). Visits and new patients of the file point back to it |
| Dropdown lists | `branches`, `ranks`, `diagnoses`, `age_brackets` | Admin-maintained choices |
| Control | `month_closures` | Which months are closed (locked) |
| | `report_snapshots` | Saved copy of each closed month's report, and of each quarter and year whose months are all closed |
| | `audit_logs` | Who did what, when |
| | `backup_runs` | Result of each automatic or manual backup |
| | `backup_marks` | Admin marked a USB copy or a test restore as done |
| | `settings` | Hospital name, logo, report signatories |

## 2. Tables

### users
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar(100) | |
| username | varchar(50) | Unique |
| password | varchar(255) | Hashed |
| role | enum('encoder','admin','system_admin','viewer') | Only one active `admin` and one active `system_admin`, checked by the app |
| office | enum('ER','OPD','Admission','Admin','IT','Command') | Decides the default category for encoders |
| is_active | boolean | Deactivated users can't log in (temporary, separate from delete) |
| failed_attempts | tinyint, default 0 | Resets to 0 on a successful login |
| locked_at | timestamp, null | Set when failed_attempts reaches 5. Cleared on unlock. |
| must_change_password | boolean, default false | Set when Admin, System Admin or IT sets the password (new account or reset). The user must choose their own right after logging in, which clears it. |
| deleted_at | timestamp, null | Soft delete (Laravel `SoftDeletes`). Set = in Trash. |
| deleted_by | FK → users, null | |
| delete_reason | varchar(255), null | Required when moved to Trash. Picked from a list (section 5, "Delete reasons") |
| remember_token, created_at, updated_at | | Laravel standard |

### patients
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| patient_no | varchar(12) | Unique. Generated from id, e.g. `P-000001` |
| last_name | varchar(60) | Indexed with first_name for search |
| first_name | varchar(60) | |
| middle_initial | varchar(2), null | |
| sex | enum('Male','Female') | |
| birthdate | date | |
| import_batch_id | FK → import_batches, null | Set only on a patient first created by a downtime import, so "Undo import" knows which patients to move to Trash |
| deleted_at | timestamp, null | Soft delete (Laravel `SoftDeletes`). Set = in Trash. |
| deleted_by | FK → users, null | |
| delete_reason | varchar(255), null | Required when moved to Trash. Picked from a list (section 5, "Delete reasons") |
| created_at, updated_at | | |

### visits
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| visit_no | varchar(12) | Unique, e.g. `V-000001` |
| patient_id | FK → patients | |
| visited_at | datetime | Auto timestamp on save |
| age | tinyint unsigned | Auto from birthdate, editable. Reports use this value. |
| branch_id | FK → branches | Stored per visit because rank can change over time |
| rank_id | FK → ranks | |
| rank_other | varchar(60), null | Filled only when "Other (specify)" is chosen |
| diagnosis_id | FK → diagnoses | |
| diagnosis_other | varchar(120), null | Filled only when "Other (specify)" is chosen |
| category | enum('OPD','ER','Admission') | |
| remarks | text, null | |
| written_by | varchar(60), null | Who wrote the row in the downtime sheet. Set only by an import. Shown in Records |
| import_batch_id | FK → import_batches, null | Set only on visits created by a downtime import. Indexed. Shows the "Imported" label and drives "Undo import" |
| encoded_by | FK → users | |
| updated_by | FK → users, null | Last editor |
| deleted_at | timestamp, null | Soft delete (Laravel `SoftDeletes`). Set = in Trash. |
| deleted_by | FK → users, null | |
| delete_reason | varchar(255), null | Required when moved to Trash. Picked from a list (section 5, "Delete reasons") |
| created_at, updated_at | | |

**Indexes:** (category, visited_at, deleted_at) for reports · (patient_id, visited_at) for repeat checks · (encoded_by, visited_at) for "My entries today" · (import_batch_id) for Undo import

### import_batches
One row per downtime import (Records > Import). No soft delete: it is a record of what was imported, never removed.
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| file_name | varchar(255) | The name the file had on Admin's computer |
| original_rows | int unsigned | Rows with data in the file |
| imported_count | int unsigned | Rows saved as visits |
| skipped_count | int unsigned | original_rows minus imported_count (unticked rows, and rows held back as new duplicates) |
| imported_by | FK → users | The Admin who confirmed |
| created_at | timestamp, indexed | |
| undone_at | timestamp, null | Set when the whole import was moved to Trash |
| undone_by | FK → users, null | |

The uploaded file itself is not stored in the database. It waits in `storage/app/private/imports` for at most an hour (until confirm or cancel), and is never in `public/` or in a backup.

### branches
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| name | varchar(30) | Unique: Army, Navy, Air Force, Marines, Others |
| is_afp | boolean | false for "Others" (shown as "Type" instead of "Rank") |
| sort_order | smallint | |
| is_active | boolean | |

### ranks
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| branch_id | FK → branches | |
| name | varchar(60) | Unique per branch, ignoring upper / lower case (checked by the app; the database index also blocks exact repeats). 2 to 60 characters. "Others" types (Dependent, Retiree…) live here too. |
| is_other | boolean | Marks the "Other (specify)" entry. Always listed last. |
| sort_order | smallint | Seniority order within the branch, set by Admin with Move up / Move down. Used by the Encode rank list and the report's Branch × Rank table. A new rank goes to the bottom. |
| deleted_at | timestamp, null | Soft delete (Laravel `SoftDeletes`). Set = in Trash. |
| deleted_by | FK → users, null | |
| delete_reason | varchar(255), null | Required when moved to Trash. Picked from a list (section 5, "Delete reasons") |

### diagnoses
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| name | varchar(120) | Unique, ignoring upper / lower case. 2 to 120 characters. |
| is_other | boolean | Marks "Other (specify)". Always listed last. |
| sort_order | smallint | Not used: diagnoses are listed alphabetically. |
| deleted_at | timestamp, null | Soft delete (Laravel `SoftDeletes`). Set = in Trash. |
| deleted_by | FK → users, null | |
| delete_reason | varchar(255), null | Required when moved to Trash. Picked from a list (section 5, "Delete reasons") |

### age_brackets
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| min_age | tinyint unsigned | |
| max_age | tinyint unsigned, null | null = "and above" (shown as "60 & above"). Ages 0 to 120; min_age can't be above max_age. The app blocks overlaps with other active brackets. |
| sort_order | smallint | Not used: brackets are listed by min_age. |
| deleted_at | timestamp, null | Soft delete (Laravel `SoftDeletes`). Set = in Trash. |
| deleted_by | FK → users, null | |
| delete_reason | varchar(255), null | Required when moved to Trash. Picked from a list (section 5, "Delete reasons") |

### month_closures
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| period | char(7) | Unique, e.g. `2026-09` |
| is_closed | boolean | |
| closed_by / closed_at | FK → users / datetime | |
| reopened_by / reopened_at | FK → users / datetime, null | |

### report_snapshots
Saved copy of a month's report, taken when Admin closes the month. Closed months always show this copy, so submitted numbers never change. The same table also holds the saved copy of a **quarter** and of a **year**, taken when closing a month makes every month of that quarter (or year) closed (see "Snapshot keys" below).
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| period | char(7) | The snapshot key: a month `YYYY-MM` (e.g. `2026-09`), a quarter `YYYY-Qn` (e.g. `2026-Q3`) or a year `YYYY` (e.g. `2026`). Unique: one current copy per key. |
| data | JSON | All counts for OPD, ER and Admission, with the bracket, diagnosis and rank labels as they were at closing |
| signature_block | JSON, null | The signature block as it was at closing: `prepared_by`, `prepared_by_title`, `noted_by`, `noted_by_title`, and `prepared_signature` / `noted_signature` (file names inside the month's frozen folder, or null when no picture was set). Null for a month closed before this column existed: it then uses the current settings. |
| created_by | FK → users | Admin who closed the month |
| created_at | datetime | |

On reopen, the month's copy is discarded together with its frozen signature pictures, and so are the copies of its quarter and its year (they are no longer final). Closing again saves fresh copies and freezes the signature block again. Both actions go to the audit log (`month.closed` / `month.reopened` for the month; a quarter or year copy has no audit entry of its own).

**Snapshot keys:** `YYYY-MM` is a month, `YYYY-Qn` a quarter, `YYYY` a year. Only `MonthService` writes them, and only `ReportSheetResolver` and `SignatureController` read them. Nothing else lists or reads this table: the month picker and the closed-month checks use `month_closures`, Close, Reopen and Delete month report accept only `YYYY-MM`, and Trash, the audit log and backups never look at it (a test guards this). A quarter or year copy is counted by the same `ReportBuilder::build` call the live report uses, with the same `version`, so the saved copy of a closed month is not touched by it.

**Frozen signature pictures:** at closing, the two signature pictures in Settings are copied to `storage/app/private/frozen-signatures/{key}/prepared.png|jpg` and `noted.png|jpg`, one folder per snapshot key (`2026-09`, `2026-Q3`, `2026`; the private `local` disk, never under `public/`). They are served only by `GET /reports/signatures/{span}/{who}` for a signed-in Admin or Viewer (CO), and only while that month is closed or that quarter or year has its copy. Reopening a month deletes its folder and the folders of its quarter and year. A closed month, and a quarter or year whose months are all closed, show their frozen block. A quarter or year with no copy (a month still open, or months closed before quarter and year copies existed) is counted live, shows "Not final" and has no signature pictures. The report numbers in `data` are not affected.

### audit_logs
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | FK → users | |
| action | varchar(40) | e.g. `visit.created`, `visit.deleted`, `visit.restored`, `visit.purged`, `month.closed`, `user.unlocked`, `user.password_reset`, `user.password_changed`, `user.session_ended`, `list.added` |
| subject_type / subject_id | varchar / bigint | What was changed (e.g. visits / 1532) |
| changes | JSON, null | Old and new values for edits |
| ip_address | varchar(45) | Which PC did it |
| created_at | datetime | Never updated or deleted |

### backup_runs
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| started_at / finished_at | datetime | |
| status | enum('success','failed') | Failures show on the Admin screen |
| file_name / size_bytes | varchar / bigint | The database dump |
| zip_name / zip_size_bytes | varchar / bigint, null | The picture files zip (`storage/app/private`: logo, signatures) taken with the same date stamp (added in Step 6i-1). Null for runs made before it and for failed runs. A run is `success` only if both files were written |
| message | text, null | Error detail |

### backup_marks
Admin's manual backup safety steps (added in Step 6g). A **reminder only**: nothing is ever blocked by these rows.

| Column | Type | Notes |
|---|---|---|
| id | PK | |
| type | enum('usb_copy','test_restore') | Weekly USB copy, monthly test restore |
| marked_by | FK → users | Restrict on delete. A user with marks also has audit entries, so can't be deleted permanently |
| marked_at | datetime | When Admin marked it done. Index on (type, marked_at) |

Overdue = no mark in the last **8 days** (USB copy) or **35 days** (test restore). Never marked counts as overdue.

### settings
| Column | Type | Notes |
|---|---|---|
| key | varchar(50) PK | e.g. `hospital_name`, `logo_path`, `prepared_by`, `noted_by` |
| value | text | |

Keys: `hospital_name`, `hospital_subtitle`, `report_office`, `prepared_by`, `prepared_by_title`, `noted_by`, `noted_by_title` (text; an empty value means its default), and `logo_path`, `prepared_by_signature`, `noted_by_signature` (the file name of a picture, or empty). The pictures are PNG or JPG, saved again by the server, and stored on the private `local` disk under fixed names (`logo.png|jpg`, `signature-prepared.png|jpg`, `signature-noted.png|jpg`), outside `public/`. They are served only by signed-in routes (Admin and Viewer).

## 3. Relationships
- One **patient** has many **visits**.
- Each **visit** points to one branch, rank, diagnosis, and the user who encoded it.
- Each **rank** belongs to one **branch**.
- A downtime **import batch** has many **visits** and many **patients** (`import_batch_id`). Reports ignore the column: imported visits count exactly like encoded ones.
- **Age brackets** aren't linked by key. Reports place each visit's `age` into a bracket when the report runs.

## 4. Check: Can Every Report Count Be Produced?
Counting rule: **each patient once per category per period**, using their latest visit in that period. Deleted visits (in Trash) are excluded. **Exception: the Diagnosis table counts every visit**, not the latest one, so it adds up to Total visits.

| Report item | Comes from |
|---|---|
| Total patients | `COUNT(DISTINCT patient_id)` |
| Total visits | `COUNT(*)` |
| Sex | `patients.sex` |
| Age range | `visits.age` matched to `age_brackets` |
| Diagnosis (per visit) | Every visit in the category and period, not the latest visit: `COUNT(*)` grouped by `visits.diagnosis_id`. "Other (specify)" visits are grouped by their typed `visits.diagnosis_other` instead (case and extra spaces ignored). No Top 10 and no "Others" line. |
| Branch × Rank | `visits.branch_id`, `visits.rank_id` |

Example: OPD patients by sex for September 2026
```sql
SELECT p.sex, COUNT(*) AS patients
FROM visits v
JOIN patients p ON p.id = v.patient_id
WHERE v.id IN (
  SELECT MAX(id) FROM visits
  WHERE category = 'OPD' AND deleted_at IS NULL
    AND visited_at >= '2026-09-01' AND visited_at < '2026-10-01'
  GROUP BY patient_id
)
GROUP BY p.sex;
```

## 5. Design Notes
- **Soft and hard delete.** Visits, patients, users and dropdown items use Laravel `SoftDeletes`. Soft delete moves an item to **Trash** (hidden everywhere, restorable). Hard delete (`forceDelete()`) removes it permanently, **only from Trash, by Admin**, after typing the item's ID to confirm. Trash never empties itself; Admin controls it.
- **Hard delete is blocked when it would break history:**
  - Visit: its month is closed (reopen first)
  - Patient: still has any visits, including ones in Trash
  - Diagnosis / rank: used by any visit
  - User: has any visits or audit log entries (accounts created by mistake can be deleted)
  - Age brackets can always be hard-deleted (not linked to visits)
- **Patients and their visits move together:** a patient goes to Trash only after all their visits are in Trash ("Move this patient's visits to Trash first."). A visit, or a deleted month report, comes back only after its patient is restored ("Restore the patient first.").
- **Delete reasons:** moving anything to Trash needs a reason picked from a fixed list for that record type. There is no free-text box, except for "Other (specify)":
  | Record type | Reasons |
  |---|---|
  | Visit | Duplicate entry · Wrong patient selected · Encoded by mistake · Test / training entry · Other (specify) |
  | Patient | Duplicate patient record · Encoded by mistake · Test / training entry · Other (specify) |
  | User | Resigned / separated · Transferred out · Created by mistake · Other (specify) |
  | List item (diagnosis, rank, age bracket) | Duplicate item · Misspelled, replaced with a corrected one · No longer used · Other (specify) |
  | Month report | Encoded in the wrong month · Test / training data · Other (specify) |

  "Other (specify)" shows a text box that needs 10 to 200 characters and is saved as `Other: <text>`. `delete_reason` stores the chosen text, so Trash and the audit log show it as is. The server accepts only a reason from that record type's list ("Choose a reason from the list."). The lists live in one place in the code: one enum per record type in `app/Enums`.
- **Every delete, restore and permanent delete is written to `audit_logs`.** Permanently deleted data still exists in backups until they rotate out (30 days).
- **Patient name, sex and birthdate** live on `patients`, so correcting them fixes all of that patient's visits.
- **Branch, rank, age and diagnosis** live on `visits`, because they describe that visit.

- **Dropdown lists are fully dynamic:** Admin can add, edit (rename or change ages), delete and restore entries. Renaming updates past visits too, because they point to the same row; closed months are protected by `report_snapshots`.
- **Fixed entries:** "Other (specify)" in diagnoses and in Others types can't be edited, removed or moved.
- **List rules** (full list in planning/mvp-scope.md, section 5a):
  - No duplicate names, ignoring upper / lower case. Ranks: within their branch. A name that is in Trash must be restored, not added again.
  - Edit is for fixing spelling only. It changes the name on all past visits, because they store the item's id.
  - Ranks keep a seniority order (`ranks.sort_order`, Move up / Move down), used in Encode and the Branch × Rank report table.
  - Diagnoses are listed alphabetically, with "Other (specify)" last.
  - Age brackets can't overlap.
  - Restoring from Trash is refused when the name is now used by an active item, or the bracket overlaps an active one.
- **Quarterly, annual and custom reports** are always counted live from the visits.

## 6. Decisions Log
- Closed months save a report copy (`report_snapshots`): **Yes**
- Dropdown lists: **Dynamic** (add, edit, delete, restore)
- Delete: **Soft and hard** for visits, patients, users, dropdown items. Trash emptied by **Admin only**, never automatically.
- Downtime import (Step 6j, 2026-10-20): `import_batches` table, `visits.written_by`, `visits.import_batch_id`, `patients.import_batch_id` (all new, nullable). No change to how reports count. Visit delete reason "Wrong import" added; Undo import uses that text for the visits and for the patients it moves to Trash.
