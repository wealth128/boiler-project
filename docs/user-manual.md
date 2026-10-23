# User Manual

_Version: DRAFT (Step 6j) · Matches system: Step 6j · Only the Admin section on importing visits exists so far. Phase 7.5 writes the rest (Encoder, Admin, System Admin, Viewer) and adds screenshots._

---

## Admin

### Hospital settings: the preview (Admin)

Under **Settings > Hospital settings**, the **Preview** shows the report header and the signature block on a sheet of A4 paper, centred and shrunk to fit the panel, as they will print. What you type, and a picture you just chose, show in it at once, before you click **Save settings**. The title and the month are a sample.

On a wide screen (1280 px and up) the form is on the left and the preview stays in view on the right while you scroll. On a smaller screen the preview is under the form. On a phone the sheet is small to fit; the form above it is the readable part.

### Importing visits after a downtime (draft)

**When to use it.** The system was down and the offices wrote their visits in the downtime template (or on paper). Now the system is back and you bring those visits in. It is only for visits written during a downtime. It does not import old Google Sheets history.

**Where.** Left menu **Patient Records**, then the **Import** tab. Only the Admin sees it.

#### Before the downtime: download the template

1. On **Import**, click **Download template**.
2. Copy the file to every office PC and print the **Visits** and **How to fill** sheets. Do it again every 3 months and after any change to the dropdown lists. The file name shows the date.

The file has four sheets: **How to fill** (instructions), **Visits** (where the offices write; one row for each visit), **Example** (four sample rows) and **Lists** (the choices in the dropdowns, copied from the system that day).

#### After the downtime: import

1. **Upload.** Under "2. Upload the filled file", click **Choose file**, pick the `.xlsx` or `.csv` file, click **Upload and check**. Up to 2 MB and 2,000 rows. Nothing is saved yet.
2. **Read the preview.** The top line says how many rows are OK, how many have warnings and how many have errors. Each row shows its number in the file, a result and the reason in plain words.

   | Result | Meaning | What you do |
   |---|---|---|
   | **OK** | Nothing to say | Nothing. It is ticked. |
   | **Warning** | It can be imported, you decide | Read the note. Untick the row if it is wrong. |
   | **Error** | It cannot be imported | Fix the file and upload it again. The row has no tick box. |

   **Warnings you may see**
   - "Will be linked to P-000012": a patient with the same name and birthdate exists, so the visit is added to that patient. If it is a different person, tick **Save as a new patient instead**.
   - "Possible duplicate visit": that patient already has a visit in the same category at that date and time. It is **not** ticked. Tick it only if it is a real second visit.
   - "Same patient, date and time as row N": the same patient is in the file twice. It is not ticked.
   - "... will count as <name> in reports": the text typed under "Other (specify)" is a name that is on the list. It is saved as typed and counted under the listed name.
   - "Sex or M.I. is different ... The patient record is not changed": the visit is added; the patient's own record is left as it is.

   **Errors you may see.** A required value is missing, a bad date or time, a visit in the future or before January 2026, a visit in a closed month ("September 2026 is closed. Reopen September 2026 first."), a birthdate after the visit date, an age outside 0 to 120, a name that is not on a list, a rank of another branch, a typed "Other" text that is missing or not needed, text that is too long, or a Patient ID that is not found, in Trash or for another person.
3. **Choose the rows.** Each row has a small box on its left. **To tick a row is to click its box so a check mark (✓) shows. A ticked row is included in the import. An unticked row (empty box) is left out.** Click the box again to untick it. Only ticked rows are imported; nothing else is saved.
   - **Show** lets you look only at warnings or errors. It does not change any tick.
   - **Tick the usual rows** puts the ticks back to how they were when the preview opened: OK rows and warning rows are ticked, possible-duplicate rows are not. Use it if you changed ticks and want to start over.
   - **Untick all** removes every tick, so nothing is selected. Then tick only the rows you want.
   - Neither button saves anything. Only **Import N visits** (step 4) saves the ticked rows.
   - Rows with an error have no box: they cannot be imported.
