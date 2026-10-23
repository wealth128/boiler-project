# Keyboard Shortcuts

_Last updated: 2026-10-21 (Step 7b paging) · One section per screen. Print and post beside each PC._

You can do everything on these screens without the mouse.

---

## Encode Patient

### Move around
| Key | What it does |
|---|---|
| **Tab** | Go to the next field |
| **Shift + Tab** | Go back to the previous field |

**Order of the fields (Tab):** Find returning patient → Last name → First name → M.I. → Sex → Birthdate → Age → Branch of service → Rank / Type → (Specify) → Diagnosis → (Specify diagnosis) → Category → Remarks → Save & New → Clear

- While search results are showing, **Tab** goes through them first.
- After a patient is picked, **Not this patient** comes right before Last name.
- **(Specify)** appears only when "Other (specify)" is chosen.
- **Category** is skipped when it is set by your office.
- When editing a visit, **Cancel edit** comes first instead of the search box.
- A visit opened with **Edit** on Patient Records goes back to Patient Records after **Save changes** or **Cancel edit**, with the same filters and page.

### Save
| Key | What it does |
|---|---|
| **Enter** | Save, from any box or list of the form (not from the search box) |
| **Ctrl + Enter** | Save, from inside Remarks (there, Enter alone starts a new line) |

After **Save & New**, the form clears and the cursor goes back to **Find returning patient**, ready for the next patient.

### Find returning patient
| Key | What it does |
|---|---|
| Type 2 or more letters | Search by last name (e.g. `Santos`) or Patient ID (e.g. `P-000012` or `12`) |
| **↓ (Down arrow)** | Move from the search box into the results |
| **↑ / ↓** | Move between results (↑ on the first result goes back to the search box) |
| **Enter** | Pick the highlighted patient |
| **Esc** | Close the results |

After picking a patient, the cursor jumps to the first empty field, usually **Diagnosis**.

### Choosing in a field
| Field | Key | What it does |
|---|---|---|
| Sex | **← / →** (or ↑ / ↓) | Switch between Male and Female |
| Any list (Branch, Rank, Diagnosis, Category) | Type the first letters | Jump to that choice, e.g. `N` for Navy |
| Any list | **Alt + ↓** | Open the list |
| Birthdate | Type the numbers | Fill month, day and year; **← / →** move between them |
| Age | **↑ / ↓** | Add or subtract 1 |

### Buttons
| Key | What it does |
|---|---|
| **Enter** or **Space** on a button | Press it (Save & New, Clear, Not this patient, Cancel edit, Use this patient, No, save as a new patient) |

### Where the cursor goes on its own
- **Page opens:** Find returning patient (or Last name when editing a visit)
- **Something is missing or wrong:** the first field with a message
- **"A patient with this name and birthdate already exists":** the warning box. Press **Tab** to reach **Use this patient**, then **Enter**.
- **After "Use this patient":** the Save & New button. Press **Enter** to save.

### My entries today (right side)
| Key | What it does |
|---|---|
| **Tab** to an **Edit** link, then **Enter** | Open that visit in the form |

---

## Patient Records > Import (Admin)

No special shortcuts. Everything works with **Tab**, **Shift + Tab**, **Space** and **Enter**.

| Key | What it does |
|---|---|
| **Tab** | Next control. Order: Download template, File, Upload and check; then, in the preview: Show, Tick the usual rows, Untick all, each row's checkbox and "Save as a new patient instead", Import, Cancel; then the Undo import links |
| **Space** | Tick or untick a row, or a "Save as a new patient instead" box |
| **Enter** or **Space** on a button | Press it. **Import** and **Undo import** ask once more; **Esc** closes that question |

---

## Patient Records (Admin)

### Move around
| Key | What it does |
|---|---|
| **Tab** | Go to the next tab, filter, link or button |
| **Shift + Tab** | Go back |
| **Enter** on **Visits** or **Patients** | Switch tabs (each tab keeps its own filters) |

**Order (Tab), Visits tab:** Visits → Patients → From → To → Category → Branch → Search → each row's **Edit** and **Delete** → page numbers
**Order (Tab), Patients tab:** Visits → Patients → Search → each row's **Delete** → page numbers

**Rows per page (both tabs):** the last **Tab** stop under the table. Choose 10, 20 or 50 (type `1`, `2` or `5`, or **↑ / ↓**). It goes back to page 1, keeps the filters, and each tab remembers its own choice (it stays in the address, so **F5** keeps it). The default is 10.

