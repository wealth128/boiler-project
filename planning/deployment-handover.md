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
| Documents | Printed + PDF: user manual (per role), keyboard shortcuts, operations guide, developer guide, database guide, security plan | From Phase 7.5 |
| Accounts sheet | Sealed envelope, given to the CO or IT head | Admin and System Admin usernames with **temporary** passwords (must be changed at first login), MySQL root password, Windows server login |
| Backup setup | Working daily backup + one tested restore | Proven before handover (section 5) |
| Acceptance record | Signed form | Phase 7 parallel-run results + sign-off (section 7) |

All software used is free/open source (Laravel, React, MySQL Community, Laragon). No licenses to buy.

## 2. Before Deployment (prepare the server)
- [ ] **Server PC:** dedicated, on a UPS, in a locked room, set to never sleep; Windows updated; antivirus on; screen lock 5 min.
- [ ] **Fixed address on the LAN:** a static IP (or fixed hostname, e.g. `census-server`) so office PCs always find it.
- [ ] **Laragon** with **PHP 8.3+** and extensions: gd, intl, zip, mbstring, pdo_mysql, fileinfo, openssl. **MySQL 8.**
- [ ] **Backup destination:** a shared folder on a second PC (e.g. `\\BACKUP-PC\census-backups`), writable only by the server's account; a USB drive for weekly copies.
- [ ] **Disk encryption** (BitLocker) on the server and backup PC, if available.
- [ ] **HTTPS or HTTP decision** made with hospital IT / CO (open decision in `planning/security.md`).

## 3. Deployment Steps (summary; full steps in the operations guide)
1. Copy the ready-to-install `system/` folder to the server.
2. **Run `php artisan census:install`** (steps 2 to 5 of the old manual list are now this one command). It checks PHP 8.3+, the extensions and the folders (and stops on any FIX), asks for the MySQL root password (hidden, never saved), then:
   - creates the database `patient_census` and the user `census_app` with a random password and **only** SELECT, INSERT, UPDATE, DELETE, LOCK TABLES, SHOW VIEW, TRIGGER (MySQL listens on `127.0.0.1` only);
   - writes the production `.env` (`APP_ENV=production`, `APP_DEBUG=false`, new `APP_KEY`, `LOG_STACK=daily`, `MYSQLDUMP_PATH` found in Laragon, `BACKUP_PATH` asked and tested);
   - runs the migrations with root, seeds **only** the lists and settings (never the dev seeder), and creates the Admin and System Admin with temporary passwords shown once ("Write these down and put them in the sealed envelope");
   - runs `config:cache`, `route:cache`, `view:cache` and writes `storage/logs/install.log` (no passwords).
   On an installed system it refuses; `php artisan census:install --repair` re-checks and fixes missing `.env` keys without touching data.
3. Still by hand: set `'serve' => false` in `config/filesystems.php`. Check `php artisan route:list` has no `_inertia/devtools` or `storage/{path}` routes.
4. Run `php artisan census:health`. Everything must be OK or Warning (the scheduler shows Problem until step 7 is done).
5. The `.env` and the caches were already made by step 2. After any later change to `.env`, run `php artisan census:install --repair` to rebuild the caches.
6. Web server (Laragon/Apache) points to `system/public`. Windows Firewall: allow only the web port from the LAN; block 3306.
7. **Windows Task Scheduler:** run `php artisan schedule:run` every minute (this runs the 02:00 backup).
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
| Weekly | Copy the latest backup to the USB drive, store it in another room, click "Mark USB copy done" | Admin |
| Monthly | Test restore on a spare PC with `php artisan census:restore <file>`, compare record counts, click "Mark test restore done" | IT |
| Before any system update | Nothing extra: `php artisan census:update` takes a backup first and stops if it fails | IT |
| Any time | `php artisan census:health` (exit code 1 means a Problem) | IT |

**Restore (when the server fails), in short:** set up a new server (section 2, then `census:install`, then steps 6 to 8 of section 3), then run `php artisan census:restore <backup file>` with the latest backup in `BACKUP_PATH`. It asks you to type `RESTORE`, takes a safety backup, asks for the MySQL root password (hidden), loads the dump, unzips the matching picture zip, migrates, signs everyone out and prints the record counts and the last encoded visit. Check those, then let users back in. Anything encoded after the last backup must be re-encoded from paper. If a restore or update fails, the site stays in maintenance mode on purpose: the command prints `php artisan up` and the `census:restore` command that undoes it. Full steps in the operations guide.

**Before handover:** do one complete practice **install** (`census:install`), **update** (`census:update`) and **restore** (`census:restore`) on a spare PC and record the result. These three commands were built in Step 6i-1 and tested only with fakes.

## 6. Accounts & Access at Handover
- [ ] Remove or deactivate every developer/test account; make sure no dev users exist in production.
- [ ] Admin and System Admin get temporary passwords (forced change at first login). Real owners change them in front of no one.
- [ ] MySQL root and Windows server passwords changed by hospital IT after handover; developer no longer knows them.
- [ ] `php artisan census:reset-admin-password` explained to IT (for a locked-out Admin).
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
| Weekly | USB copy | Admin |
| Monthly | Test restore; review user list (deactivate leavers); skim the audit log for unusual deletes; check disk space | IT / Admin |
| Quarterly | Windows updates on the server (after a backup); check the UPS | IT |
| Yearly | Review lists (diagnoses, ranks, brackets) with the office; review this plan and the security plan | Admin / IT |
| On any staff change | Create, deactivate or move accounts the same day | Admin / System Admin |

**Updating the system later** (offline): build the new version on a dev PC, copy the new files over the old ones (keep `.env` and `storage/`), then run `php artisan census:update`. It backs up first (stops if that fails), puts the site in maintenance mode, asks for the MySQL root password, runs `migrate --force`, rebuilds the caches, brings the site back and writes the audit entry `system.updated`. Then smoke test. Steps in the operations guide.

## 9. Support After Handover
- Who to call first (hospital IT), and the developer's contact and support period, if any, written on the acceptance form.
- Common problems and fixes are in the operations guide (locked account, failed backup, server won't start, printer/PDF issues).
