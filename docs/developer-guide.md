# Developer Guide

_Version: DRAFT (Step 7g: only the Performance section) · Matches system: Step 7g · Phase 7.5 writes the rest: set-up on a new PC, the folder map, the path of one request, one feature end to end, releasing a new version._

## Performance

Measured on 2026-10-26 on the developer's laptop (Intel Core 7 150U, 2 fast and 8 power-saving cores, MySQL 8.0.45, PHP 8.5) with a busy year in `patient_census_test`: 15,000 patients, 22,191 visits (about 60 ER visits a day for a year), 100,000 audit entries within the year and 300 backup runs (`PerformanceSeeder`). Server time is from the first line of PHP to the end of the response; each value is the median of 5 requests (exports: the better of 2).

### Targets (production mode) and results

| What | Target | Before 7g | After 7g |
|---|---|---|---|
| Every ordinary screen (server time) | under 300 ms | up to 1,507 ms (Audit log, Record filter) | 31–127 ms |
| Patient search (as you type) | under 150 ms | 35–58 ms | 33–71 ms |
| Reports > Generate, monthly, counted | under 1 s | 221 ms | 199 ms (55 ms when remembered) |
| Excel, monthly / annual | under 2 s / 4 s | 0.27 / 0.35 s | 0.31 / 0.34 s |
| PDF, every tab (23–27 pages) | – | 1.2–1.6 s | 1.4–1.5 s |

Screens that changed most (production mode, ms): Audit log Record filter 1,507 → 41; Audit log last page 595 → 125; Records > Patients last page 372 → 44; Records > Visits name search 306 → 57; Records > Visits page 1 126 → 33; Trash 210 → 38; System health 277 → 70; Encode after Save & New 30 → 12 queries. The full table per screen, in three modes, is in the 7g step summary (planning/project-history.md).

### What keeps it fast (keep these when you change code)

- **Props on every page are cached for 60 seconds.** `AdminAlerts` holds the Admin warning bars (backups, test restore, USB copy, audit clean-up, scheduler) in one cache entry; `HospitalSettings::shared()` holds the name, subtitle and logo version. Any change forgets them at once: models with the `ClearsAdminAlerts` trait (backup runs, marks, test restores, audit clean-up runs), `SchedulerHeartbeat::beat()` when the scheduler comes back, and `Setting` saves. A problem that comes only with time (no backup for 26 hours, scheduler silent for 5 minutes) shows within 60 seconds. **A new record type that changes a bar must use `ClearsAdminAlerts`.**
- **Long lists page ids-first:** `TablePaging::paginate($query, $request)` reads only the ids of the page from an index, then the 10–50 rows with their relations. Same rows and page numbers as `paginate()`, but page 2,000 does not read 20,000 rows first. Use it for any list that can grow past a few thousand rows.
- **No query per row.** Load what a row needs for the whole page (`with`, `withExists`; Trash loads "still used?" with `withExists`, see `TrashPolicy::HAS_VISITS`). A policy that asks "is this month closed?" for every row runs inside `MonthClosure::remembering(...)`, which reads the closed months once (for building a page only, never around a close or reopen). `tests/Feature/Performance/QueryCountTest.php` fails when a list starts running a query per row.
- **Indexes for the lists** (migration `2026_10_26_000002_add_performance_indexes`): `visits (deleted_at, visited_at)`, `patients (deleted_at, last_name, first_name)`, `audit_logs (action, created_at)`. Every list either hides Trash or shows only Trash, so each index starts with `deleted_at` and then the sort column. The report queries keep `visits (category, visited_at, deleted_at)`.
- **Search:** the patient search adds "last name starts with the typed text up to the first space or comma", which every match fulfils, so the name index is used; the client waits 250 ms after the last key and cancels the previous request. Records > Visits finds the matching patients first and then their visits (one query over the whole year's visits otherwise). The Audit log Record filter looks the record's id up first and lists the "deleted permanently" actions instead of `LIKE '%.purged'`. `tests/Feature/Performance/SameResultsTest.php` keeps the old queries and checks the new ones give the same rows.
- **Reports:** a built report is remembered for an hour per period and data version (Step 7d-4), so Export right after Generate does not count again (tested). The PDF's small logo is made once per logo file (`picture-small:` cache key with the file's time and size).
- **Excel:** fixed column widths, styles applied per look over ranges, no formulas, no pre-calculation. Merged title cells use "hide" mode (`MERGE_CELL_CONTENT_HIDE`): the other cells are always empty, so nothing needs blanking.
- **System health:** starting `mysql --version` costs about 0.2 s, so the page remembers that one answer for 10 minutes per program path; `census:health` always runs it.
- **Static files:** `public/.htaccess` lets browsers keep `public/build/assets` for a year (the names change with every build) and compresses scripts, styles and SVG. Pages and JSON are not compressed (they carry the sign-in token; unsafe over HTTPS). The logo route sends `private, max-age=86400` with a `?v=` that changes with every new logo.

