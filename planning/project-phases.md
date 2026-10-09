# Project Phases: AFP Hospital Patient Encoding & Reporting System

_Last updated: 2026-10-03 · Adapted from the user's phasing_

| # | Phase | Stage | Objective | Output | Done when |
|---|---|---|---|---|---|
| 1 | Define Problem & Scope | Planning | Lock the MVP: what v1.0 does and doesn't do | Answered clarification list, MVP feature list (in / out) | All ⚠️ items in `clarification-list.md` answered |
| 2 | **Design the Screens** _(added)_ | Planning | Design every screen in Claude Design and validate it with Admin/ER **before** any code | Screen designs: login, encoding form, patient list, transfer, reports, admin lists, users | Admin and ER confirm the screens match how they work |
| 3 | Architecture & Tech Stack | Planning | Choose the frontend, backend, database and **local network** setup; map how data flows from encoding to report | Short architecture note + stack decision | Stack chosen and installs on a test PC |
| 4 | Database Schema | Planning | Define the tables and how they connect | Table list + relationships | Every report count can be produced from the tables (checked on paper) |
| 5 | Foundational Features | Infrastructure | Login, roles (Encoder/Admin/Viewer), create/read/update records, soft delete (Trash) and hard delete, audit log, error handling, settings file, **daily backup** | Working skeleton with no reports yet | A test user can log in and only see what their role allows |
| 6 | Develop the Features | Building | Build module by module: encoding → transfers → master lists → reports → exports (PDF/Excel/print) | Working system | Each module passes its own checks |
| 7 | **Testing & Parallel Run** _(added)_ | Verification | Run the system alongside the Google Sheets for one full month. Admin's manual tally must match the system report. | Test results, list of fixes | One month's totals match exactly |
| 8 | Deploy on Hospital Network | Launch | Install on the server PC and open it to office PCs on the LAN. Set up automatic backup. | Live system + setup guide | Every office PC can open it, and a restore from backup works |
| 9 | **Training & Handover** _(added)_ | Launch | Train ER and Admin, and hand over the user guide and support contact | Quick user guide (1–2 pages per role) | Users encode and generate reports without help |

## Phase order dependencies
- Phase 1 blocks everything else.
- Phase 2 comes before phase 4: confirming the screens with users catches missing fields before they're built into tables.
- Phase 7 is the main protection against wrong report numbers.
