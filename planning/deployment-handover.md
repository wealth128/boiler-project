# Deployment & Handover Plan

_Created 2026-10-16 · Used in Phase 8 (Deploy) and Phase 9 (Training & Handover), after the system is finished and the Phase 7 parallel run passes._

This is the checklist for putting the system on the hospital server and handing it over so the hospital can run it **without the developer**. Each part says what to do and what to hand over. Detailed step-by-step instructions go into `docs/operations-guide.md` (Phase 7.5).

---

## 1. What the Hospital Receives (Handover Package)
| Item | Form | Notes |
|---|---|---|
| The running system | Installed on the server PC | Production settings, tested from every office PC |
| Source code | GitHub repository (transfer ownership or add a hospital IT account), plus a ZIP copy on the USB drive | Includes `planning/`, `docs/`, `prototype/` |
| Ready-to-install copy | ZIP with `system/` including `vendor/` and built `public/build/` | The server has no internet, so it can't download packages |
| **Installers on the handover USB** | `vc_redist.x64.exe` (Microsoft Visual C++ Redistributable 2015-2022 x64), `laragon-wamp.exe` (Laragon **Full**), and only if Laragon's PHP is older than 8.4.1 the Thread Safe PHP x64 zip | The server has no internet. Exact file names and versions tested are in `docs/requirements.md` (filled in at the spare-PC test). Composer, Node.js and Git are **not** needed on the server |
| Documents | Printed + PDF: user manual (per role), keyboard shortcuts, operations guide, developer guide, database guide, security plan | From Phase 7.5 |
| Accounts sheet | Sealed envelope, given to the CO or IT head | Admin and System Admin usernames with **temporary** passwords (must be changed at first login), MySQL root password, Windows server login |
| Backup setup | Working daily backup + one tested restore | Proven before handover (section 5) |
| Acceptance record | Signed form | Phase 7 parallel-run results + sign-off (section 7) |

All software used is free/open source (Laravel, React, MySQL Community, Laragon). No licenses to buy.

## 2. Before Deployment (prepare the server)
- [ ] **Server PC:** dedicated, on a UPS, in a locked room, set to never sleep; Windows updated; antivirus on; screen lock 5 min.
- [ ] **Fixed address on the LAN:** a static IP (or fixed hostname, e.g. `census-server`) so office PCs always find it.
- [ ] **Everything in `docs/requirements.md` part 1 installed** (Windows 10/11 64-bit, 4 GB memory, 10 GB free disk, port 80 free, Visual C++ Redistributable x64). `install.bat` checks all of it before it changes anything.
- [ ] **Laragon Full** with **PHP 8.4.1+** (the locked packages need it; `composer.json` requires `^8.4.1`) and extensions: gd, intl, zip, mbstring, pdo_mysql, fileinfo, openssl. **MySQL 8.**
- [ ] **Backup destination:** a shared folder on a second PC (e.g. `\\BACKUP-PC\census-backups`), writable only by the server's account; a USB drive for weekly copies.
- [ ] **Disk encryption** (BitLocker) on the server and backup PC, if available.
- [ ] **HTTPS or HTTP decision** made with hospital IT / CO (open decision in `planning/security.md`).

## 3. Deployment Steps (summary; full steps in the operations guide)
1. Copy the ready-to-install `system/` folder to the server.
2. **Run `php artisan census:install`** (steps 2 to 5 of the old manual list are now this one command). It checks PHP 8.4.1+, the extensions and the folders (and stops on any FIX), asks for the MySQL root password (hidden, never saved), then: **if root has no password** (a new Laragon), it makes you set one first (twice, hidden, at least 12 characters) and says "Write this in the sealed envelope."; then:
   - creates the database `patient_census` and the user `census_app` with a random password and **only** SELECT, INSERT, UPDATE, DELETE, LOCK TABLES, SHOW VIEW, TRIGGER (MySQL listens on `127.0.0.1` only);
   - writes the production `.env` (`APP_ENV=production`, `APP_DEBUG=false`, new `APP_KEY`, `LOG_STACK=daily`, `MYSQLDUMP_PATH` found in Laragon and `MYSQL_PATH` found next to it, so a restore never has to ask, `BACKUP_PATH` asked and tested);
   - runs the migrations with root, seeds **only** the lists and settings (never the dev seeder), and creates the Admin and System Admin with temporary passwords shown once ("Write these down and put them in the sealed envelope");
   - runs `config:cache`, `route:cache`, `view:cache` and writes `storage/logs/install.log` (no passwords).
   On a server that already has the system (`.env` with an `APP_KEY`, users in the database, or the `patient_census` database) it refuses with "This server already has the system installed. Use census:install --repair, or census:restore." Only `--force` plus typing `INSTALL OVER` goes on. `php artisan census:install --repair` re-checks and fixes missing `.env` keys (including `MYSQL_PATH`) without touching data.
