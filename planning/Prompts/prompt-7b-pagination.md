# Prompt 7b for Claude Code: One pagination everywhere

_Paste after 7a is merged and pushed._

```
Step 7b – Pagination everywhere. Create branch step-7b-pagination. Reuse the shared TablePaging (server) and components/shared/pagination.tsx (client); change them once, use them everywhere.
1. Rows per page on EVERY long list: default 10, dropdown 10 / 20 / 50, kept in the URL (?per_page=), server accepts only 10/20/50. Check and fix: Records > Visits, Records > Patients, Records > Import > Past imports, Trash, Audit log, Backups history (backups and test restores), User accounts (add paging; it has none), System health (see 3). List any other table you find and what you did.
2. Page buttons never grow: show « First, ‹ Prev, at most 3 page numbers around the current page (e.g. 4 5 6), Next ›, Last », plus "Page [dropdown] of N" to jump to any page (a select when N ≤ 200, a small number box with Go when larger), and the text "Showing 41–50 of 1,234". Keyboard accessible; current page has aria-current="page". On a phone, show only Prev, "Page x of N" and Next.
3. System health: sort Problems first, then Warnings, then OK, so paging never hides a problem on page 2; add the same paging (default 10, 10/20/50) and a small filter (All / Problems / Warnings / OK) kept in the URL. The top of the page always says "N problems, N warnings".
4. Changing rows per page goes back to page 1 and keeps the filters; a page number past the end goes to the last page (as today).
5. Tests: per_page accepted only 10/20/50 on every listed page; page window shows max 3 numbers; jump to page; System health order and filter; User accounts paging. Update docs/keyboard-shortcuts.md and the user manual.
6. When I approve, before the commit, update planning/project-history.md (section 0, section 5, decisions log).
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize, with browser test steps. Don't commit until I say okay.
```
