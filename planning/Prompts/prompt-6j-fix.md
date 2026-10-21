# Prompt 6j-fix for Claude Code: Fixes from the 6j code review

_Paste on `step-6j-import`, after Claude Code has finished 6j and summarized, before approving._

```
Fixes from the 6j code review (stay on step-6j-import). Don't change the import rules or the file format.
1. Closed month race (ImportService confirm ~72/126 and undo ~272): take the same lock MonthService uses (month-close:YYYY) for every year in the chosen rows (or the batch's years for undo), sorted, then re-check closed months INSIDE the transaction before saving. A month closed while the import was being confirmed must refuse those rows (or the whole confirm) with the closed-month message. Tests.
2. Double confirm (ImportController ~450, ImportService ~72-116): claim the staged file atomically (Cache::lock('import-confirm:'.$token) plus mark/rename the file as used inside it). A second confirm with the same token says "This file was already imported." and saves nothing. Also: if at confirm a row now has a NEW "possible duplicate visit" warning that wasn't in the preview, do not import it unless it was ticked; list it in the result. Tests (two confirms with the same token; same file staged twice and confirmed twice).
3. Performance: analysis runs ~5 queries per row and runs again on every page load. Let VisitFields accept the preloaded branch/rank/diagnosis lists, batch the patient name-match and duplicate-visit lookups with whereIn, and cache the analysis per token (in the staged folder, cleared with it) so index/paging doesn't re-analyze. Put GET records/import under the import throttle too. Add a test that analyzing 2,000 rows uses a bounded number of queries (e.g. < 50).
4. At save, re-read the chosen branch/rank/diagnosis inside the transaction (with lockForUpdate or a fresh query) and refuse a row whose item went to Trash or became inactive since the preview.
5. "Save as new patient" is accepted only for rows that had the name+birthdate match warning; ignore it for other rows.
6. Undo: lock the patient rows (lockForUpdate) before checking "no other visits" and moving them to Trash.
7. Rows beyond the limit: if the sheet has data past the row limit (or past the 10,000-row scan), refuse the file with "This file has more than 2,000 rows. Split it into smaller files." instead of dropping rows silently.
8. Memory: read the sheet in read-only/data-only mode (or chunked) so a sheet formatted down to a million rows gives the friendly "file too large" message instead of a 500. Test with a large formatted file if possible.
9. Purge staged import files every 15 minutes (not hourly), so nothing stays more than about 75 minutes.
10. CSV encoding: if the CSV is not valid UTF-8 (Excel's Windows "CSV" saves ANSI/Windows-1252), convert it from Windows-1252 to UTF-8 before reading, so "PEÑA" and "Ñ" survive. Test with a Windows-1252 CSV containing ñ. Mention in the How to fill sheet: "In Excel, save as CSV UTF-8 if you use CSV; .xlsx is best."
11. Confirm these are done (they may already be): AuditActions labels for import.created and import.undone; the Records Visits page shows the "Imported" label and Written by; import tests exist for every rule in the 6j prompt.
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize. Don't commit until I say okay.
```
