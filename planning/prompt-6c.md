# Prompt 6c for Claude Code: Lists screen

_Paste the block below into Claude Code after Step 6b is merged and pushed._

```
Step 6c – Lists screen (Admin only). Create branch step-6c-lists. Match the "Dropdown lists" tab in prototype/prototype.html (layout, wording, messages). Rules: planning/rbac.md, planning/database-schema.md, planning/security.md section 2. Routes from planning/setup-and-structure.md: GET /settings/lists, POST /settings/{list}, PUT /settings/{list}/{id}, DELETE /settings/{list}/{id}.
1. Three panels as in the prototype: Diagnosis list ("N active + Other"), Age brackets ("N active"), and Ranks by branch with one button per branch (Army, Navy, Air Force, Marines, "Others (patient types)") showing each branch's count. Only active items show; items in Trash don't.
2. Add: a diagnosis, a rank to the selected branch, or an age bracket (From age, To age; blank To means "and above", shown as "60 & above"). Names are trimmed with spaces collapsed, 2 to 120 characters (ranks 2 to 60). Names must be unique in their list (ranks: within the branch), ignoring upper/lower case. If the same name is in Trash, say: "“<name>” is in Trash. Restore it from Trash instead."
3. Edit inline (Save / Cancel; Enter saves, Esc cancels), with the same checks as Add. Editing a name changes how it shows on every past visit and in open-month reports (visits store the item's ID), so the edit row shows the hint: "Use Edit only to fix spelling. To use a different item, add a new one." Closed months keep their saved report copy.
4. Age brackets: ages 0 to 120, From must not be above To, and a bracket can't overlap another active one: "Overlaps 18–25. Adjust the ages." Show brackets sorted by From age. Keep the prototype's hint about sample brackets.
5. Ranks: Move up / Move down buttons on each rank, so the seniority order can be kept. That order is used in the Encode rank dropdown and later in the report's Branch × Rank table. Diagnoses are listed alphabetically, with "Other (specify)" last.
6. "Other (specify)" items show "Fixed", with no Edit, Delete or Move.
7. Delete: the same inline "Move to Trash?" row and reason dropdown as Records, using the List item reasons. A deleted item disappears from Encode, but past visits and reports keep showing it. Prepare restore checks in TrashService for the Trash step (a restored bracket can't overlap an active one; a restored name can't duplicate an active one), with tests.
8. Server: role:admin plus the manage-lists Gate, a Form Request for add and edit, and every check repeated there. Audit entries come from the existing observers.
9. Add new keyboard shortcuts to docs/keyboard-shortcuts.md.
10. Pest tests: Admin can open it, every other role gets 403; add / edit / delete for each list; duplicate name (including different case) rejected; name in Trash gives the restore message; rank name unique only within its branch; bracket overlap and From above To rejected; blank To saved as "and above"; Other (specify) can't be edited, deleted or moved; Move up / down changes the order Encode shows; deleted item gone from Encode but still shown on old visits in Records.
Run php artisan test (patient_census_test only), npm run types:check and npm run build. Fix errors. Stop and summarize, with steps to test it in the browser. Don't commit until I say okay.
```
