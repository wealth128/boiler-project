# MVP Scope v1.0: AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-07 · Status: Phase 1 complete (pending the inputs in section 7)_

## 1. Objective
Replace Google Sheets and manual tallying with **one centralized system**:
1. **Encode:** Each office encodes patients directly into the system.
2. **Report:** Admin generates reports automatically, with no manual counting.

## 2. Encoding Form (in order)
| # | Field | How it works |
|---|---|---|
| 1 | Patient ID | **Auto-generated** (e.g., P-000001) the first time a patient is encoded. On a return visit, the encoder searches the patient and reuses the ID. |
| 2 | Date & time | **Auto timestamp** when saved |
| 3 | Last name | Typed |
| 4 | First name | Typed |
| 5 | Middle initial | Typed |
| 6 | Sex | Male / Female |
| 7 | Birthdate | Typed |
| 8 | Age | Exact number (e.g., 34), auto-filled from birthdate and still editable. Reports group it into age brackets. |
| 9 | Branch of service | Army, Navy, Air Force, Marines, Others |
| 10 | Rank | List changes by branch (e.g., Navy → ENS, ASN; Army → 2LT). "Others" → Dependent, Retiree, Civilian Employee, Civilian, Other (specify). |
| 11 | Diagnosis | Picked from a list, with an Other (specify) fallback |
| 12 | Category | **Set automatically from the encoder's office** (OPD / ER / Admission). An ER account can choose any category, since ER may encode for all. |
| 13 | Remarks | Optional |

_AFP Serial No. removed (too personal)._

## 3. Counting Rules
- **Each visit is its own record**, counted in the month and category it was encoded. Example: ER on Jan 31, then Admission on Feb 1, counts in **January (ER)** and **February (Admission)**.
- **Same patient, same category, same period:** counted **once**. Total visits are shown as a separate line.
- Quarterly and annual reports use calendar periods (Jan–Mar, Jan–Dec).

## 4. Reports
- **Periods:** Monthly, quarterly, annual, custom date range
- **Per category (OPD / ER / Admission):** Total patients, sex, age bracket, diagnosis (Top 10 plus Others), branch of service with rank breakdown
- **Formats:** On-screen, PDF, Excel, Print. The user chooses.
- **Header:** Hospital name/logo, period, Prepared by, Noted by: Commanding Officer

## 5. Users & Control
| Role | Can do |
|---|---|
| Encoder (per office) | Encode, and edit their own entries **same day only** |
| Admin | Everything: edit, delete (Trash), reports, dropdown lists, user accounts, close/reopen a month |
| Viewer (CO) | View and export reports |

- Individual logins
- Delete moves records to **Trash** (reason required). Admin can restore or permanently delete them.
- An audit log records who encoded or changed what
- Admin closes a month after the report is final, which locks it

## 6. Setup
- Runs on the **hospital network** (one server PC, other PCs open it in a browser), not on the internet
- Server stays on **24/7** for ER
- Automatic **daily backup**

## 7. Inputs Still Needed (not blockers)
These are editable lists in the system, so design and build can start with sample values:
- Age brackets
- Diagnosis list
- AFP rank lists: I'll draft them, you verify
- Current report template, if you can get it
- Number of encoder PCs, target date, who supports it after go-live

## 8. Not in v1.0
Discharge recording, transfer tracking, bed census, dashboard charts (later), importing old Google Sheets, address/doctor/unit fields, internet access.
