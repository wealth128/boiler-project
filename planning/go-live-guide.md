# Go-Live Guide: Spare-PC Test → Deploy → Training & Handover

_Created 2026-10-23. Use after Phase 6 is finished (steps 7b–7e). The detailed checklists it points to already exist in `docs/` and `planning/`; this file puts them in order._

**Order:** A. Spare-PC test → B. Decide HTTPS or HTTP → C. Build the release package → D. Install on the hospital server → E. Phase 7 parallel run → F. Fresh start (no data) → G. Phase 7.5 documents with proof → H. Training → I. Handover and sign-off.

---

## A. Spare-PC test (proves install, update, restore, reboot, USB)

**Where:** any spare Windows 10/11 64-bit PC, or a virtual machine on your PC (VirtualBox + Windows evaluation). **Never on your dev PC** (restore deletes the database).
**Full checklist:** `docs/windows-scripts-manual-test.md`. Write the result of every line (pass / fail + screen text).

1. **Prepare the PC:** install only what `docs/requirements.md` lists (Laragon, PHP 8.4.1+, Visual C++ Redistributable). Fill in the "Version tested" column of `docs/requirements.md` as you go.
2. **Build the ready-to-install folder** on your dev PC (see C below) and copy it to the spare PC.
3. **Install:** right-click `install.bat` → Run as administrator. Check: requirements check all OK; MySQL root password set; Admin and System Admin temporary passwords shown; services `CensusMySQL` and `CensusApache` created; scheduler task created; first backup ran; `http://localhost/` shows the login page.
4. **Run install.bat again:** it must say the system is already installed and only repair.
5. **Log in** as Admin and System Admin (choose new passwords). Encode a few test visits. Close a past month.
6. **Backup as `census_app`:** Backups → Run backup now → Success with `.sql` and `.zip`.
7. **Test restore:** Backups → Run test restore now → Passed.
8. **Copy to USB:** plug a USB into that PC → Copy to USB → both files on the USB in `census-backups`.
9. **Update:** run `update.bat` → backup first, migrate, back up and running.
10. **Restore:** run `restore.bat`, pick a backup → counts printed, the site works, the visits are there.
11. **Reboot with no one logged in:** after a few minutes, open the site from another PC or phone on the same network → login page.
12. **Crash restart:** in Task Manager end `mysqld.exe` → it comes back within a minute.
13. **Office PC access and firewall:** from another PC, `http://<spare-PC name or IP>/` works; port 3306 is NOT reachable.
14. **`reset-admin-password.bat`** and **`health.bat`** work.
15. **`uninstall-tasks.bat`** removes services, tasks and firewall rules, and no data.
16. **Fix and repeat:** send every failure (exact screen text) to Claude Code, fix, and re-run the failed lines. Done when every line passes.

## B. Decide HTTPS or HTTP (before deploying)

| | HTTP on a staff-only network | HTTPS (encrypted) |
|---|---|---|
| What it means | Plain web traffic. Anyone on the same network could read passwords and patient data in transit | Traffic is encrypted |
| Needs | The server and office PCs on a wired network only staff use | A certificate on the server, and the certificate trusted once on every office PC |
| Upkeep | None | The certificate expires; replacing it needs someone |
| Fits "no IT" | Easiest | Harder, unless the certificate is long-lived (e.g. 10 years) and the installer sets it up |

**My recommendation:** HTTPS if the hospital network is shared with other offices, visitors or Wi-Fi; HTTP only if it is a separate, wired, staff-only network. Ask the CO / hospital IT. **If you choose HTTPS,** ask me for a small extra step (install.bat creates a 10-year certificate, Apache serves HTTPS, a one-click file trusts it on each office PC, `SESSION_SECURE_COOKIE=true`). Record the decision in `planning/security.md` and `planning/project-history.md`.

## C. Build the release package (on your dev PC)

1. All steps merged and pushed; full tests pass. Tag the version: `git tag v1.0.0` and `git push origin v1.0.0`.
2. Make a **clean copy** from git (not your working folder): `git clone` the repo into a new folder, or `git archive v1.0.0`.
3. In `system/`: `composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build`.
4. **Must NOT be in the package:** `.env`, `_private/`, `storage/app/backups`, `storage/app/private/*` (logo, signatures), `storage/logs/*`, `node_modules/`, any `.sql` or `.zip` backup, `prototype/` test data. (A clean clone has none of these.)
5. ZIP it as `census-system-v1.0.0.zip`. Put it, with the installers from `docs/requirements.md` and the printed documents, on the handover USB.

