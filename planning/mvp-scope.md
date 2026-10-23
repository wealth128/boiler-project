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
- **Per category (OPD / ER / Admission):** Total patients and visits, sex, age bracket, diagnosis, branch of service with rank breakdown. Sex, age bracket and branch/rank count each patient once (latest visit). **Diagnosis counts every visit** and lists every diagnosis by name, highest count first, then by name. There is no Top 10 and no "Others" line. "Other (specify)" visits show the typed text as their own lines (upper/lower case and extra spaces ignored; "Other (unspecified)" if empty). The Diagnosis total equals the category's Visits.
- **Report content (decided 2026-10-23, Step 7d-2).** Screen, Print, PDF and Excel show the same tables with the same numbers, in the same order (one source: `ReportBuilder` through `ReportSections`).
  - **Summary (all categories together; each patient counts once, using their latest visit in any category, so it can be below the categories added up):** (1) patients and visits by category and the total; (2) sex for OPD, ER, Admission and all; (3) age range by sex (Male, Female, Total); (4) the **Top 20 diagnoses** per visit with the count and % of visits, no "Others" line, with the note "Full list on each category page."; (5) branch of service with %, and AFP personnel against Others; (6) ranks by branch in seniority order with branch subtotals; (7) new and returning patients; (8) the daily census (one row for each day with OPD, ER, Admission and total visits, a total row and the busiest day; one row for each month for a period longer than 31 days); (9) comparison with the previous period of the same length (patients and visits, change and %), left out when that period starts before January 2026.
  - **Each category (OPD / ER / Admission):** sex, age range by sex, **every** diagnosis (highest count first, ties by name) with the % of the category's visits, branch × rank with branch subtotals and the AFP / Others split, new and returning patients (new = no earlier visit in that category), and the daily census of the category.
  - **New and returning:** of the patients counted in the period, new = no earlier visit (not in Trash) before the period; returning = seen before. On a category page the earlier visit must be in that category; on the Summary, in any category.
  - **Saved copies:** closing a month saves all of this (`ReportBuilder::VERSION` 4, including the previous-period figures), so a closed month does not change after a correction. A copy of an older version is counted live and shown as "Not final – no saved copy".
  - **Screen:** each table is a collapsible panel; printing the screen (Ctrl + P) and the Print view always show every panel open. **Print / PDF:** each table is kept on one page where it fits.
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

## 5b. Downtime Import (added 2026-10-20, Step 6j)
If the system is down for hours or days, offices write each visit in a spreadsheet (Excel or Google Sheets) using the downtime template. When the system is back, **Admin imports the file** so the reports are complete. This is **not** an import of old history (that stays out of v1.0).
- **Where:** Records > Import. Admin only. Admin downloads the template there (it is built from the current lists) and keeps a copy on each office PC and a printed one.
- **File:** one row = one visit, 17 columns in a fixed order (Visit Date, Visit Time, Patient ID, Last Name, First Name, M.I., Sex, Birthdate, Age, Branch, Rank / Type, Rank Other, Diagnosis, Diagnosis Other, Category, Remarks, Written by). `.xlsx` or `.csv` (UTF-8; a Windows-1252 CSV from Excel is converted), up to 2 MB and 2,000 rows. More rows, or data far below the last row, are refused, never cut.
- **Preview first:** every row is OK, Warning or Error, with the reason in plain words. Nothing is saved until Admin confirms. Rows with an error can't be imported.
- **Same rules as Encode:** the visit's fields use the Encode rules. A visit may not be in the future, before January 2026, or in a **closed month** (also checked again at confirm, under the same lock closing a month uses).
- **Warnings Admin decides:** linked to an existing patient with the same name and birthdate (or "save as a new patient instead"), a possible duplicate visit (unticked by default), the same patient twice in the file, a typed Other that matches a listed name.
- **At confirm:** only the ticked rows are saved, all or none, in one transaction. A row that became a possible duplicate after the preview is **held back** and listed in the result. If a branch, rank or diagnosis was removed or turned off, or a month was closed, meanwhile, the **whole import is refused**. A file can be imported **once**.
- **Afterwards:** visits are dated from the file, the Admin is `encoded_by`, "Written by" is saved and shown with an "Imported" label in Records. Reports count them like any other visit. **Past imports** lists each import; **Undo import** moves the whole batch (and patients only it created) to Trash with the reason "Wrong import", unless a visit is in a closed month.

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
Discharge recording, transfer tracking, bed census, dashboard charts (later), importing old Google Sheets history (the downtime import of section 5b is different: only visits written while the system was down), address/doctor/unit fields, internet access.
