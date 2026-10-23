# Prompt 7d-3 for Claude Code: Charts on the reports (screen, Print, PDF, Excel)

_Paste after 7d-2 is merged and pushed. Charts are added ON TOP of the tables; the tables stay. Decided 2026-10-23: "as long as it is presentable"._

```
Step 7d-3 – Charts on the reports. Create branch step-7d-3-charts. No new packages: draw charts on the server as inline SVG (one PHP chart builder) used by the screen, Print and PDF, so all three show the same chart; in Excel use native PhpSpreadsheet charts (already installed with maatwebsite/excel). Numbers come only from the 7d-2 report data (never re-count). Tables stay exactly as they are; each chart sits above its table.

1. Charts:
   - Daily census: stacked columns per day (OPD / ER / Admission), by month for quarterly/annual. Summary + each category page.
   - Male vs Female: one horizontal 100% bar with counts and %. Summary.
   - Age × Sex pyramid: males left, females right, one row per age bracket, counts at the bar ends. Summary.
   - Top 10 diagnoses: horizontal bars, highest first, count at the end of each bar (names wrap, never cut). Summary + each category page.
   - Branch of service: horizontal bars (Army, Navy, Air Force, Marines, Others). Summary.
   - This period vs previous: paired bars for patients and visits, with +/– and %. Summary (skip when no previous data).
2. Presentable rules (test them visually and in code):
   - Clean, flat, 2D; no pie, no 3D, no gradients, no shadows. White background, light grid lines, readable axis labels (at least 12 px on screen, 9 pt in PDF/print).
   - One small palette from the app's navy theme: OPD, ER and Admission each a fixed, clearly different color with good contrast, used the same everywhere; Male and Female two fixed colors. Colors must also be distinguishable in black-and-white print (different shades plus a pattern or labels), and every bar has its number written on or beside it, so nothing depends on color alone.
   - Every chart has a title, a short subtitle (period, "patients" or "visits"), a legend when there is more than one series, and no overlapping text. Long labels wrap or truncate with the full text in a tooltip on screen.
   - Zero data: show "No visits in this period" instead of an empty chart.
   - Charts never split across printed pages; Print/PDF keep chart + its table together when they fit.
   - Screen: responsive (fits 360 px phone to wide desktop), hover/focus shows the exact value; each SVG has role="img" and an aria-label summary (e.g. "ER 120 visits, OPD 40, Admission 8").
   - Excel: each chart placed beside or above its table on the same sheet, sized to fit A4 one page wide, chart titles and data labels on, same colors.
3. Saved copies: charts are drawn from the saved numbers (closed months use their snapshot), so a final report's charts never change. No snapshot change needed.
4. Tests: chart data equals the table data for each chart (screen props, PDF HTML SVG, Excel chart series ranges); zero-data message; colors fixed per category; aria-label text; no chart in Excel refers to cells outside its table; dompdf renders the SVG (PDF contains the chart). Update docs/samples/census-report-sample.xlsx and add docs/samples/census-report-sample.pdf (made-up data), and a "Charts" part in docs/user-manual.md.
5. When I approve, before the commit, update planning/project-history.md (section 0, section 5, decisions log: charts added above tables, server-drawn SVG + native Excel charts, no new package, chart list, color rules).
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize, with browser test steps (screen at phone and desktop size, Print preview, PDF, Excel, black-and-white print preview). Don't commit until I say okay.
```
