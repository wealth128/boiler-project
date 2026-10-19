# My Project Notes: How I Built the Patient Census System

_My own "remember everything" file. Started 2026-10-19. For the full decision details see `planning/project-history.md`. Passwords are **not** here: they are in `_private/credentials.md`, which is never committed._

---

## 1. Why this project exists
- **The problem:** the Admission / Administrative Office of an AFP (Armed Forces of the Philippines) hospital counted patients by hand from Google Sheets every month to report to the **Commanding Officer (CO)**. It was slow and easy to get wrong.
- **The goal:** one system on the hospital LAN. Offices **encode** each patient visit (OPD / ER / Admission), and the system **makes the reports** (monthly, quarterly, annual, custom range) on screen, as PDF, as Excel and printed.
- **The hard constraint:** the hospital has **no IT staff and no internet** on the server. So the system has to install itself, check itself, back itself up, and be documented well enough that an Admin-office person can look after it.
- **v1.0 scope:** only ER encodes (OPD and Admission accounts come later). No discharge, transfer tracking, bed census or dashboards. No import of old Google Sheets.

## 2. How it started (timeline)
| When (2026) | What happened |
|---|---|
| Early Oct | Planning in Claude chat: problem, scope, phases, roles, report rules. Wrote everything into `planning/` |
| Oct 3–8 | Scope answers, database schema, RBAC, security plan, the report rules ("patients once per category per period") |
| Oct 8–9 | Laravel project set up (`system/`), GitHub repo, MySQL databases, login (Step 3), RBAC (Step 4) |
| Oct 13 | Dropped Claude Design; `prototype/prototype.html` became the final screen reference. Foundation (Steps 5, 5b, 5c) |
| Oct 13–16 | Encode (6a), Records (6b), Lists (6c), Reports (6d-1, 6d-1-fix, 6d-2, 6d-3), Trash (6e), Audit log (6f), Backups (6g) |
| Oct 16 | Token-saving rules; `project-history.md` handover file; deployment and handover plan |
| Oct 18 | Hospital settings with logo and signatures (6h), Reports tabs (6d-4), whole-system code review → fixes (6x), self-care commands (6i-1) |
| Oct 19 | Second review → fixes (6i-1-fix), Windows double-click files and services (6i-2), PHP 8.4.1 minimum, root password at install, documentation plan with "proof" tests |

## 3. My working style (how I build)
- **I decide; the AIs help.** Claude chat is my reviewer/planner. Claude Code in VS Code builds. I paste prompts, test in the browser, and say when to commit.
- **One step at a time.** Each step has a prompt file in `planning/Prompts/` (e.g. `prompt-6h.md`), its own git branch (`step-6h-settings`), tests, and a summary before any commit.
- **The loop for every step:**
  1. Claude chat writes the prompt → saved in `planning/Prompts/`.
  2. I paste it into Claude Code. It builds on a new branch and runs the **full** `php artisan test` (test database only), `npm run types:check` and `npm run build`.
  3. Claude Code stops and summarizes. **It never commits until I say okay.**
  4. I bring the summary to Claude chat. It reviews (reads the real code when I say **"check the code"**) and gives me the exact approve/fix message.
  5. I do the browser checks, paste the approve message, and Claude Code commits, merges into `main` and updates `project-history.md`.
  6. I run `git push origin main`.
- **How I want help:** don't decide for me; recommend, and say what's **new** vs **already agreed**; plain language with real examples; short and practical; give the exact message to paste; English.
- **Token saving:** a new chat per major step, starting from `planning/project-history.md` section 0.
- **Every so often: a whole-system check.** Two reviews (Oct 18 and 19) found real bugs the step reviews missed. Do one again before the security review and before Phase 8.

## 4. The stack (and why)
- **Laravel 13** (PHP **8.4.1+**) with the official **React starter kit**: Inertia.js, TypeScript, Tailwind, Fortify (login), Wayfinder, **Pest** (tests). One app (monolith), no separate API.
- **MySQL 8.** `patient_census` (real data) and `patient_census_test` (**tests only**, because tests wipe every table).
- **Allowed packages only:** maatwebsite/excel (Excel), barryvdh/laravel-dompdf (PDF). **No new package without asking.** No Sanctum, no Spatie, no crop library (the built-in canvas cropper is used).
- **Server:** one Windows PC with **Laragon** on the hospital LAN; MySQL and Apache run as **Windows services** (start with Windows, restart after a crash).

