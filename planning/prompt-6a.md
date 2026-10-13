# Prompt 6a for Claude Code: Encode screen

_Paste the block below into Claude Code after Step 5c is committed and merged into main._

```
Step 6a – Encode screen. Create branch step-6a-encode. Match the Encode screen in prototype/prototype.html (layout, wording, error messages, behavior). Rules: planning/mvp-scope.md section 2, planning/rbac.md, planning/database-schema.md.

0. Docs: remove Claude Design from planning/.
   - architecture.md: replace "Screens are built from the Claude Design output." with "Screens are built by Claude Code to match prototype/prototype.html."
   - phase5-foundation.md: remove "and the final Claude Design screens".
   - screen-brief.md: at the top add "Claude Design is no longer used. prototype/prototype.html is the final screen reference." and remove the other Claude Design mentions.
   - brainstorm-notes.md, clarification-list.md, prompt-5.md: these are history, so keep the text but add "(Dropped 2026-10-13: screens are built by Claude Code to match prototype/prototype.html.)" right after each Claude Design mention.
   - CLAUDE.md under Stack, add: "When updating vite-plus, update the vite alias in package.json to the same version, or the build fails."
   Afterwards, search planning/ and CLAUDE.md for "Claude Design" and list every line that still mentions it.
1. Find returning patient: search by last name or Patient ID (GET /patients/search, JSON, max 10 results, patients in Trash excluded). Picking one fills last name, first name, M.I., sex and birthdate, plus branch and rank from their latest visit, and shows "Returning patient". "Not this patient" clears it. Without a pick, show "New patient" with the next Patient ID as a preview only; the real ID is set on save.
2. Fields, required marks and error messages exactly as the prototype. Age fills from birthdate (as of today; when editing, as of the visit date) and stays editable, 0–120. Birthdate can't be in the future. M.I. max 2 letters, saved uppercase without periods.
3. Rank list is filtered by branch; the label is "Type" when the branch is Others. Only active list items are offered, but when editing, the visit's current item still shows even if it was turned off later. Choosing "Other (specify)" shows a text box that is then required (rank_other / diagnosis_other).
4. Category comes from the user's office and is locked. ER encoders and Admin can choose any category.
5. Save & New (POST /visits) in one transaction: create the patient if new, or update the returning patient's name, M.I., sex and birthdate (this corrects all their visits, as the schema says); then create the visit with branch, rank, age, diagnosis, category, remarks, the encoder and the server time. Show the toast "Saved P-000123 · Full Name · ER", clear the form and focus the search box. Tab moves through fields in order, Enter saves, Clear resets the form.
6. "My entries today" panel: this user's visits today, newest first, each with Edit. Visits in Trash don't show. Edit loads the visit into the same form ("Editing V-…", Cancel edit, Save changes) and saves through PUT /visits/{visit} using VisitPolicy (encoder: own visit, same day; Admin: any; nobody while the month is closed).
7. Repeat every check on the server in Form Requests: rank must belong to the branch, items must be active (unless unchanged on edit), and the category rule from item 4. Audit entries come from the existing observers.
8. Pest tests: new patient plus visit; returning patient keeps the same Patient ID; rank from another branch rejected; Other (specify) without text rejected; inactive item rejected; non-ER encoder can't change category; same-day edit rule; edit blocked in a closed month; viewer and system_admin get 403; search excludes patients in Trash.
Run php artisan test (patient_census_test only), npm run types:check and npm run build. Fix errors. Stop and summarize, with steps to test it in the browser.
```

**Before pasting, commit Step 5c:**
```
git add .
git commit -m "Step 5c: vite-plus 0.3.3, docs updated, Claude Design removed from plan"
git checkout main
git merge step-5c-cleanup
git push
```
