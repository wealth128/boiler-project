# Project Phases: AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-14 · Adapted from the user's phasing_

| # | Phase | Stage | Objective | Output | Done when |
|---|---|---|---|---|---|
| 1 | Define Problem & Scope | Planning | Lock the MVP: what v1.0 does and doesn't do | Answered clarification list, MVP feature list (in / out) | All ⚠️ items in `clarification-list.md` answered |
| 2 | **Design the Screens** _(added)_ ✅ **Done** | Planning | Design every screen and validate it with Admin/ER **before** any code | `prototype/prototype.html`: the final screen reference (login, encoding form, patient list, reports, admin lists, users) | Admin and ER confirm the screens match how they work |
| 3 | Architecture & Tech Stack | Planning | Choose the frontend, backend, database and **local network** setup; map how data flows from encoding to report | Short architecture note + stack decision | Stack chosen and installs on a test PC |
| 4 | Database Schema | Planning | Define the tables and how they connect | Table list + relationships | Every report count can be produced from the tables (checked on paper) |
| 5 | Foundational Features | Infrastructure | Login, roles (Encoder/Admin/Viewer), create/read/update records, soft delete (Trash) and hard delete, audit log, error handling, settings file, **daily backup** | Working skeleton with no reports yet | A test user can log in and only see what their role allows |
| 6 | Develop the Features | Building | Build module by module: encoding → records → master lists → reports → exports (PDF/Excel/print). No transfer screen in v1.0: a transfer is a new visit in the new category, with a remark (e.g. "From ER, admitted to ward"). Claude Code builds each screen to match `prototype/prototype.html`. | Working system | Each module passes its own checks |
| 7 | **Testing & Parallel Run** _(added)_ | Verification | Run the system alongside the Google Sheets for one full month. Admin's manual tally must match the system report. | Test results, list of fixes | One month's totals match exactly |
| 7.5 | **Documentation** _(added)_ | Handover | Write what a developer needs to understand the code and what users need to use the system. Written after Phase 7 fixes, so it describes the final system. | See "Phase 7.5: Documentation" below | A new developer can run and change the system from the developer guide; the System custodian (no IT) can do the daily, weekly and monthly care; a technician can rebuild a dead server from the recovery guide; each role can do their daily work from the user manual (see "Proof" below) |
| 8 | Deploy on Hospital Network | Launch | Install on the server PC and open it to office PCs on the LAN. Set up automatic backup. | Live system + setup guide | Every office PC can open it, and a restore from backup works |
| 9 | **Training & Handover** _(added)_ | Launch | Train ER and Admin using the user manual from Phase 7.5, and hand over the support contact | Training done; user manual printed or shared per role | Users encode and generate reports without help |

## Phase order dependencies
- Phase 1 blocks everything else.
- Phase 2 comes before phase 4: confirming the screens with users catches missing fields before they're built into tables.
- Phase 7 is the main protection against wrong report numbers.
- Phase 7.5 comes after Phase 7 so the docs describe the final, fixed system, and before Phase 8 so the deployment uses the operations guide.

## Phase 7.5: Documentation
Saved in `docs/` at the project root (next to `planning/` and `system/`). Written **after Phase 7 fixes**, so the documents describe the final system. Goal: the hospital can run the system with **no IT staff**, and a future developer can take over the code alone.

### Rules for every document (so they are complete and correct)
- **Written from the real system, not from memory:** every screen name, button, message and menu item is copied from the running system. Every role chapter is checked against `planning/rbac.md` and the `Permission` enum.
- **Plain language**, short steps, one action per step, with a **screenshot** for each screen a user touches.
- **Each document has a version and date** at the top and says which system version it matches.
- **Tested by the people who will use it:** each document is followed step by step by someone who did not write it, and every place they got stuck is fixed (see "Proof" below).
- **Kept up to date:** after handover, any change to the system updates the affected documents in the same step (rule in `CLAUDE.md`).
- **Two forms:** Markdown in `docs/`, plus printed PDFs per role (user manual chapters, custodian card, keyboard shortcuts, recovery guide), placed in a labelled folder beside the server.

### For the developer (to understand and change the code)
| Doc | What it covers |
|---|---|
| `docs/developer-guide.md` | Set up and run the project on a new PC; the folder map; the path of one request (route → middleware → controller → Form Request → policy → service → model → observer → Inertia page); one feature walked through end to end (Encode: search, save, edit); how to release a new version to the server (build, `update.bat`) |
| `docs/feature-notes.md` | One section per feature (Login, RBAC, Encode, Records, Lists, Reports and tabs, Months and saved copies, Trash, Audit log, Backups, Users, Hospital settings, System health, install/update/restore commands): which files do the work and the rules they enforce |
| `docs/database-guide.md` | Each table in plain words; how report counts are produced (each patient once per category per period; diagnosis per visit); saved copies for months, quarters and years; soft delete vs permanent delete; what the backup contains |
| `docs/security-notes.md` | Short summary of `planning/security.md` for a new developer: roles and double checks, sessions and 8 PM sign-out, passwords, file storage, throttles, what must never change |
| `docs/how-to.md` | Common changes: add a field, a list, a screen, a role permission; write a test; run tests (test database only); build for production |
| `docs/learning-path.md` | What to learn to own this code, in order, mapped to where it is used here, with official doc links |