### Excel and PDF, phase by phase

Production mode, busy year, 600 × 600 logo; median of 3 runs, ms (`scripts/perf/export-breakdown.php`).

| Phase | Monthly | Quarterly | Annual |
|---|---|---|---|
| Report counted (cache empty) / remembered | 96 / 8 | 548 / 9 | 1,004 / 8 |
| Excel: rows of the sheets | 1 | 2 | 1 |
| Excel: cells | 12 | 12 | 13 |
| Excel: styling | 201 | 219 | 224 |
| Excel: native charts (12) | 0.5 | 0.5 | 0.5 |
| Excel: hidden "Chart data" sheet | 2 | 2 | 2 |
| Excel: logo | 2 | 2 | 2 |
| Excel: writing the file (charts add about 4 ms) | 30 | 27 | 32 |
| Excel: reading the file back for the download | 34 | 33 | 25 |
| **Excel total (after the report)** | **287** | **323** | **308** |
| PDF: logo (remembered small copy) | 0.5 | 0.5 | 0.5 |
| PDF: HTML | 8 | 7 | 7 |
| PDF: load | 42 | 91 | 56 |
| PDF: layout (dompdf) | 1,041 | 1,291 | 1,358 |
| PDF: writing (fonts embedded whole, 1.3 MB) | 284 | 305 | 312 |

On the developer's own dev database (15 visits), the Excel file takes 0.14–0.20 s and the PDF 0.5–0.7 s in either mode. Styling is the biggest part of Excel; the PDF is dompdf laying out the pages. Font subsetting would make the PDF 76 KB instead of 1.3 MB but writing about 90 ms slower; it stays off (decided in 7d-3).

### What is slow only on a developer PC

| Cause | Effect |
|---|---|
| **Windows power throttling.** On the Balanced power plan Windows moves background processes without a window, such as `php artisan serve` from `composer run dev`, to the power-saving cores after about a minute. | 3–4 times slower PHP (the empty `/up` route: 13 ms fresh, 45–90 ms throttled). This was most of the "Excel is slow" feeling: 1.1–1.3 s per Excel in dev mode before 7g. |
| No config, route, view and event caches; `APP_DEBUG=true` | About 100–150 ms more per request (the framework reads every config file and route each time). |
| Vite dev server | The first full page load fetches hundreds of small modules instead of a few built files. |
| `php artisan serve` handles one request at a time | A slow request (a PDF) makes the next click wait. |
| Xdebug | Not installed on this PC. If you install it, expect everything 2–3 times slower while it is on. |

The server runs Apache as a service, with the caches built, debug off and OPcache on (`docs/operations-guide.md`, section 11). Set its power plan to High performance.

### Bundle sizes

Built with `npm run build`, before and after 7g (no change: every page was already its own chunk loaded on demand). Every page loads `app` (118 KB, 34 KB gzipped), the shared library chunk `utils` (React, React DOM, Inertia and helpers: 373 KB, 116 KB gzipped) and its own page chunk (1–57 KB; Users is the largest). CSS 90 KB (16 KB gzipped).

### How to measure again

Only on `patient_census_test`. Never on `patient_census`, except `export-breakdown.php --readonly`, which opens a read-only MySQL session.

1. Production copy (optional): copy `system` to a scratch folder without `node_modules` and `tests`, put a `.env` with `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`, `DB_DATABASE=patient_census_test`, then `composer install --no-dev --optimize-autoloader`, `php artisan optimize`, and make sure `public/hot` is not there. Your own `.env` stays as it is.
2. Run, from `system` (Git Bash):
   ```
   PERF_OUT=/c/temp/perf PERF_PASSWORD=<8+ characters> sh scripts/perf/run-measure.sh "$(pwd)" 8123 dev-run 5
   PERF_OUT=/c/temp/perf PERF_PASSWORD=<same> sh scripts/perf/run-measure.sh /c/temp/prodcopy 8124 prod-run 5
   ```
   The first run migrates `patient_census_test` fresh, seeds it with `PerformanceSeeder` (accounts `perf.admin`, `perf.encoder1`–`4`, `perf.viewer`, `perf.sysadmin` with your password) and keeps a dump in `PERF_OUT`; every run reloads that dump. It prints one line per screen and writes `<label>.json` and a per-request log with every query slower than 50 ms. Add `throttled` at the end to measure the way `composer run dev` really runs.
3. Excel and PDF phases: `powershell -File scripts\perf\run-unthrottled.ps1 scripts\perf\export-breakdown.php <system dir> monthly 2026-09 --dbcache --logo=<a png>`.
4. Running `php artisan test` afterwards empties `patient_census_test` again (RefreshDatabase).

The tools in `scripts/perf/` are for developers only; nothing on the server uses them.
