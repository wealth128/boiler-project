# Prompt 7c for Claude Code: Responsive screens, Hospital settings preview, logo

_Paste after 7b is merged and pushed._

```
Step 7c – Responsive UI and visual fixes. Create branch step-7c-responsive-ui. No new packages; Tailwind only. Don't change any rule or number.
1. Every screen adjusts to the screen size. Check at 360 px (phone), 768 px (tablet), 1024 px (small laptop) and 1440 px+ (desktop):
   - Sidebar becomes a menu button (hamburger) with a slide-in panel below 1024 px; the ADMINISTRATION group still collapses.
   - Tables: on small screens either scroll sideways inside their own box (never the whole page) with the first column kept visible, or switch to stacked cards for the most-used tables (Records Visits, Encode "today" list). Choose per table and list your choices.
   - Forms (Encode, Users, Lists, Hospital settings, Import): fields stack in one column on phones, two columns on tablets, current layout on desktop; buttons full width on phones.
   - Reports: tabs scroll sideways on small screens; the report sheet scales to fit the width; Print/PDF unchanged.
   - Warning bars, toasts, dialogs and the crop dialog fit on a phone.
   - No text smaller than 14 px; tap targets at least 40 px.
2. Hospital settings preview: use the full width of its panel and show the report header and signature block like a real page (A4 proportion, centered, scaled to fit, light page shadow), so it looks like the printout. On wide screens put the form on the left and the preview on the right (sticky); on smaller screens put the preview under the form.
3. Hospital logo: today it shows only on the Reports header, Print, PDF and the Hospital settings preview. Also show it (small, from the same signed-in route) in the top bar next to the system name, and on the login page above the form (the login page needs a public, cached, read-only logo route that serves ONLY the logo file; no signatures). If no logo is set, show the current text only. Tests: login-page logo route serves only the logo, 404 when none; signatures still need sign-in.
4. Write the screen-size checks in the summary as browser test steps (Chrome DevTools device toolbar: iPhone SE, iPad, laptop). Update the user manual where screens changed.
5. When I approve, before the commit, update planning/project-history.md (section 0, section 5, decisions log) and planning/security.md (public logo route).
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Stop and summarize. Don't commit until I say okay.
```
