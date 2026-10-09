# Database Schema (Phase 4): AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-07 · MySQL · Status: Draft for review_

Laravel's standard tables (`sessions`, `cache`, `jobs`, `migrations`) are created by the framework and not listed here.

## 1. Table Overview
| Group | Table | Holds |
|---|---|---|
| People | `users` | System accounts and roles |
| | `patients` | One row per person (name, sex, birthdate) |
| Encoding | `visits` | One row per visit: what gets counted in reports |
| Dropdown lists | `branches`, `ranks`, `diagnoses`, `age_brackets` | Admin-maintained choices |
| Control | `month_closures` | Which months are closed (locked) |
| | `report_snapshots` | Saved copy of each closed month's report |
| | `audit_logs` | Who did what, when |
| | `backup_runs` | Result of each automatic backup |
| | `settings` | Hospital name, logo, report signatories |

## 2. Tables

### users
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar(100) | |
| username | varchar(50) | Unique |
| password | varchar(255) | Hashed |
| role | enum('encoder','admin','system_admin','viewer') | Only one active `admin`, checked by the app |
| office | enum('ER','OPD','Admission','Admin','IT','Command') | Decides the default category for encoders |
| is_active | boolean | Deactivated users can't log in (temporary, separate from delete) |
| failed_attempts | tinyint, default 0 | Resets to 0 on a successful login |
| locked_at | timestamp, null | Set when failed_attempts reaches 5. Cleared on unlock. |
| deleted_at | timestamp, null | Soft delete (Laravel `SoftDeletes`). Set = in Trash. |
| deleted_by | FK → users, null | |
| delete_reason | varchar(255), null | Required when moved to Trash |
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
| deleted_at | timestamp, null | Soft delete (Laravel `SoftDeletes`). Set = in Trash. |
| deleted_by | FK → users, null | |
| delete_reason | varchar(255), null | Required when moved to Trash |
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
| encoded_by | FK → users | |
| updated_by | FK → users, null | Last editor |
| deleted_at | timestamp, null | Soft delete (Laravel `SoftDeletes`). Set = in Trash. |
| deleted_by | FK → users, null | |
| delete_reason | varchar(255), null | Required when moved to Trash |
| created_at, updated_at | | |

**Indexes:** (category, visited_at, deleted_at) for reports · (patient_id, visited_at) for repeat checks · (encoded_by, visited_at) for "My entries today"

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
| name | varchar(40) | Unique per branch. "Others" types (Dependent, Retiree…) live here too. |
| is_other | boolean | Marks the "Other (specify)" entry |
| sort_order | smallint | Order shown in the dropdown and report |
| deleted_at | timestamp, null | Soft delete (Laravel `SoftDeletes`). Set = in Trash. |
| deleted_by | FK → users, null | |
| delete_reason | varchar(255), null | Required when moved to Trash |

### diagnoses
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| name | varchar(120) | Unique |
| is_other | boolean | Marks "Other (specify)" |
| sort_order | smallint | |
| deleted_at | timestamp, null | Soft delete (Laravel `SoftDeletes`). Set = in Trash. |
| deleted_by | FK → users, null | |
| delete_reason | varchar(255), null | Required when moved to Trash |

### age_brackets
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| min_age | tinyint unsigned | |
| max_age | tinyint unsigned, null | null = "and above". The app blocks overlaps. |
| sort_order | smallint | |
| deleted_at | timestamp, null | Soft delete (Laravel `SoftDeletes`). Set = in Trash. |
| deleted_by | FK → users, null | |
| delete_reason | varchar(255), null | Required when moved to Trash |

### month_closures
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| period | char(7) | Unique, e.g. `2026-09` |
| is_closed | boolean | |
| closed_by / closed_at | FK → users / datetime | |
| reopened_by / reopened_at | FK → users / datetime, null | |

### report_snapshots
Saved copy of a month's report, taken when Admin closes the month. Closed months always show this copy, so submitted numbers never change.
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| period | char(7) | e.g. `2026-09`. One current copy per month. |
| data | JSON | All counts for OPD, ER and Admission, with the bracket, diagnosis and rank labels as they were at closing |
| created_by | FK → users | Admin who closed the month |
| created_at | datetime | |

On reopen, the copy is discarded. Closing again saves a fresh copy. Both actions go to the audit log.

### audit_logs
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | FK → users | |
| action | varchar(40) | e.g. `visit.created`, `visit.deleted`, `visit.restored`, `visit.purged`, `month.closed`, `user.unlocked`, `list.added` |
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
| file_name / size_bytes | varchar / bigint | |
| message | text, null | Error detail |

### settings
| Column | Type | Notes |
|---|---|---|
| key | varchar(50) PK | e.g. `hospital_name`, `logo_path`, `prepared_by`, `noted_by` |
| value | text | |

## 3. Relationships
- One **patient** has many **visits**.
- Each **visit** points to one branch, rank, diagnosis, and the user who encoded it.
- Each **rank** belongs to one **branch**.
- **Age brackets** aren't linked by key. Reports place each visit's `age` into a bracket when the report runs.

## 4. Check: Can Every Report Count Be Produced?
Counting rule: **each patient once per category per period**, using their latest visit in that period. Deleted visits (in Trash) are excluded.

| Report item | Comes from |
|---|---|
| Total patients | `COUNT(DISTINCT patient_id)` |
| Total visits | `COUNT(*)` |
| Sex | `patients.sex` |
| Age range | `visits.age` matched to `age_brackets` |
| Diagnosis Top 10 | `visits.diagnosis_id` (+ "Others") |
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
- **Every delete, restore and permanent delete is written to `audit_logs`.** Permanently deleted data still exists in backups until they rotate out (30 days).
- **Patient name, sex and birthdate** live on `patients`, so correcting them fixes all of that patient's visits.
- **Branch, rank, age and diagnosis** live on `visits`, because they describe that visit.

- **Dropdown lists are fully dynamic:** Admin can add, edit (rename or change ages), delete and restore entries. Renaming updates past visits too, because they point to the same row; closed months are protected by `report_snapshots`.
- **Fixed entries:** "Other (specify)" in diagnoses and in Others types can't be edited or removed.
- **Quarterly, annual and custom reports** are always counted live from the visits.

## 6. Decisions Log
- Closed months save a report copy (`report_snapshots`): **Yes**
- Dropdown lists: **Dynamic** (add, edit, delete, restore)
- Delete: **Soft and hard** for visits, patients, users, dropdown items. Trash emptied by **Admin only**, never automatically.
