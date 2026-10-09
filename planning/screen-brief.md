# Screen Brief (Phase 2): AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-07 · For review before designing in Claude Design_

## Design direction
- **Desktop-first** at 1366×768, the common size for office/hospital PCs
- **Fast keyboard entry:** Tab moves between fields, Enter saves. Large inputs and clear labels.
- **Clean and plain:** white background with navy accents, high contrast, no decoration
- Hospital name and logo are placeholders until provided

## Navigation by role
| Role | Sees |
|---|---|
| Encoder | Encode Patient, My Entries Today |
| Admin | Encode Patient, Patient Records, Reports, Admin Settings |
| Viewer (CO) | Reports |

## Screens

### 1. Login
- Username, password, Log in
- The office (OPD / ER / Admission) comes from the account and is shown after login

### 2. Encode Patient (encoder home)
- **Top:** Search an existing patient by name or Patient ID. If found, the name fields are pre-filled. If not found, the patient is new.
- **Form (in order):** Patient ID (auto), Date/Time (auto), Last name, First name, M.I., Sex, Birthdate, Age (auto from birthdate, editable), Branch of service, Rank (filtered by branch), Diagnosis (with Other: specify), Category (auto, but ER can change it), Remarks
- **Buttons:** Save & New (main action), Clear
- **Side panel:** "Today's entries" for this encoder, editable until midnight

### 3. Patient Records (Admin)
- Table of visits with filters: date range, category, branch, search box
- Row actions: Edit, **Delete** (moves to Trash, reason required)
- **Trash screen (Admin):** restore, delete permanently (type the ID to confirm), or empty Trash

### 4. Reports (Admin, CO)
- **Controls:** Period type (Monthly / Quarterly / Annual / Custom), period picker, category (All / OPD / ER / Admission), Generate
- **Preview sections per category:**
  1. Total patients and total visits
  2. Sex (Male / Female)
  3. Age brackets
  4. Diagnosis: Top 10 plus Others
  5. Branch of service × Rank table
- **Actions:** Export PDF, Export Excel, Print
- **Admin only:** Close Month / Reopen Month

### 5. Admin Settings (tabs)
- **Users:** add, deactivate, reset password, set office and role
- **Lists:** diagnosis, ranks per branch, "Others" types, age brackets
- **Audit Log:** who did what and when (read-only)

## Out of these screens (v1.0)
Dashboard charts, discharge/transfer screens, patient profile history.