### Filters
| Field | Key | What it does |
|---|---|---|
| From, To | Type the numbers | Fill month, day and year; **← / →** move between them. The list updates by itself. |
| Category, Branch | Type the first letters, or **↑ / ↓** | Choose; the list updates right away |
| Search | Type | The list updates after a short pause. Search by name, Patient ID (e.g. `P-000012` or `12`) or Visit ID (e.g. `V-000045`). |
| Search | **Esc** | Clear the search box |

The filters stay in the address, so **F5 (Refresh)** and **Alt + ← (Back)** keep them.

### Rows
| Key | What it does |
|---|---|
| **Tab** to **Edit**, then **Enter** | Open the visit on the Encode screen |
| **Tab** to **Delete**, then **Enter** | Open the "Move to Trash?" row under it; the cursor goes to the Reason list |

Visits in a closed month show "Month closed" instead of Edit and Delete.

### "Move to Trash?" row
| Key | What it does |
|---|---|
| Type the first letters, or **↑ / ↓** | Choose a reason from the list, e.g. `D` for Duplicate entry |
| **Alt + ↓** | Open the Reason list |
| **Enter** in the Reason list | Move to Trash (a reason is required) |
| Choose **Other (specify)** | The cursor moves to the text box. Type 10 to 200 characters, then **Enter**. |
| **Esc** | Cancel; the cursor goes back to that row's **Delete** |

---

## Dropdown Lists (Admin)

### Move around
| Key | What it does |
|---|---|
| **Tab** | Go to the next item button, box or branch |
| **Shift + Tab** | Go back |

