# Prompt 7d for Claude Code: Presentable Excel export

_Paste after 7c is merged and pushed._

```
Step 7d – Make the Reports Excel export presentable. Create branch step-7d-excel-export. maatwebsite/excel only. Numbers and sheet contents stay exactly the same (tests must prove the values are unchanged); only the look changes. Keep every text cell written as text (formula safety).
1. Every sheet (Summary, OPD, ER, Admission):
   - Title block at the top: hospital name (bold, larger), office line, report title ("Monthly Census Report – ER"), period ("October 2026" / "Q3 2026" / "01-Oct-2026 to 15-Oct-2026"), "Final" or "Not final – …" exactly as on screen, and "Generated DD-Mon-YYYY HH:mm by <user>".
   - The hospital logo at the top left if one is set (current settings, or the frozen copy for a closed month if a logo was frozen; if not frozen, current). Still NO signature pictures in Excel (decided in 6h).
   - Each table (Patients and Visits, Sex, Age range, Diagnosis, Branch × Rank) with a section heading, a navy header row with white bold text, thin borders, light zebra rows, bold total rows, numbers right-aligned with thousands separators, text left-aligned, wrapped long names.
   - Column widths fitted to the content; header row of each table repeated when printing; freeze panes below the title block.
   - Page setup: A4, landscape when the table is wide (Branch × Rank), fit to 1 page wide, margins 1.5 cm, footer "Page x of y" and the hospital name.
   - Prepared by / Noted by names and titles at the bottom with signature lines (text only).
2. Sheet tab names: "Summary", "OPD", "ER", "Admission". File name unchanged.
3. Tests: same values as before in every cell that holds a number or label; title block present; styles applied (bold header, borders, number format); logo drawing present when set and absent when not; no formulas anywhere.
4. Put a sample generated file in the summary steps (where to click) and update the user manual.
5. When I approve, before the commit, update planning/project-history.md (section 0, section 5, decisions log).
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize. Don't commit until I say okay.
```
