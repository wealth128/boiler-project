# Keyboard Shortcuts

_Last updated: 2026-10-15 · One section per screen. Print and post beside each PC._

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

## Patient Records (Admin)

### Move around
| Key | What it does |
|---|---|
| **Tab** | Go to the next tab, filter, link or button |
| **Shift + Tab** | Go back |
| **Enter** on **Visits** or **Patients** | Switch tabs (each tab keeps its own filters) |

**Order (Tab), Visits tab:** Visits → Patients → From → To → Category → Branch → Search → each row's **Edit** and **Delete** → page numbers
**Order (Tab), Patients tab:** Visits → Patients → Search → each row's **Delete** → page numbers

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

### Pages
| Key | What it does |
|---|---|
| **Tab** to **Previous**, a page number or **Next**, then **Enter** | Go to that page (50 rows per page) |

---

## Reports

Nothing is counted until you press **Generate**. The choices are then kept in the address bar, so Refresh and Back keep them.

| Key | What it does |
|---|---|
| **Tab** / **Shift + Tab** | Next / previous box. Order: Period type → Month (or Quarter and Year, or Year, or From and To) → Category → Generate |
| Type the first letters, or **↑ / ↓** | Choose in Period type, Month, Quarter, Year or Category |
| **Alt + ↓** | Open a list |
| **Enter** | Generate, from any box |
| Type in From / To | Dates go day, month, year in the order your browser shows. **↑ / ↓** change the part the cursor is in. |

### Export PDF, Export Excel and Print (Admin and Viewer)
They use the report on the screen, so press **Generate** first if you changed a choice.

| Key | What it does |
|---|---|
| **Tab** | After Generate: Export PDF → Export Excel → Print |
| **Enter** / **Space** | Press the focused button. The PDF or Excel file is saved by the browser. |
| **Ctrl + P** | Print the report, the same as the Print button (the menu, controls and buttons are left out, and the whole Diagnosis list is shown) |

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