**Order (Tab):** Diagnosis list (each item's **Edit** and **Delete**) → Add a diagnosis → **Add** → Age brackets (each **Edit** and **Delete**) → From age → To age → **Add** → branch buttons → each rank's **Move up**, **Move down**, **Edit** and **Delete** → Add to branch → **Add to** branch

"Other (specify)" shows "Fixed" and has no buttons, so **Tab** skips it.

### Add
| Key | What it does |
|---|---|
| **Enter** in "Add a diagnosis" or "Add to (branch)" | Add it. The box clears and the cursor stays there for the next one. |
| **Enter** in From age or To age | Add the age bracket. Leave To age blank for "and above". |
| **↑ / ↓** in From age or To age | Add or subtract 1 |

### Ranks by branch
| Key | What it does |
|---|---|
| **Tab** to a branch, then **Enter** or **Space** | Show that branch's ranks |
| **Tab** to **Move up** (↑) or **Move down** (↓), then **Enter** | Move the rank one place. The cursor stays on the same rank, so press **Enter** again to keep moving it. |

### Edit row
| Key | What it does |
|---|---|
| **Tab** to **Edit**, then **Enter** | Open the edit row. The cursor goes to the name (or From age) with the text selected. |
| **Enter** | Save |
| **Esc** | Cancel; the cursor goes back to that item's **Edit** |

### "Move to Trash?" row
Same keys as on Patient Records: choose a reason, **Enter** to move it to Trash, **Esc** to cancel.

---

## User Accounts: Move to Trash

| Key | What it does |
|---|---|
| Type the first letters, or **↑ / ↓** | Choose a reason from the list |
| Choose **Other (specify)** | The cursor moves to the text box. Type 10 to 200 characters. |
| **Tab** to **Move to Trash**, then **Enter** | Move the account to Trash |
| **Esc** | Close without moving anything |

The User Accounts list is paged like every long list (see **Pages and rows per page**): 10 accounts per page, ordered by name. After adding, editing or moving an account to Trash you stay on the same page.

---

## Trash (Admin)

### Move around
| Key | What it does |
|---|---|
| **Tab** / **Shift + Tab** | Next / previous button. Order: **Empty Trash** → each row's **Restore** and **Delete permanently** → page numbers |
| **Enter** / **Space** | Press the focused button |

Rows that can't be deleted permanently show "Can't delete permanently" with the reason, and **Tab** skips to the next row's **Restore**.

### Restore
| Key | What it does |
|---|---|
| **Tab** to **Restore**, then **Enter** | Restore the item right away. If it can't be restored, the reason shows under that row. |

### "Delete permanently?" and "Empty Trash?" row
| Key | What it does |
|---|---|
| Type the ID | The ID is shown in the row: the Visit No., Patient ID, username, name, age bracket (e.g. `18-25` with a normal hyphen, or `60 & above`) or the month as `2026-09`. For Empty Trash, type `EMPTY TRASH`. |
| **Enter** | Delete permanently (only when the text matches) |
| **Esc** | Cancel; the cursor goes back to the button you started from |
| **Tab** | Delete permanently → Cancel |

### Pages
| Key | What it does |
|---|---|
| **Tab** to **‹ Prev**, a page number, **Next ›**, **First** or **Last**, then **Enter** | Go to that page (10 rows per page unless you change it) |
| **Tab** to **Rows per page**, then type `1`, `2` or `5`, or **↑ / ↓** | Show 10, 20 or 50 rows; goes back to page 1 and keeps the choice after Refresh |

---

## Audit Log (Admin)

Read-only. No new shortcuts: it uses the same keys as Patient Records.

### Move around
| Key | What it does |
|---|---|
| **Tab** / **Shift + Tab** | Next / previous. Order: From → To → User → Action → Record → each row's **Details** → page numbers |
| **Enter** / **Space** on **Details** | Show or hide what changed under that entry ("Field: old → new") |

### Filters
| Field | Key | What it does |
|---|---|---|
| From, To | Type the numbers | Fill month, day and year; **← / →** move between them. The list updates by itself. |
| User, Action | Type the first letters, or **↑ / ↓** | Choose; the list updates right away |
| Record | Type | The list updates after a short pause. Type a Visit No., Patient ID or username exactly, e.g. `V-000045`. |
| Record | **Esc** | Clear the box |

The filters stay in the address, so **F5 (Refresh)** and **Alt + ← (Back)** keep them. Pages: **Tab** to **‹ Prev**, a page number, **Next ›**, **First** or **Last**, then **Enter** (10 entries per page unless you change it). **Tab** to **Rows per page** and choose 10, 20 or 50 (type `1`, `2` or `5`, or **↑ / ↓**); it goes back to page 1 and keeps the choice after Refresh.

---

## Warning bars at the top of Admin pages

Red and yellow bars (scheduler stopped, backup failed, USB copy or test restore overdue) have an **x** at the right.

| Key | What it does |
|---|---|
| **Tab** | Move to the **x** of a bar ("Close this warning"). It has a visible outline. Bars come before the page content in the Tab order, after the menu. |
| **Enter** / **Space** | Close the bar |

The x only hides the warning in this browser. Nothing on the server changes. A yellow bar comes back tomorrow, a red bar at your next sign-in, and any bar at once if its text changes. The Backups and System health pages always show the full status and have no x.

---

## Backups (Admin)

No new shortcuts. The screen uses the standard keys: **Tab** / **Shift + Tab** to move, **Enter** / **Space** to press a button.

| Key | What it does |
|---|---|
| **Tab** / **Shift + Tab** | Next / previous. Order: **Run backup now** → **Mark USB copy done** → **Mark test restore done** → **Run test restore now** → **Copy to USB** → page numbers |
| **Enter** / **Space** | Press the focused button, which opens a question |
| **Enter** | In the question ("Run a backup now?", "Run a test restore now?", "Mark ... as done?"): confirm. The confirm button is selected first. |
| **Esc** | Cancel the question |
| **↑ / ↓** | In the "Copy the latest backup to USB?" window: choose the USB drive in the list (type its letter to jump to it). **Tab** to **Look again** or **Copy to USB**, then **Enter**. |

Pages: **Tab** to **‹ Prev**, a page number, **Next ›**, **First** or **Last**, then **Enter** (10 backups per page unless you change it). **Tab** to **Rows per page** and choose 10, 20 or 50; the choice stays in the address.

---

## Reports

Nothing is counted until you press **Generate**. The choices are then kept in the address bar, so Refresh and Back keep them.

| Key | What it does |
|---|---|
| **Tab** / **Shift + Tab** | Next / previous box. Order: Period type → Month (or Quarter and Year, or Year, or From and To) → Generate → the open category tab → Print / PDF choice → Export PDF → Export Excel → Print |
| Type the first letters, or **↑ / ↓** | Choose in Period type, Month, Quarter or Year |
| **Alt + ↓** | Open a list |
| **Enter** | Generate, from any box |
| Type in From / To | Dates go day, month, year in the order your browser shows. **↑ / ↓** change the part the cursor is in. |

### Category tabs: OPD | ER | Admission | Summary
One category is shown at a time (ER when none is chosen). The tab is kept in the address bar with the period choices (`?category=er`), so Refresh and Back keep it. **Tab** goes to the open tab only; the arrow keys move between the tabs.

| Key | What it does |
|---|---|
| **→** / **←** | Move the focus to the next / previous tab (from Summary to OPD and back) |
| **Home** / **End** | Move the focus to the first (OPD) / last (Summary) tab |
| **Enter** / **Space** | Open the tab that has the focus (the report loads again for that tab) |

### Export PDF, Export Excel and Print (Admin and Viewer)
They use the report on the screen, so press **Generate** first if you changed a choice. **Print / PDF** takes **This category** (the open tab) or **All categories** (the summary and each category, every one on a new page with its own header and signatures). Excel always has a Summary sheet and one sheet per category.

| Key | What it does |
|---|---|
| **Tab** | After the tabs: Print / PDF choice → Export PDF → Export Excel → Print |
| **←** / **→** or **↑** / **↓** (on the Print / PDF choice) | Switch between This category and All categories |
| **Enter** / **Space** | Press the focused button. The PDF or Excel file is saved by the browser. Print opens the print view in a new tab and starts the Print dialog there. |
| **Ctrl + P** | In the print view: print again. On the Reports screen: print the open tab only (the menu, controls and buttons are left out, and the whole Diagnosis list is shown) |
| **Ctrl + W** | Close the print view tab |

### Close month, Reopen month and Delete month report (Admin, Monthly reports)
| Key | What it does |
|---|---|
| **Tab** | After Generate and the export buttons: Close month (or Reopen month) → Delete month report |
| **Enter** / **Space** | Press the focused button |
| **Enter** | In the Close / Reopen question: confirm (the confirm button is selected first) |
| **Esc** | Cancel the Close / Reopen question, or the Delete month report row |
| **Alt + ↓**, then **↑ / ↓** and **Enter** | In the Delete month report row: choose the reason. **Enter** on the list confirms. |

### Diagnosis list (per visit)
Shows 10 diagnoses at a time. A longer list scrolls inside its box, and the Total line stays at the bottom.

| Key | What it does |
|---|---|
| **Tab** to the Diagnosis list | Put the cursor in the list |
| **↑ / ↓** | Scroll one line |
| **Page Up / Page Down** | Scroll one page |
| **Home / End** | Go to the top / bottom |

---

## Hospital Settings (Admin)

Use **Tab** and **Shift + Tab** to move between the boxes, the file buttons, **Remove logo** / **Remove signature** and **Save settings**. **Enter** in a text box saves the form.

### Crop dialog (logo and signatures)
Opens after you choose a picture file. **Tab** goes through: crop area → Zoom slider → Rotate 90° → Remove white background (signatures) → Cancel → Save.

| Key | What it does |
|---|---|
| **Arrow keys** (in the crop area) | Move the picture |
| **+** / **-** (in the crop area) | Zoom in / out |
| **← / →** (on the Zoom slider) | Zoom a little |
| **Home / End** (on the Zoom slider) | Smallest / largest zoom |
| **Enter** or **Space** (on Rotate 90°) | Turn the picture a quarter turn |
| **Space** (on Remove white background) | Tick / untick |
| **Esc** | Close the dialog and keep nothing (same as Cancel) |

---

## Pages and rows per page (every long list)

The same footer is under Visits, Patients, Past imports, User Accounts, Trash, Audit Log, Backup history and System health. It says "Showing 41–50 of 1,234", then **« First**, **‹ Prev**, up to three page numbers around the page you are on (for example 4 5 6), **Next ›**, **Last »**, "Page [ ] of N" and "Rows per page".

| Key | What it does |
|---|---|
| **Tab** / **Shift + Tab** | Move between the page buttons, the page box and **Rows per page** |
| **Enter** on **« First**, **‹ Prev**, a page number, **Next ›** or **Last »** | Go to that page. The page you are on is read out as "current page". |
| **Tab** to **Page [ ] of N**, then **↑ / ↓** or type the number | Jump to any page (a list when there are up to 200 pages) |
| With more than 200 pages: type the number in the small box, then **Enter** (or **Tab** to **Go**, then **Enter**) | Jump to that page. A number past the end goes to the last page. |
| **Tab** to **Rows per page**, then type `1`, `2` or `5`, or **↑ / ↓** | Show 10, 20 or 50 rows. It goes back to page 1, keeps the filters, and stays in the address, so **F5** keeps it. The default is 10. |

On a phone only **‹ Prev**, "Page x of N" and **Next ›** show, with **Rows per page**.

Each screen below only adds where the footer sits in its Tab order.

---

## System Health (Admin)

| Key | What it does |
|---|---|
| **Tab** / **Shift + Tab** | Next / previous. Order: **Check again** → **Show** (All, Problems, Warnings, OK) → page buttons → **Rows per page** |
| **Tab** to **Show**, then **↑ / ↓** or type the first letter | Choose All, Problems, Warnings or OK. It goes back to page 1 and stays in the address. |

Problems come first, then Warnings, then OK, so a problem is never on page 2 while OK checks fill page 1. The top line always says how many problems and warnings there are, whatever you show.

---

## Menu (sidebar, all roles with a heading)

| Key | What it does |
|---|---|
| **Tab** to the **ADMINISTRATION** heading | Put the cursor on it |
| **Enter** or **Space** | Hide / show the links under it (the arrow shows which). Your choice is remembered on this PC. |

The group opens by itself when you go to one of its pages. On a narrow screen there are no headings and every link is always shown.