4. **Import.** Click **Import N visits** and confirm. The chosen rows are saved together: all of them or none. The visits are dated with the date and time in the file, are encoded by you, and keep the name in **Written by**. New patients get a Patient ID as usual.
5. **Check.** In **Patient Records > Visits** the visits show **Imported** and **Written by**. They count in the reports like any other visit.

The uploaded file waits on the server for up to one hour, until you confirm or click **Cancel**. After that it is deleted. A file can be imported only once: if you click **Import** twice, the second time says "This file was already imported."

If, while you were importing, a visit that matches a row (same patient, date, time and category) was saved on the Encode screen, that row is **not** imported. The message after **Import** lists its row number. The rest is imported. If a month was closed in the meantime, nothing is imported and the message names the month.

If you use a CSV file, save it as **CSV UTF-8** in Excel. `.xlsx` is best.

#### Past imports and Undo

Under **Past imports** every import is listed with its file name, who imported it, and how many rows were in the file, imported and skipped.

**Undo import** moves the whole import to Trash with the reason "Wrong import". Patients that only this import created go to Trash too, unless they have other visits. Single visits can be restored from **Trash** (restore the patient first). Undo is not possible while one of the import's visits is in a closed month: reopen the month first.

#### If something goes wrong

See `docs/troubleshooting.md`, section 9: every message and what to do. The operations steps (what to do before and during a downtime) are in `docs/operations-guide.md`, section 8.

## All users

### Using the system on a phone, a tablet or a laptop

Every screen fits the screen it is opened on. Nothing makes the whole page slide sideways.

- **Menu.** On a screen narrower than 1024 px (phones and tablets) the left menu becomes a **menu button** (three lines) at the top left of the blue bar. Tap it to slide the menu in. It has the same links and the same **ADMINISTRATION** heading, which you can hide or show. The menu closes when you choose a page or tap outside it. On a wider screen the left menu is always there.
- **Lists with many columns** (Trash, Audit Log, Backups, System health, User Accounts, Patients and the import preview) slide sideways inside their own box on a small screen. The first column stays in view while you slide.
- **Patient Records, Visits** shows one card per visit on a phone (name, category, date, IDs, branch and rank, diagnosis, who encoded it, and **Edit** and **Delete**). From 768 px up it is the table.
- **Encode Patient.** The boxes are in one column on a phone, two on a tablet and three on a laptop. **Save & New** and **Clear** are full width on a phone. **My entries today** goes under the form on a screen narrower than 1280 px.
- **Reports.** The category tabs (OPD, ER, Admission, Summary) slide sideways when they do not fit. The report page fits the width of the screen. Print and PDF look the same as before.
- **Warning bars, messages and dialogs** fit the screen. A tall dialog scrolls inside itself.
- Text is at least 14 px and every button, box and menu link is at least 40 px high on a phone or tablet.

### Reports: what each page shows (Admin and Viewer)

The **Summary** tab and the **OPD**, **ER** and **Admission** tabs show the same tables on the screen, in Print, in the PDF and in the Excel file, in the same order and with the same numbers. On the screen each table is a panel: click its title (or press **Enter** or **Space** on it) to fold or open it. Folding is only for reading on the screen: **Ctrl + P** and **Print** always show every panel open, even one you folded.

**How patients are counted.** A patient counts once in each category in the period, using their latest visit there for age, branch and rank. **Visits** counts every visit. Diagnoses are counted per visit. Visits in Trash are never counted. On the **Summary** tab a patient seen in two categories counts once, using their latest visit in any category, so the Summary total can be less than the three categories added together.

**Summary tab** (all categories together)
1. **Patients and visits**: each category and the total.
2. **Sex**: Male, Female and total patients for OPD, ER, Admission and all categories.
3. **Age range by sex**: one row for each age range from Dropdown Lists, with Male, Female and Total. "No bracket" appears only when an age fits no range.
4. **Top 20 diagnoses (per visit, all categories)**: highest first, with the visits and the percent of all visits. There is no "Others" line. The full list is on each category page.
5. **Branch of service**: patients of each branch with the percent, then AFP personnel (Army, Navy, Air Force, Marines) against Others (Dependent, Retiree, Civilian Employee, Civilian, Other).
6. **Ranks by branch**: patients of each rank, grouped by branch in seniority order, with the branch subtotal.
7. **New and returning patients**: new means no earlier visit before the period; returning means seen before.
8. **Daily census (visits)**: the chart above it shows each day. The table holds only the totals: OPD, ER, Admission and Total visits for the whole period, and the busiest day, for example "Busiest day: 04-Sep-2026 (48 visits)". There are no day by day rows. A period longer than 31 days (a quarter, a year or a long custom range) keeps one row for each month, a total row and the busiest month.
9. **Compared with the previous period**: patients and visits now and in the period of the same length just before (the month before, the quarter before, the year before, or the same number of days before a custom range), with the change and the percent change. It is left out when that earlier period starts before January 2026.

