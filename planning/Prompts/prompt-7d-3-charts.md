# Prompt 7d-3 for Claude Code: Reports redesign (dashboard look), charts and export speed

_Paste after 7d-2 is merged and pushed. Design reference: `planning/design/report-reference.png` (picked by me 2026-10-23). Take its look, not its content: our system has no kiosks, insurance or reasons-for-visit._

```
Step 7d-3 – Reports redesign with charts, and export speed. Create branch step-7d-3-charts. Look at planning/design/report-reference.png and follow its style. No new packages: charts are server-drawn inline SVG (one PHP chart builder) used by the screen, Print and PDF, so all three match; Excel uses native PhpSpreadsheet charts. Numbers come only from the 7d-2 report data (never re-count). All tables from 7d-2 stay, below the charts.

1. Reports screen layout (like the reference):
   - Header: title "Census Report", subtitle with the period and "from encoded visits"; on the right a segmented control Month | Quarter | Year | Custom (replaces the Period type dropdown; month/year pickers stay next to it) and the export buttons: "Export PDF" (primary, navy, with icon), "Excel" and "Print" (secondary).
   - Category tabs as pills: Summary | OPD | ER | Admission (keep the URL and keyboard rules).
   - Row of KPI cards: Patients, Visits, New patients, Returning patients. Each card: small icon + label, big number, a small sparkline of the daily (or monthly) trend, and a change badge vs the previous period (green up / red down with %, "vs September 2026"). No badge when there is no previous data.
   - "Patients by Branch": donut with the total in the center, legend with each branch and its % (Army, Navy, Air Force, Marines, Others), plus the AFP vs Others split under it.
   - "Top Diagnoses": bars with the value on each bar, highest first (Summary: top 10 in the chart, top 20 in the table; category pages: top 10 in the chart, every diagnosis in the table). Use horizontal bars if names are long so they never get cut.
   - "Daily Census": stacked columns per day (OPD / ER / Admission), by month for quarter/year.
   - "Sex": Male vs Female bar with counts and %. "Age × Sex": pyramid (males left, females right).
   - "This period vs previous": paired bars (Summary only).
   - Then the 7d-2 tables in collapsible cards. Cards: white, rounded corners, light border, soft shadow, small uppercase label, same spacing as the reference. Final / "Not final – …" shown as a small pill in the header.
   - Works on phone, tablet and desktop (cards stack on small screens).
2. Chart rules: flat 2D, no 3D or gradients except the light area under sparklines; a fixed palette from the reference (navy, blue, teal, steel, olive green, amber, grey) with OPD, ER, Admission, Male, Female each a fixed color used everywhere; every bar/segment labelled with its number or %, so it reads in black-and-white print; title, subtitle, legend when more than one series; no overlapping text; "No visits in this period" instead of an empty chart; on screen hover/focus shows the exact value; each SVG has role="img" and an aria-label summary.
3. Print and PDF: the same header, KPI cards, charts and tables in a print-friendly A4 layout (light background always, even in dark mode), each chart kept on one page with its table where it fits, page order Summary then OPD, ER, Admission for "All categories", Prepared by / Noted by and signature rules unchanged.
4. Excel: each sheet gets the KPI block (as styled cells), then native charts (donut for branches, bars for top diagnoses, columns for the daily census) beside or above their tables, sized to fit A4 one page wide, same colors, titles and data labels on; tables unchanged from 7d-2.
5. Saved copies: charts are drawn from the saved numbers, so a final report's charts never change.
6. Speed of Print, PDF and Excel (they are slow today). MEASURE first, then fix, then measure again; report before/after times for monthly, quarterly and annual reports, Excel/PDF/Print, open and closed periods, on the dev PC with about 1 year of seeded ER visits (~60 a day) on patient_census_test only.
   - List the top causes of the time (queries, building the report, PhpSpreadsheet styling, dompdf, logo).
   - Count the report once: cache the built report per period + category + ReportBuilder::VERSION + a "data changed" stamp that changes on any visit/patient/list/month change; Export after Generate reuses it; closed periods use their saved copy.
   - No per-row or per-day queries; each section is one grouped query (or a few). Test: a full monthly report and an annual report each use a bounded number of queries (e.g. < 40).
   - Excel: style whole ranges, reuse style objects, no formula calculation on save. PDF: simple HTML/CSS, dompdf font cache on, logo embedded once and small. SVG charts built once per report and reused.
   - Targets on the dev PC: monthly export under 3 s, annual under 8 s. If not met, say why and what it would take.
   - Buttons show "Preparing the file…" and can't be clicked twice.
7. Tests: chart data equals table data (screen props, PDF HTML SVG, Excel chart ranges); KPI change % correct; zero-data message; fixed colors; aria-labels; query bounds; cache invalidated by a new visit. Update docs/samples (xlsx and a pdf with made-up data) and docs/user-manual.md (the new Reports screen with a screenshot placeholder).
8. When I approve, before the commit, update planning/project-history.md (section 0, section 5, decisions log: Reports redesign after planning/design/report-reference.png, chart list, colors, donut allowed for branches, export speed fixes and measured times).
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize, with browser test steps (desktop and phone, Print preview, PDF, Excel, black-and-white print preview) and the before/after times. Don't commit until I say okay.
```