## D. Install on the hospital server (Phase 8)

Follow `planning/deployment-handover.md` sections 2–3. In short:
1. **Server ready:** UPS, locked room, never sleeps, Windows updated, antivirus, fixed IP or name (e.g. `census-server`), BitLocker if available, the backup PC with a shared folder `census-backups`.
2. **Install the requirements**, copy the package, run `install.bat` as administrator. Give the backup folder `//BACKUP-PC/census-backups`. Write every password the installer shows on the sealed-envelope sheet (`_private/credentials.md` section B).
3. **Sign in as Admin:**
   - Hospital settings: name, office line, Prepared by / Noted by, logo, signatures.
   - Lists: review branches, ranks, diagnoses and age brackets with the Admin office; add, rename or remove before encoding starts.
   - Create the real accounts (ER encoders, CO viewer); each gets a temporary password.
4. **Each office PC:** a desktop shortcut to `http://census-server/`. Print the keyboard shortcuts sheet beside each PC. Save the downtime template (Records → Import → Download template) on each PC and print 2 blank copies.
5. **Smoke test per role** from a real office PC: encode, Records, Reports (Print, PDF, Excel), Viewer sees reports only, System Admin sees accounts only.
6. **Backups:** run a backup, a test restore and a Copy to USB; confirm System health is all OK.
7. **Check no dev accounts or test data exist** (see F).

## E. Phase 7: Parallel run (one full month)

1. From the 1st of a month, ER encodes every visit in the system **and** the office keeps its usual Google Sheets tally.
2. On the 1st of the next month: compare the system's Monthly report with the manual tally: patients per category, visits, sex, age ranges, diagnoses, branch × rank. **They must match exactly.**
3. Every difference: find the visit, decide whether the system or the tally is wrong, fix the cause (training, list item, or a code fix by Claude Code). Repeat until a whole month matches.
4. Close the month. Record the result for the acceptance form.

## F. Hand it over fresh (no test data)

**Rule:** the hospital starts with **only** its own settings, lists and accounts: zero test patients, zero test visits, no developer accounts.

- **Recommended:** never put test data on the server. Test on the spare PC (A). Install the server from the clean package (C). Then the server is fresh by design: the installer never runs the dev seeder, and creates only lists, settings and the two first accounts.
- **If the parallel run (E) was done on the server:** that data is **real** (real patients) and should stay. It is not "test data".
- **If test data did get onto the server** (practice encoding, training): do a clean reinstall before go-live: run a backup, `uninstall-tasks.bat`, in MySQL drop `patient_census` (with root), delete `system/.env` and `storage/app/private`, then run `install.bat` again. Keep the backup until you are sure. (There is deliberately no "delete all data" button in the system.)
- **For training,** use the spare PC / VM copy, not the real server.
- **Final checks before handover:**
  - Records → Patients and Visits show 0 (or only the real parallel-run data).
  - User accounts: no `encoder` / `viewer` dev accounts, no developer accounts, only real named staff.
  - Audit log: only install, settings and real-user entries.
  - `.env`: `APP_ENV=production`, `APP_DEBUG=false`, a new `APP_KEY` made on the server (not copied from your PC).
  - No `_private/` folder, no dev backups, no `prototype/` data on the server.
  - System health: all OK.

## G. Phase 7.5: Documentation (complete and proven)

Everything you asked for. Write after Phase 7 fixes so it describes the final system (`planning/project-phases.md`, Phase 7.5, has the full rules).

**For the users**
| Document | Status |
|---|---|
| `docs/user-manual.md`: one chapter per role (Encoder, Admin, System Admin, Viewer/CO), screenshots, every message explained, FAQ; includes the downtime import | Draft exists |
| `docs/keyboard-shortcuts.md`: one printable page per screen | Collected during build |

