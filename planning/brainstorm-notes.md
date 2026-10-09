# Brainstorm Notes: AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-03 · Status: Brainstorming_

## 1. Problem
The Admission/Admin office tallies patient data by hand every month. ER encodes patients daily in Google Sheets (tagged OPD / ER / Admission) and sends the sheet to Admin. Admin counts everything manually to produce monthly, quarterly and annual reports for the Commanding Officer.

## 2. Users
| Role | What they do |
|---|---|
| Encoder (ER) | Encodes all patients for now: OPD, ER and Admission |
| Encoder (other offices) | Built into the roles but switched off at launch, so OPD/Admission can encode their own later without a rebuild |
| Admin | Views, corrects and generates reports; maintains the dropdown lists |
| Commanding Officer | Receives reports (possibly read-only access later) |

## 3. Decisions so far
- **Encoding:** The role system supports per-office encoding, but at launch only the ER role can encode.
- **Transfers:** A patient moved between categories (e.g., ER to Admission) is counted in the **new** category, with a remark recording where they came from.
- **Repeat visits:** A patient is counted **once** per report period, and repeat visits are recorded in remarks.
- **Diagnosis:** Picked from a list (better accuracy than free text), with an "Other (specify)" fallback. Admin maintains the list.
- **Age:** Grouped into the hospital's brackets (values still needed).
- **Branch → Rank:** A cascading dropdown. Choosing a branch shows only that branch's ranks (Navy: ASN, ENS, etc.). "Others" lets the encoder pick or type a type, e.g. Civilian or Dependent.
- **Reports:** Monthly, quarterly and annual. The user chooses the format: on-screen, PDF, Excel or print.
- **Hosting:** Runs locally on the hospital network, not on the internet.
- **Historical data:** No import of old Google Sheets.
- **Frontend:** Designed with Claude Design.

## 4. Report counts (per OPD / ER / Admission)
- Total patients
- Sex
- Age range
- Diagnosis
- Branch of service, with rank breakdown inside each branch
- Department (meaning still to be confirmed)

## 5. Open questions
1. **Patient ID:** What uniquely identifies a patient (AFP serial no., hospital record no.)? Without one, the system can't tell that a patient is repeating.
2. **Department:** Is it the hospital service that handled the patient, or the patient's own unit?
3. **Age brackets:** What are the exact ranges?
4. **Marines:** Separate branch, or under Navy? Their rank list is different.
5. **Report template:** Try to get a copy of the current one.
6. **Report period counting:** Is a patient counted once per month, quarter and year, with total visits shown as a separate line?
