# Prompt 6d-1 fix for Claude Code: Diagnosis table per visit, every diagnosis named

_Decided 2026-10-15. Send on branch step-6d1-reports, before approving 6d-1._

```
Change to Step 6d-1 (stay on branch step-6d1-reports). Decision: the Diagnosis table counts per visit and names every diagnosis. Sex, Age range and Branch/rank stay per patient (latest visit), unchanged.
1. Diagnosis table, for each category: count every visit in the category and period (visits in Trash excluded), grouped by diagnosis. List every diagnosis that has visits, sorted by count (highest first), then by name. No Top 10 cut-off and no "Others" line.
2. "Other (specify)" visits show the typed text (diagnosis_other) as their own lines, merged ignoring upper/lower case and extra spaces, mixed into the same sorted list (e.g. "Snake bite 2"). If the typed text is empty, use "Other (unspecified)".
3. Title the table "Diagnosis (per visit)". Its total equals the category's Visits. Show a Total line at the bottom, like the Sex table.
4. Remove TOP_DIAGNOSES and the Others logic from ReportBuilder. Bump ReportBuilder::VERSION to 3 so saved copies in the old shape are not used.
5. Update the docs: planning/mvp-scope.md section 4 (replace "Top 10 plus Others" with this rule), planning/database-schema.md section 4 (Diagnosis comes from every visit, not the latest visit), and planning/project-history.md (decisions log: "Diagnosis table is per visit and lists every diagnosis by name, typed Other text included; decided 2026-10-15"; and fix the 6d-1 notes in section 6).
6. Tests: replace the Top 10 / Others tests with: a patient with 3 visits and 3 different diagnoses shows all 3; Diagnosis total equals Visits while Sex total equals Patients; typed Other text listed by name and merged across case and spaces; sort by count then name; visits in Trash excluded; a closed month's saved copy still shows its old diagnosis lines.
Run the full php artisan test (patient_census_test only), npm run types:check and npm run build. Fix errors. Stop and summarize. Don't commit until I say okay.
```