**For the System custodian (no IT knowledge)**
| Document | Status |
|---|---|
| `docs/start-here.md`: one page, which document for which problem | To write |
| `docs/custodian-card.md`: one printed page beside the server: daily / weekly / monthly / "when you see red" | To write |
| `docs/operations-guide.md`: the double-click files, backups, USB, test restore, 8 PM sign-out, downtime (template + import), logs | Draft exists |
| `docs/troubleshooting.md`: Symptom → Check → Fix → If still broken | Draft exists |
| `docs/recovery-guide.md`: "the server died", with screenshots, for any technician | To write |
| `docs/requirements.md`: what to install first; installers on the USB | Done (versions filled at A) |

**For the developer**
| Document | Status |
|---|---|
| `docs/developer-guide.md`: set up, folder map, request path, one feature end to end, releasing a new version | To write |
| `docs/feature-notes.md`: one section per feature | To write |
| `docs/database-guide.md`: tables, how counts are made, saved copies, backups | To write |
| `docs/security-notes.md` (+ `docs/security-review-2026-10.md`) | Report done; notes to write |
| `docs/how-to.md`: common changes, tests, build | To write |
| `docs/learning-path.md`: what to learn, mapped to this code | To write |

**Handover and your own records**
| Document | Status |
|---|---|
| `planning/deployment-handover.md`: handover package, server steps, accounts, sign-off, maintenance calendar | Done (update after A–E) |
| `docs/windows-scripts-manual-test.md`: spare-PC test list, with your results | Results at A |
| `planning/import-format/`: downtime template example | Done |
| `planning/my-project-notes.md`: how you built it, style, what to remember | Done |
| `_private/credentials.md`: every username and password (never committed; printed copy sealed) | Fill at D |
| Acceptance form (parallel-run result, what was handed over, signatures) | To write (template in H/I) |

**Proof (Phase 7.5 is done only when all pass):** a new developer sets up from the developer guide; the custodian does the daily/weekly/monthly tasks and 5 troubleshooting cases from the documents alone; a technician rebuilds a dead server from the recovery guide on the spare PC with matching counts; one person per role does their daily work from the user manual; every label and message in the documents matches the system.

**How to get them written:** ask me for a "Phase 7.5 documentation" prompt once Phase 7 is finished. Claude Code writes them from the real system; you and the users test them.

## H. Phase 9: Training

| Who | What | Time |
|---|---|---|
| ER encoders | Encode (new and returning patient, Other (specify), duplicates), today's list, editing own visit the same day, keyboard shortcuts, what each message means, the downtime sheet | 1–2 hours + practice |
| Admin | Records, Lists, Reports (tabs, Print/PDF/Excel, close/reopen months, final vs not final), Trash, Audit log, Hospital settings, Import after downtime, user accounts | Half a day |
| System custodian (Admin office) | The custodian card: daily check, Copy to USB, reading System health, red/yellow bars, `health.bat`, `reset-admin-password.bat`, when to call the technician | 2 hours + one practice of each |
| CO / Viewer | Reading the reports, Print/PDF | 30 minutes |
| System Admin | Accounts: add, deactivate, unlock, reset password | 30 minutes |

- Train on the **spare PC / VM copy**, never the real server.
- Each person does their tasks **from the manual**, without help, once. Note every place they got stuck and fix the manual.
- Keep an attendance sheet (name, role, date, signed).

## I. Handover and sign-off

1. **Handover package** (`planning/deployment-handover.md` section 1): the running system; the release ZIP and installers on USB; the source code (GitHub ownership transferred or hospital account added, plus a ZIP copy); all documents printed and as PDF; the sealed envelope (Admin and System Admin temporary passwords, MySQL root, server Windows login, backup-PC account).
2. **Accounts:** remove every developer/test account; real owners change their passwords at first login; hospital staff change the server Windows and MySQL root passwords after handover; you stop knowing them.
3. **GitHub:** transfer the repository or give hospital IT admin access; remove your personal access when agreed.
4. **Acceptance form:** parallel-run result (month, matched), training done (attendance), backup + test restore proven, list of what was handed over, support contact and period, signed by the Admin office head and noted by the CO.
5. **After handover:** the maintenance calendar in `planning/deployment-handover.md` section 8, and the custodian card beside the server.
