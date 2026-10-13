# Prompt 6b for Claude Code: Records screen

_Paste the block below into Claude Code. main is up to date with Step 6a._

```
Step 6b – Records screen (Admin only). Create branch step-6b-records. Match the Patient Records screen in prototype/prototype.html (Visits and Patients tabs, layout, wording, messages). Rules: planning/rbac.md, planning/database-schema.md (Trash and delete rules), planning/mvp-scope.md.
1. Visits tab (GET /records/visits): filters From, To, Category (All), Branch (All), and Search (name, Patient ID or Visit ID). Default range: first day of last month to today. Keep the filters in the URL so Refresh and Back keep them. Columns as the prototype: Date & time, Visit, Patient ID, Name, Sex, Age, Branch / Rank (shows the "Other (specify)" text), Diagnosis (with remarks under it), Category chip, Encoded by. Newest first. Show "N visits match" in the header. Visits in Trash never show.
2. Paging: 50 rows per page on the server, with page numbers, instead of the prototype's "latest 60".
3. Patients tab (GET /records/patients): search by name or Patient ID, sorted by last name, columns Patient ID, Name, Sex, Birthdate, Visits (active visits only), paged the same way. Patients in Trash never show.
4. Edit (visits): opens the Encode screen in edit mode for that visit (same form, same VisitPolicy and Form Request as Step 6a). After "Save changes" or "Cancel edit", return to Records with the same filters and page.
5. Delete (visits and patients): the inline "Move to Trash?" row from the prototype, reason required, through TrashService. Patient delete is refused with "Move this patient's visits to Trash first." while they have active visits. Visits in a closed month show no Edit or Delete, just "Month closed".
6. Every check is repeated on the server (role, VisitPolicy, TrashPolicy). Audit entries come from the existing observers.
7. Add any new keyboard shortcuts to docs/keyboard-shortcuts.md (for example Enter in the reason box confirms, Esc cancels).
8. Pest tests: Admin sees both tabs, encoder / viewer / system_admin get 403; each filter and the search work; visits and patients in Trash are excluded; paging; delete needs a reason; patient with active visits can't be deleted; closed-month visit can't be edited or deleted; the Patients tab visit count ignores visits in Trash.
Run php artisan test (patient_census_test only), npm run types:check and npm run build. Fix errors. Stop and summarize, with steps to test it in the browser. Don't commit until I say okay.
```
