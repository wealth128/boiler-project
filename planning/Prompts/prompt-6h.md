# Prompt 6h for Claude Code: Settings screen (hospital details)

_Paste after 6g is merged and pushed._

```
Step 6h – Settings screen for hospital details (Admin only). Create branch step-6h-settings. There is no separate screen for this in prototype/prototype.html, so follow the style of the Lists and Backups pages (sidebar page, panels). Rules: planning/database-schema.md (settings table), planning/rbac.md, planning/security.md section 2, planning/project-history.md section 6 (the six report settings). Route: GET /settings/hospital and PUT /settings/hospital (add both to the route map in planning/setup-and-structure.md).
1. Navigation: "Hospital settings" in the sidebar for Admin only.
2. Form with: Hospital name (2–150 characters), Office line (default "Admission / Administrative Office"), Prepared by name and title, Noted by name and title (default title "Commanding Officer"). Trim spaces. Save with one button; show "Settings saved."
3. Logo: optional upload, PNG or JPG only (no SVG), max 1 MB, max 1000 × 1000 px; check the real file type, not just the extension. Store it outside public/ (e.g. storage/app/private/logo.png) and serve it only through a route for signed-in users. "Remove logo" button. Show the current logo on the page.
4. Live preview under the form: the report sheet header and signature block exactly as Reports, PDF and Print will show them (reuse the existing report header component), using the values being typed.
5. Reports screen, PDF, Excel and Print use these values (they already read the six keys; add the logo to the screen header, PDF and Print when one is set). Empty values keep today's defaults.
6. Audit log: every save writes "settings.updated" with old → new values for each changed field, and "settings.logo_changed" / "settings.logo_removed" (no image data in the log). Add labels to the AuditActions map.
7. Server: role:admin plus a Gate; encoder, viewer and system_admin get 403 on all three routes (page, save, logo). Validate in a Form Request.
8. Add any new keyboard shortcuts to docs/keyboard-shortcuts.md (or note that there are none).
9. Pest tests: Admin can view and save; each field's limits; defaults when empty; the report screen and PDF show the saved name, office line, signatories and logo; logo upload accepts PNG/JPG and rejects SVG, a renamed non-image, over 1 MB and over-size dimensions; the logo route needs sign-in and is not under public/; remove logo works; audit entries with old → new values; encoder, viewer and system_admin get 403.
10. When I approve, before the commit, update planning/project-history.md: section 0 (Quick Start), section 5 (status table) and the decisions log for anything newly decided.
Run php artisan test (patient_census_test only), npm run types:check and npm run build. Fix errors. Stop and summarize, with steps to test it in the browser. Don't commit until I say okay.
```
