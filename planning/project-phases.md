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
| 7.5 | **Documentation** _(added)_ | Handover | Write what a developer needs to understand the code and what users need to use the system. Written after Phase 7 fixes, so it describes the final system. | See "Phase 7.5: Documentation" below | Someone new can run the system from the developer guide, and each role can do their daily work from the user manual |
| 8 | Deploy on Hospital Network | Launch | Install on the server PC and open it to office PCs on the LAN. Set up automatic backup. | Live system + setup guide | Every office PC can open it, and a restore from backup works |
| 9 | **Training & Handover** _(added)_ | Launch | Train ER and Admin using the user manual from Phase 7.5, and hand over the support contact | Training done; user manual printed or shared per role | Users encode and generate reports without help |

## Phase order dependencies
- Phase 1 blocks everything else.
- Phase 2 comes before phase 4: confirming the screens with users catches missing fields before they're built into tables.
- Phase 7 is the main protection against wrong report numbers.
- Phase 7.5 comes after Phase 7 so the docs describe the final, fixed system, and before Phase 8 so the deployment uses the operations guide.

## Phase 7.5: Documentation
Saved in `docs/` at the project root (next to `planning/` and `system/`).

### For the developer (to understand the code)
| Doc | What it covers |
|---|---|
| `docs/developer-guide.md` | How to set up and run the project on a new PC; the folder map (global vs feature); the path of one request (route → middleware → controller → Form Request → policy → service → model → observer → Inertia page); one feature walked through end to end (Encode: search, save, edit) |
| `docs/feature-notes.md` | One section per feature (Login, RBAC, Encode, Records, Lists, Reports, Trash, Months, Audit log, Backup, Users, Settings): which files do the work and the rules they enforce |
| `docs/database-guide.md` | Each table in plain words, how report counts are produced (each patient once per category per period), soft delete vs permanent delete |
| `docs/how-to.md` | Common changes: add a field, add a list, add a screen, add a role permission, write a test, run tests (test database only), build for production |
| `docs/learning-path.md` | What to learn to own this code, in order, mapped to where it's used here: Laravel (routing, Eloquent, middleware, Form Requests, policies/gates, observers, Fortify, scheduler, commands), Inertia, React + TypeScript, Tailwind, Pest, MySQL. Official doc links for each. |

### For IT / the person running the server
| Doc | What it covers |
|---|---|
| `docs/operations-guide.md` | Start/stop the system, daily backup and how to restore one, `census:reset-admin-password`, the 8:00 PM sign-out, where the logs are, what to check when something breaks |

### For the users (to know the system)
| Doc | What it covers |
|---|---|
| `docs/user-manual.md` | One chapter per role (Encoder, Admin, System Admin, Viewer/CO): step-by-step daily tasks, what each message means, short FAQ |
| `docs/keyboard-shortcuts.md` | One page of keyboard-only shortcuts per screen (e.g. Tab moves between fields, Enter saves, Ctrl+Enter saves from Remarks). Printable, to post beside each PC. |

**Collect as we build:** every keyboard shortcut added in Phase 6 is written into `docs/keyboard-shortcuts.md` in the same step, so nothing has to be rediscovered later.