**OPD, ER and Admission tabs**
1. **Sex**, with the total.
2. **Age range by sex**, with the totals.
3. **Diagnosis (per visit)**: every diagnosis, highest count first (equal counts by name), with the percent of the category's visits and a total row.
4. **Branch of service and rank**: each branch with its subtotal and its ranks, then AFP personnel and Others.
5. **New and returning patients**: new means no earlier visit in this category before the period.
6. **Daily census** for the category: the chart shows each day; the table holds the total visits and the busiest day (by month for a long period, with one row for each month).

A category with no patients shows "No visits encoded for ... in this period." on the screen, in Print and in the PDF. Its Excel sheet shows the same tables with zeros.

**Closed months.** Closing a month saves all of these tables, including the comparison, so they do not change when a patient is corrected later. A closed month saved by an older version of the system (this can only happen on a test PC) is counted live and shows "Not final – no saved copy".

**Print and PDF.** Each table is kept together on one page where it fits. With **All categories** the order is Summary, OPD, ER, Admission, each on a new page.

**The buttons.** **Export PDF** and **Export Excel** save the file without leaving the page. While the file is being made the button says **Preparing the file…** and both buttons are off, so a second click cannot start it twice. If it fails, a red line says "The file could not be made. Check your connection and try again." A period you just looked at is not counted again: the file is made from the same figures, so it is quick (measured on the development PC with a year of busy ER data: a month's PDF about 1.5 to 2 seconds and its Excel file under 1 second, a year's PDF under 4 seconds). Anything that changes a visit, a patient, a list or a month makes the next file count again.

### Reports: the charts (Admin and Viewer)

Every chart sits **above the table it comes from**. It is made from that table's numbers, so a chart can never show a figure its table does not. The tables are unchanged.

**Summary tab**
1. **Daily census** (above Daily census): one column for each day, stacked OPD, ER and Admission, with the day's total on top. For a quarter or a year, one column for each month. This chart is where the day by day figures are: the table below shows only the totals and the busiest day. When the days are too narrow for every total, only the busiest column shows its total; point at a column to see its exact figures.
2. **Male vs Female** (above Sex): one bar split by sex, with the counts and the percent of all patients.
3. **Age range by sex** (above Age range by sex): a pyramid with males on the left, females on the right, one row for each age range (oldest at the top), and the count at the end of each bar.
4. **Top 10 diagnoses** (above the Top 20 table): horizontal bars, highest first, the visits at the end of each bar. A long name wraps onto more lines and is never cut.
5. **Branch of service** (above its table): one bar for each branch, with the patients and the percent.
6. **This period vs previous** (above the comparison table): two bars each for patients and for visits, with the change and the percent change. It is left out when there is no earlier period to compare with.

**OPD, ER and Admission tabs:** the **Top 10 diagnoses** and the **Daily census** of that category, each above its table.

**Colors.** They are the same everywhere (screen, Print, PDF and Excel): **OPD** light blue, **ER** dark navy, **Admission** green; **Male** dark blue, **Female** amber. Nothing depends on color: every bar has its number written on or beside it, there is a legend when a chart has more than one series, and the shades differ a lot in brightness, so a black and white print still tells them apart.

**On the screen.** The chart fits the screen, from a 360 px phone to a wide desktop (a phone gets a narrower drawing with the same readable text). Point at a bar, or Tab to it, to outline it and see its exact value. A screen reader reads a one line summary of each chart, for example "Daily census, September 2026. ER 120 visits, OPD 40, Admission 8."

**No visits.** A chart with nothing to show says **No visits in this period** instead of drawing an empty chart.

