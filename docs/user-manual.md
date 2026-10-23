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