3. Still by hand: set `'serve' => false` in `config/filesystems.php`. Check `php artisan route:list` has no `_inertia/devtools` or `storage/{path}` routes.
4. Run `php artisan census:health`. Everything must be OK or Warning (the scheduler shows Problem until step 7 is done).
5. The `.env` and the caches were already made by step 2. After any later change to `.env`, run `php artisan census:install --repair` to rebuild the caches.
6. **Done by `install.bat`** (right-click, Run as administrator; it runs `census:install` first): MySQL and Apache become Windows services (`CensusMySQL`, `CensusApache`) that start with Windows with nobody logged in and restart after a crash, using Laragon's own `my.ini` and `httpd.conf`; `bind-address=127.0.0.1` is checked in `my.ini` (copy kept); the Apache site file `00-census.conf` serves `system/public` on the web port; and `http://localhost/` is checked to show the login page. Windows Firewall: the web port is open to the local network only and 3306 is blocked from outside. **Laragon's Start All is never used on the server after the first install.**
7. **Done by `install.bat`:** the Task Scheduler task "Census - Scheduler" runs `php artisan schedule:run` every minute (this runs the 02:00 backup), as SYSTEM for a local backup folder or as a Windows account whose password never expires for a network share. One real backup is run through the task to prove it. The install prints the address for the office PCs.
8. Folder permissions: `storage/` and `bootstrap/cache/` writable by the server account; `.env` and the backup folder readable only by the server account and IT.
9. Fill in **Settings**: hospital name, logo, office line, Prepared by / Noted by with titles.
10. Smoke test from **each office PC**: log in per role, encode a test visit, run a report, export PDF/Excel, then move the test data to Trash and delete it permanently.

## 4. The Database: What to Know
- **What's in it:** patients (names, birthdates), visits (diagnoses, categories), lists, users, audit log, saved month reports. It is **personal and health information** under the Data Privacy Act of 2012: the hospital is responsible for it. Confirm with the hospital's Data Protection Officer what registration or records they need.
- **Who has access:** only the system (through `census_app`) and IT (MySQL root, kept in the sealed envelope). Nobody edits tables directly; all changes go through the system so they're audited.
- **Nothing is ever removed automatically.** Deleted records go to Trash; only Admin deletes permanently. Backups keep 30 days.
- **Growth:** small (text only). Check free disk space monthly; the Backups page shows backup sizes.

## 5. Backups: What to Do
| When | What | Who |
|---|---|---|
| Daily 02:00 | Automatic backup to the backup PC, kept 30 days: the database dump **and** the picture zip | System |
| Every morning | Check for the red bars (backup failed, or automatic tasks not running) and the **System health** page | Admin |
| Weekly | Plug the USB drive into the **server PC**, open **Backups**, click **Copy to USB**, choose the drive and confirm. The server copies both files, checks the sizes, keeps the newest 8 and marks it done. Store the USB in another room | Admin / custodian |
| Monthly (1st, 03:00) | **Automatic test restore**: the system loads the latest backup into a scratch database and compares the counts. Check on **Backups** that "Test restore" says Passed (a red bar means it failed: see troubleshooting). **Run test restore now** if the yellow reminder shows | System; Admin checks |
| Yearly, or when moving servers | Practice restore on a spare PC with `restore.bat` | IT |
| Before any system update | Nothing extra: `php artisan census:update` takes a backup first and stops if it fails | IT |
| Any time | `php artisan census:health` (exit code 1 means a Problem) | IT |

