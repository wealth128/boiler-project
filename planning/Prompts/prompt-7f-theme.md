# Prompt 7f for Claude Code: Light and Dark theme

_Paste after 7d-3 is merged and pushed._

```
Step 7f – Light / Dark theme. Create branch step-7f-theme. The starter kit already has an appearance setting (hooks/use-appearance.tsx, components/appearance-tabs.tsx, pages/settings/appearance.tsx, HandleAppearance middleware). Reuse it; no new packages.
1. Theme choice Light | Dark | System for every signed-in user: a small toggle (sun/moon icon) in the top bar plus the existing Settings > Appearance page. Remembered per user in the browser (cookie used by HandleAppearance, so the first paint has no white flash); default Light.
1a. Light theme colors (decided 2026-10-23): blue and white with navy, the known Navy/AFP look. White page and white cards; navy (about #0B2545) for the top bar, sidebar, headings and primary buttons; a clear medium blue (about #1E5AA8) for links, focus rings, selected tabs and active menu items; a very light blue (about #EEF4FB) for hover rows, zebra rows and panel headers; grey only for borders and muted text. Status colors (red, amber, green) stay but are toned to fit the blue palette. Keep the report chart colors (OPD, ER, Admission, Male, Female) the same in both themes so a report looks the same everywhere. Show me the token list (name, light value, dark value) in the summary.
2. Dark mode for every screen: define the colors as theme tokens (background, card, border, text, muted text, navy primary, the status colors bad/warn/ok and their soft backgrounds) in light and dark versions and use them everywhere. No hard-coded white/black left in components. Check: sidebar, top bar, cards, tables (zebra rows), forms, dropdowns, dialogs, toasts, warning bars, pagination, the crop dialog, the import preview, Records cards, Reports (cards, KPI cards and charts: chart colors that stay readable on dark, axis and labels light), login page.
3. Always light, whatever the theme: Print, PDF, Excel, the print view, and the Hospital settings A4 preview (they are paper).
4. Contrast: text and controls meet WCAG AA in both themes (4.5:1 for text, 3:1 for icons/borders that matter). Focus rings visible in both.
5. Tests: the theme cookie is read on first paint; Print/PDF HTML carry no dark classes; a contrast check of the token pairs (a small script or test that computes the ratios). Browser steps for each screen in both themes.
6. Update docs/user-manual.md (how to switch theme) and docs/keyboard-shortcuts.md if a shortcut is added.
7. When I approve, before the commit, update planning/project-history.md (section 0, section 5, decisions log).
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize. Don't commit until I say okay.
```