**Print and PDF.** A chart is kept with its table on one page when they fit, and a chart is never split across two pages. In the PDF the charts are drawn as sharp vector pictures, with text of at least 9 pt (chart titles are not bold in the PDF, they are larger). A sample is in `docs/samples/census-report-sample.pdf`.

**A closed month** is drawn from its saved copy, so its charts never change after the month is closed.

**Excel.** Each chart is a real Excel chart (click it to see its data), placed above its table on the same sheet, as wide as the table, with its title, the numbers on the bars and the same colors. It reads the cells of its own table, so changing a number in the table changes the chart. The daily census chart is the exception: its table holds only the totals, so it reads the hidden sheet **Chart data**. The **Age range by sex** chart in Excel shows the males and females side by side instead of back to back. When a chart has nothing to show, the sheet has a line such as "Daily census: No visits in this period." instead. When you print a sheet, a page break is put above a chart that would not fit on the rest of the page.

### Reports: the Excel file (Admin and Viewer)

**Where.** Left menu **Reports**, choose the period, click **Generate**, then click **Export Excel**. The browser saves `census-report_<period>_all.xlsx`. A sample is in `docs/samples/census-report-sample.xlsx` (made-up names and a made-up logo, with the charts).

**What is in it.** Four sheets: **Summary**, **OPD**, **ER** and **Admission**. The file always holds every category, whichever tab is open. A fifth sheet, **Chart data**, is hidden (it is not in Excel's Unhide list): it holds the day by day figures that the daily census charts draw, because the daily census table shows only the totals. Do not delete the file's hidden sheet, or those charts go blank. The numbers are the ones on the screen; only the look is made for reading and printing.

- **Top of every sheet.** The hospital logo (when one is set in Hospital settings), the hospital name, the office, the report title (for example "Monthly Patient Census Report – ER"), the period, **Final** or **Not final – …** exactly as on the screen, and "Generated 23-Oct-2026 14:05 by <your name>".
- **Tables.** Each table has a heading, a dark blue header row, thin borders, light stripes, bold totals, and numbers lined up on the right with thousands separators. Long names wrap.
- **Bottom of every sheet.** Prepared by and Noted by, with their titles and a signature line above each name. No signature pictures are put in Excel; use the PDF for those.
- **Charts.** Each chart sits above its table (see **Reports: the charts**), so a sheet is longer than before: the tables are exactly as they were.
- **Printing from Excel.** A4 portrait, fitted to one page wide, 1.5 cm margins, footer with the hospital name and "Page x of y". The top rows stay in view while you scroll. Excel can repeat only one block of rows on each printed page, so the report title and period repeat, not each table header.

### The hospital logo

When the Admin has set a logo in **Hospital settings**, it also shows in the blue bar at the top of every page, next to the hospital name (every role), and above the form on the login page. With no logo, only the hospital name shows.

### Long lists: pages and rows per page

Visits, Patients, Past imports, User Accounts, Trash, Audit Log, Backup history and System health all use the same footer under the table.

- **Showing 41–50 of 1,234** tells you which rows you are looking at.
- **« First**, **‹ Prev**, **Next ›** and **Last »** move one step or to the ends. Between them are at most three page numbers around the page you are on, for example 4 5 6. The page you are on is highlighted.
- **Page [ ] of N** jumps to any page. Choose the page from the list. If there are more than 200 pages it is a small box: type the number and click **Go**. A number past the end opens the last page.
- **Rows per page** shows 10, 20 or 50 rows (10 is the default). It goes back to page 1 and keeps your filters. The choice stays in the web address, so Refresh keeps it. Any other number typed in the address is treated as 10.
- On a phone only **‹ Prev**, **Page x of N** and **Next ›** show.

The footer is hidden when there are 10 rows or fewer.

### System health (Admin)

The top line always says how many problems and warnings there are, for example "1 problem, 2 warnings". The list shows Problems first, then Warnings, then OK, so a problem is never hidden on page 2. Use **Show** to look at All, Problems, Warnings or OK only. The choice stays in the web address.

### User Accounts

The list is paged, 10 accounts per page, ordered by name. After you add or edit an account, unlock it, reset its password, deactivate it or move it to Trash, you stay on the same page.
