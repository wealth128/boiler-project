# Prompt 7d-2 for Claude Code: Complete report content (screen, Print, PDF, Excel)

_Paste after 7d is merged and pushed. Remove any section below that you don't want before pasting._

```
Step 7d-2 – Complete report content. Create branch step-7d-2-report-content. Screen, Print, PDF and Excel must show the SAME sections and numbers (one source: ReportBuilder → ReportSheetResolver). Keep the counting rules: Patients = each patient once per category per period (latest visit for sex, age, branch/rank); Visits = every visit; Diagnosis counted per visit. Typed "Other" merged as today. Keep formula safety and the 7d Excel styling.

A. Summary tab / Summary sheet (all categories together) – today it only has totals. Add:
  1. Patients and visits by category (keep) + grand total.
  2. Sex: Male / Female / Total patients, per category (OPD, ER, Admission) and all categories.
  3. Age range × Sex: one table, rows = age brackets (from Lists), columns = Male, Female, Total; all categories.
  4. Top 20 diagnoses (all categories, per visit), highest first, with count and % of visits. Under it: "Full list on each category page." No "Others" row (the per-category pages list every diagnosis).
  5. Branch of service: patients per branch (Army, Navy, Air Force, Marines, Others) and the AFP personnel vs Others (Dependent, Retiree, Civilian Employee, Civilian, Other) split.
  6. Ranks: patients per rank, grouped by branch in seniority order, with branch subtotals (all categories).
  7. New vs returning patients: patients whose first-ever visit is in this period vs patients seen before.
  8. Daily census (monthly and custom ranges up to 31 days only): one row per day, columns OPD, ER, Admission, Total visits; total row; busiest day noted. For quarterly/annual: the same table by month instead of by day.
  9. Compared with the previous period (same length): patients and visits now vs before, and the change (+/– and %). Skip when there is no earlier data (before January 2026).

B. Each category tab / sheet (OPD, ER, Admission) – today it has patients/visits, sex, age range, diagnosis, branch × rank. Change/add:
  1. Diagnosis: every diagnosis (unchanged rule), now sorted by count, highest first (ties alphabetical), with % of visits.
  2. Age range × Sex table (replaces the plain age-range table, or add next to it).
  3. Branch × Rank: add branch subtotals and the AFP vs Others split.
  4. New vs returning patients for that category.
  5. Daily census for that category (same rules as A8).

C. Saved copies: add the new sections to the ReportBuilder output and bump ReportBuilder::VERSION to 4, so new closed months/quarters/years save them. The hospital server has no closed months yet (it starts fresh), so older v3 copies exist only on dev PCs; for those, keep today's fallback (count live) and show "Not final – no saved copy". Tests: v4 snapshot holds every section; a closed month's numbers don't change after a patient correction.

D. Layout: Print/PDF – each section keeps its table together where possible (no table split over pages if it fits on one), Summary first then OPD, ER, Admission when "All categories". Excel – each section in the 7d style; landscape for the Daily census sheet area if wider than one page. Screen – sections in collapsible panels in the same order, with the Summary tab first.

E. Tests: every new section's numbers on a seeded dataset (hand-counted expected values), the same values on screen props, PDF HTML and Excel cells; sex totals add up to patients; age × sex totals add up; top 20 is ordered and capped; daily census sums to visits; new + returning = patients; previous-period comparison; empty category; v4 snapshot. Update docs/user-manual.md (report sections explained) and the sample file docs/samples/census-report-sample.xlsx.

F. When I approve, before the commit, update planning/project-history.md (section 0, section 5, decisions log: report content list, Top 20 on Summary only, VERSION 4) and planning/mvp-scope.md (report contents).
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize, with browser test steps. Don't commit until I say okay.
```