**Restore (when the server fails), in short:** set up a new server (section 2, then `install.bat`), then run `restore.bat` (it lists the backups in `BACKUP_PATH`, newest first, and runs `php artisan census:restore <backup file>`) with the latest backup in `BACKUP_PATH`. It asks you to type `RESTORE`, takes a safety backup, asks for the MySQL root password (hidden), loads the dump, unzips the matching picture zip, migrates, signs everyone out and prints the record counts and the last encoded visit. Check those, then let users back in. Anything encoded after the last backup must be re-encoded from paper. If a restore or update fails, the site stays in maintenance mode on purpose: the command prints `php artisan up` and the `census:restore` command that undoes it. Full steps in the operations guide.

**Before handover:** do one complete practice **install**, **update** and **restore** on a spare PC with the double-click files in `system\scripts\windows\` (`install.bat`, `update.bat`, `restore.bat`), plus the reboot, crash-restart and firewall tests, and record the result. Use `docs/windows-scripts-manual-test.md`. The commands (Step 6i-1) and the files (Step 6i-2) were tested only with fakes. If the Windows password of the backup account changes, run `install.bat` again and type the new password.

## 6. Accounts & Access at Handover
- [ ] Remove or deactivate every developer/test account; make sure no dev users exist in production.
- [ ] Admin and System Admin get temporary passwords (forced change at first login). Real owners change them in front of no one.
- [ ] MySQL root and Windows server passwords changed by hospital IT after handover; developer no longer knows them.
- [ ] `reset-admin-password.bat` (it runs `census:reset-admin-password`; on the server only, as administrator) explained to IT and the custodian, for a locked-out or forgetful Admin.
- [ ] GitHub repository: transfer ownership or give hospital IT admin access; remove personal access when done.
- [ ] Agree in writing who owns the code and who maintains it after handover.

## 7. Acceptance & Sign-off
- [ ] Phase 7 parallel run: one full month where the system's report matches Admin's manual tally exactly.
- [ ] Every role trained (Phase 9) and can do their daily work from the user manual.
- [ ] Backup and a test restore proven.
- [ ] Acceptance form signed by the Admin office head and noted by the CO, listing what was handed over (section 1).

## 8. After Handover: Maintenance Calendar
| How often | Task | Who |
|---|---|---|
| Daily | Automatic backup; Admin checks for warnings | System / Admin |
| Weekly | **Copy to USB** (a button: USB plugged into the server, click, confirm) | Admin / custodian |
| Monthly | Test restore is **automatic** (1st at 03:00): check it is green on Backups. Review user list (deactivate leavers); skim the audit log for unusual deletes; check disk space | System / Admin / IT |
| Quarterly | Windows updates on the server (after a backup); check the UPS | IT |
| Yearly | Review lists (diagnoses, ranks, brackets) with the office; review this plan and the security plan | Admin / IT |
| On any staff change | Create, deactivate or move accounts the same day | Admin / System Admin |

**Updating the system later** (offline): build the new version on a dev PC, copy the new files over the old ones (keep `.env` and `storage/`), then run `php artisan census:update`. It backs up first (stops if that fails), puts the site in maintenance mode, asks for the MySQL root password, runs `migrate --force`, rebuilds the caches, brings the site back and writes the audit entry `system.updated`. Then smoke test. Steps in the operations guide.

## 9. Support After Handover
- Who to call first (hospital IT), and the developer's contact and support period, if any, written on the acceptance form.
- Common problems and fixes are in the operations guide (locked account, failed backup, server won't start, printer/PDF issues).
- A first troubleshooting guide is already in `docs/troubleshooting.md` (a 500 error "Unknown column" after an update, the red scheduler bar, a missing or failed backup, an update or restore that stopped half-way). Fold it into the operations guide in Phase 7.5.
