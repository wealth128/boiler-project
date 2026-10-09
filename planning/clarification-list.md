# Clarification List: AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-03_

**How to use:** Each item has a **suggested default**. If the default is right, write "OK". If not, write the correct answer. Items marked ⚠️ are **blockers**: the screens and database can't be designed until they're answered.

---

## A. Patient Record (what gets encoded)

| # | Question | Suggested default | Your answer |
|---|---|---|---|
| A1 ⚠️ | What uniquely identifies a patient? AFP personnel have a serial no., but dependents and civilians don't. | The hospital record no. is required for everyone. The AFP serial no. is optional. |Every patient should have a unique ID, but keep the AFP serial no. which mean AFP personel still have patient ID and serial no. |
| A2 ⚠️ | Final list of fields to encode? | Visit date/time, patient ID, last/first/middle name, sex, birthdate, branch, rank (or "Others" type), department, diagnosis, category (OPD/ER/Admission), remarks |The order is patient id (I think it should be auto increment?), also, date and time have already a time stamp, last name, firt name, middle initial, sex, birthdate, branch of service, rank. the rank will be based on the branch of service because the rank of afp is different based on the bracnh. like for example, Navy is Ensign and Army is 2nd Lt., Diagnosis. The caterogy is will be based on the office whether it is from OPD, ER, and ADMISSION. get this?|
| A3 | Encode **birthdate** or **age**? | Birthdate. The system computes the age at visit date, so it's always accurate. |I think for good, they should ecnode this |
| A4 | Sex options? | Male / Female | yes! only male and female|
| A5 | Any other fields the CO asks about (address, unit, disposition, attending doctor)? | None for now |no need |

## B. Categories & Transfers

| # | Question | Suggested default | Your answer |
|---|---|---|---|
| B1 ⚠️ | Which moves are allowed? | OPD → ER, OPD → Admission, ER → Admission | |
| B2 | Who records the move? | The encoder updates the category. The system keeps the history and auto-adds a remark (e.g., "Moved from ER, 12-Jul-2026 14:30"). | |
| B3 ⚠️ | If a patient enters ER on Jan 31 and is admitted Feb 1, which month counts them? | They count under Admission in **February** (the date of the move) | they should be count both in january and february|
| B4 ⚠️ | Does "Admission" in the report mean **new admissions this period**, or **all patients currently admitted** (census)? | New admissions in the period | |
| B5 | Do we need to record discharge? | No (not needed for the current report) | |

Anwser: The goal of this system is only to automate the encodeing and reporting process of the hospital. In which the flow is, a patient go to ER, OPD, or Admission the desk clerk or the one who encodes the details of the patient/s encode it in google sheet. Throughout of the they, they past it on the Admin office per day, now at the end of the month, the admin manually counts the data each day for reporting. the only purpose is to encode and report 

## C. Counting Rules

| # | Question | Suggested default | Your answer |
|---|---|---|---|
| C1 ⚠️ | Is a patient counted once per period (month/quarter/year)? | Yes. Total visits are shown as a separate line. | |
| C2 | If the same patient visits OPD and later ER in the same month: | Once in OPD and once in ER. Once in the grand total. | |
| C3 | Can a patient have more than one diagnosis per visit? | One primary diagnosis (counted). Optional secondary (not counted). | |
| C4 | If the diagnosis is updated later (ER impression → final), which counts? | The latest one | |

## D. Dropdown Lists (master data)

| # | Question | Suggested default | Your answer |
|---|---|---|---|
| D1 ⚠️ | Age brackets? | _Need your list._ Admin can edit them. | |
| D2 ⚠️ | Diagnosis list source? | The hospital's common diagnoses plus "Other (specify)". Admin can add entries. | |
| D3 | What happens to "Other (specify)" entries? | Admin reviews them monthly and can add them to the list or merge them | |
| D4 ⚠️ | Branches? | Army, Navy, Air Force, Marines (separate, since Marines have their own ranks), Others | |
| D5 | Rank lists per branch (officer + enlisted)? | I draft them from the AFP standard ranks and you verify | |
| D6 | "Others" types? | Dependent, Retiree, Civilian Employee, Civilian, Other (specify) | |
| D7 ⚠️ | "Department": the hospital section that handled the patient (IM, Surgery, OB, Pedia, Dental…) or the patient's own unit? | Hospital section. _Need the list._ | |

## E. Users & Access

| # | Question | Suggested default | Your answer |
|---|---|---|---|
| E1 | Roles? | Encoder, Admin, Viewer (CO) | |
| E2 | Who creates and resets user accounts? | Admin | |
| E3 | One shared ER login or individual logins? | Individual logins, so every entry has an owner | |
| E4 ⚠️ | Can encoders edit or delete after saving? | Encoders can edit **same day only**. After that, only Admin can. No permanent delete: records are "voided" with a reason. | |
| E5 | Track who encoded/edited what and when (audit log)? | Yes | |
| E6 | How many encoders and PCs at launch? | _Need the number_ | |

## F. Daily Workflow

| # | Question | Suggested default | Your answer |
|---|---|---|---|
| F1 ⚠️ | Is encoding done **as the patient arrives**, or **in a batch at end of shift**? | As the patient arrives, so the screen is built for fast entry | |
| F2 ⚠️ | Does ER encode 24/7? If yes, the server PC must stay on 24/7. | Yes, 24/7 | |
| F3 | When does "a day" start: midnight, or shift-based (e.g., 8AM–8AM)? | Midnight | |
| F4 | Lock a month after its report is final? | Yes. Admin "closes" the month, and changes after that require Admin to reopen it. | |

## G. Reports

| # | Question | Suggested default | Your answer |
|---|---|---|---|
| G1 ⚠️ | Current report template? | _Try to get a copy or photo._ Without it, I'll design one table section per category. | |
| G2 ⚠️ | Which breakdowns? Branch × Rank is confirmed. Any others (Sex × Age, Diagnosis × Branch…)? | Each item counted separately, plus Branch × Rank | |
| G3 | Diagnosis: show all, or Top 10 plus "Others"? | Top 10 plus "Others" | |
| G4 | Quarter and year: calendar (Jan–Mar…, Jan–Dec)? | Calendar | |
| G5 | Report header and signatories? | Hospital name and logo, period, "Prepared by", "Noted by: Commanding Officer" | |
| G6 | Formats? | On-screen, PDF, Excel, Print. The user chooses. | |
| G7 | Custom date range report (e.g., Jul 1–15)? | Yes | |
| G8 | Dashboard charts on screen? | Nice-to-have, after the main reports | |

## H. Setup & Hosting

| # | Question | Suggested default | Your answer |
|---|---|---|---|
| H1 ⚠️ | Are all office PCs on the **same hospital network**? | Yes | |
| H2 | Which PC is the server? Windows? Can it be dedicated? | One dedicated Windows PC in Admin | |
| H3 | Backup? | Automatic daily copy to a second location (external drive or another PC) | |
| H4 | Is there hospital IT staff who can restart/maintain it? | _Need to know_ | |
| H5 | Who supports the system after go-live? | _Need to know_ | |
| H6 | Does the hospital's Data Protection Officer need to approve? | Yes, check before go-live | |

## I. Project

| # | Question | Suggested default | Your answer |
|---|---|---|---|
| I1 ⚠️ | Is this going to be **actually used** by the hospital, or a portfolio/practice build? | Actual use, so built with real security and backups | |
| I2 | Target date? | _Need a date_ | |
| I3 | Programming language/tools you're comfortable with? | I propose a simple stack after this list is answered | |
| I4 | Claude Design's role? | Design all screens in Claude Design first, then build the code from them | |