## 5. Folder map
| Folder / file | What's in it |
|---|---|
| `CLAUDE.md` | Rules Claude Code must follow (read first, tests only on the test DB, docs must match the system…) |
| `planning/project-history.md` | **The handover file.** Section 0 = where we are. Decisions log = every decision and why |
| `planning/` | Plans: phases, schema, RBAC, security, architecture, deployment-handover, setup guides |
| `planning/Prompts/` | Every prompt sent to Claude Code, in order |
| `prototype/prototype.html` | The final screen design reference |
| `system/` | The Laravel app |
| `system/scripts/windows/` | `install.bat`, `update.bat`, `restore.bat`, `health.bat`, `reset-admin-password.bat`, `uninstall-tasks.bat` |
| `docs/` | Documentation: requirements, operations guide, troubleshooting, keyboard shortcuts, manual test list (more in Phase 7.5) |
| `_private/` | Passwords. **Git ignores it. Never share it.** |

## 6. The system in one page
- **Roles:** Encoder (encodes visits), **Admin** (one only; runs everything: records, lists, reports, months, trash, audit log, backups, settings, health), **System Admin** (one only; user accounts only, no patient data), **Viewer** (the CO; reports only).
- **Safety rules:** every page is checked twice (role + permission); deleting goes to **Trash** with a reason from a list; permanent delete needs typing the ID; **closed months are locked**; everything is in the **audit log**; 5 wrong passwords lock an account; temporary passwords must be changed at first login; everyone is signed out at **8:00 PM**; "remember me" is always off.
- **Report rules:** **Patients** = each patient once per category per period (latest visit for sex, age, branch/rank); **Visits** = every visit; Diagnosis table counted **per visit**, every diagnosis named, typed "Other" merged with a matching listed name; Branch × Rank in seniority order. Tabs **OPD | ER | Admission | Summary**.
- **Final reports:** closing a month saves a copy (numbers never change) and freezes the signatures. A quarter or year gets its own saved copy when all its months are closed. Open periods say "Not final".
- **Backups:** daily at 02:00 (database + pictures zip), kept 30 days, to a second PC or disk. Red bar if it fails. (6i-3 adds the automatic monthly test restore and the Copy to USB button.)
- **Self-care:** System health page, "automatic tasks are not running" bar, double-click files for install / update / restore / health / reset Admin password.

## 7. Commands I use (dev PC, inside `system/`)
| What | Command |
|---|---|
| Run the app | `composer run dev` (or `php artisan serve` + `npm run dev`) |
| All tests (test DB only) | `php artisan test` |
| Type check / build screens | `npm run types:check` / `npm run build` |
| New migrations | `php artisan migrate` |
| Reset a dev Admin password | `php artisan census:reset-admin-password` |
| Health check | `php artisan census:health` |
| Backup now | `php artisan backup:run` |
| Push | `git push origin main` (from the project root) |

**Never on the dev PC:** `census:install`, `census:restore` (restore deletes and recreates `patient_census`). Test those on a spare PC.

## 8. Where things are (quick answers)
- **Backups on the dev PC:** `system/storage/app/backups` (because `BACKUP_PATH` is empty). Leave it empty on the dev PC. On the server: `//BACKUP-PC/census-backups` or `D:/census-backups`, never only the main disk.
- **Logo, signatures, frozen signatures:** `system/storage/app/private` (not public; backed up in the zip).
- **Logs:** `system/storage/logs` (`laravel-*.log`, `install.log`, `scripts.log`).
- **Settings for the server:** `system/.env` (made by the installer; never committed).

## 9. What's left
1. **6i-3:** automatic monthly test restore + Copy to USB button.
2. **Security review** (`planning/security.md` section 3) and another whole-system check.
3. **Spare-PC test** of install, update, restore, backup as `census_app`, reboot, crash restart, firewall (`docs/windows-scripts-manual-test.md`). **Blocks Phase 8.**
4. **Phase 7:** one month in parallel with the manual tally; numbers must match exactly.
5. **Phase 7.5:** full documentation with proof tests (developer, custodian, troubleshooting, recovery, users).
6. **Phase 8:** deploy on the hospital server. Decide **HTTPS or HTTP** on the LAN first.
7. **Phase 9:** training, the System custodian, handover and sign-off.

## 10. Lessons I learned
- **Check the real code, not just the summary.** The reviews found a hidden "remember me", a restore that deleted before checking, an installer that could run over a live system, and a password reset that didn't sign people out.
- **Tests only on `patient_census_test`.** A test run once wiped the dev database.
- **VS Code overwrites files changed from outside.** Close the tab first; choose **Don't Save** when the disk copy is the right one. Compare first if unsure (Ctrl+Shift+P → "Compare Active File with Saved").
- **Always run the full test suite** before a commit and again after any last change.
- **Fakes aren't proof.** Install, update and restore must be tried on a real spare PC.
- **Ask before deleting a field.** (Removing birthdate without asking was a mistake; it stays, with an editable age.)
- **Write the decision down the same day** (project-history decisions log), or it gets re-argued later.