### For the System custodian (Admin-office person who looks after the system; no IT knowledge needed)
| Doc | What it covers |
|---|---|
| `docs/start-here.md` | One page: what this system is, where the server is, which document to open for which problem |
| `docs/custodian-card.md` | **One printed page** beside the server: daily (no red bar), weekly ("Copy to USB" button), monthly (check the automatic test restore is green), "when you see red, do this" for each red/yellow message |
| `docs/operations-guide.md` | Using the double-click files (`health.bat`, `update.bat`, `restore.bat`, `reset-admin-password.bat`); start/stop; backups and the USB copy; the 8 PM sign-out; where the logs are; a "Problem → What to do" table for every System health check and every red/yellow bar |
| `docs/recovery-guide.md` | **"The server PC died"**, step by step with screenshots, for any computer technician (no Laravel knowledge): prepare a new PC, install Laragon, copy the system folder, double-click `install.bat`, then `restore.bat` from the backup PC or USB, check the counts, open from office PCs. Also: moving to a new server, server name/IP changed |

### Troubleshooting (for the custodian first, then a technician)
| Doc | What it covers |
|---|---|
| `docs/troubleshooting.md` | **Symptom → Check → Fix → If still broken**, one entry per problem, in plain steps with screenshots. Each entry says who can do it (custodian, or technician with the recovery guide) and what to write down before calling for help (the System health screen and `health.bat` result, the time, the exact message). |

Starting list of entries (Claude Code adds any new problem found during Phase 7, Phase 8 and the spare-PC tests):
- **No office PC can open the system:** is the server on? Run `health.bat`. Is MySQL/Apache running (auto-start)? Was the server's name/IP changed? Is the firewall rule there?
- **One office PC can't open it, others can:** check that PC's network cable/Wi-Fi, the address it uses, its browser; try another browser.
- **"Can't log in" / account locked:** wrong password 5 times → Admin or System Admin unlocks; forgot password → Admin resets it; Admin forgot → `reset-admin-password.bat` on the server.
- **Everyone was logged out:** 8 PM daily sign-out (normal), a password reset, or the server restarted.
- **Red bar: backup failed / no backup in 26 hours:** is the backup PC on and its shared folder reachable? Disk space? Then click Run backup now.
- **Red bar: automatic tasks are not running:** the scheduled task stopped → re-run `install.bat --repair` steps from the operations guide, or restart the server.
- **Red bar: automatic test restore failed:** what the message says, and when to call the technician.
- **Yellow: USB copy overdue:** plug the USB into the server, click Copy to USB.
- **System health shows a Problem:** one line per check, matching its "What to do" text.
- **"Too many requests, wait a minute":** normal after many quick clicks; wait one minute.
- **Report numbers look wrong:** check the period and tab, open vs closed month ("Not final"), visits in Trash, a patient correction; how to compare with Records.
- **Can't edit or delete a visit:** closed month, not your own visit, or not the same day.
- **PDF/Print problems:** blank page, missing logo/signature (closed months only), printer settings.
- **Server very slow or disk almost full:** System health disk check; what can be cleaned safely (never the database or backups).
- **Power failure / server restarted:** what to check after it comes back.
- **The server PC died:** go to `docs/recovery-guide.md`.

### For the users (to do their daily work)
| Doc | What it covers |
|---|---|
| `docs/user-manual.md` | One chapter per role (Encoder, Admin, System Admin, Viewer/CO): step-by-step daily tasks with screenshots, what each message means, short FAQ. The Admin chapter covers Lists, Records, Reports (tabs, Print/PDF/Excel, close/reopen months, saved copies and signatures), Trash, Audit log, Backups, Hospital settings and System health |
| `docs/keyboard-shortcuts.md` | One page of keyboard-only shortcuts per screen, printable, to post beside each PC (collected during Phase 6) |

### Proof that the documents are complete and correct (exit criteria)
1. **Developer:** someone other than the author sets up the project on a clean PC using only `developer-guide.md`, runs the tests, and makes one small change from `how-to.md`.
2. **Custodian:** an Admin-office person (not IT) does the daily, weekly and monthly tasks and two "when you see red" cases using only the custodian card and operations guide.
3. **Recovery:** on a spare PC, a computer technician (or the developer acting as one, without help) rebuilds the server from `recovery-guide.md` and a real backup. The record counts must match.
4. **Troubleshooting:** the custodian solves at least five entries from `troubleshooting.md` on a test setup (each problem caused on purpose), using only the document.
5. **Users:** one person per role does their daily tasks using only their user-manual chapter.
6. **Cross-check:** every route, permission, button label and message in the documents is checked against the code (Claude Code lists routes, permissions and user-facing messages; the reviewer compares them to the documents).
7. Every place someone got stuck is fixed and re-tested. Only then is Phase 7.5 done.

**Collect as we build:** every keyboard shortcut added in Phase 6 is written into `docs/keyboard-shortcuts.md` in the same step, so nothing has to be rediscovered later.
