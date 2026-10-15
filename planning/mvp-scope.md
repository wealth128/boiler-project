# MVP Scope v1.0: AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-14 · Status: Phase 1 complete (pending the inputs in section 7)_

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

## 5a. Dropdown Lists
Admin keeps the diagnosis list, the ranks of each branch (including the "Others" patient types) and the age brackets on the Dropdown Lists screen. These rules apply wherever the lists are used:
- **No duplicate names.** Upper and lower case don't count as different: "hypertension" is the same as "Hypertension". Ranks only need to be unique within their branch, so CPL can be in both Army and Marines. Names are saved without extra spaces, 2 to 120 characters (ranks 2 to 60). If the same name is in Trash, restore it from Trash instead of adding it again.
- **Edit is for fixing spelling only.** Visits store which item was picked, so a new name shows on every past visit and in open-month reports. Closed months keep their saved report copy. To use a different item, add a new one.
- **Ranks keep a seniority order**, set with Move up / Move down. The Encode rank list and the report's Branch × Rank table use this order.
- **Diagnoses are listed alphabetically**, with "Other (specify)" last.
- **Age brackets can't overlap.** Ages run from 0 to 120, From can't be above To, and a blank To means "and above" (e.g. "60 & above"). Brackets are shown by From age.
- **"Other (specify)"** in diagnoses and in "Others" types is fixed: it can't be edited, deleted or moved.
- **Deleted items** go to Trash with a reason. They disappear from Encode, but past visits and reports keep showing them. An item can't be restored if its name is now used by an active item, or if its ages overlap an active bracket.

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
