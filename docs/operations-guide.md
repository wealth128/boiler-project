# Operations Guide

_Version: DRAFT (Step 6j, speed settings 7g) · Matches system: Step 7g · Phase 7.5 will finish this guide, add screenshots and have it tested by someone who did not write it._

This guide is for the person who looks after the server PC. You do not need to type commands. Everything is a file you double-click.

## Start here

**What to install first** (Windows, Visual C++, Laragon Full, PHP, the ready-to-install folder) is in [docs/requirements.md](requirements.md). Do that before the first install below.

### The five files

They are in the folder `system\scripts\windows\` on the server PC.

| File | Use it to |
|---|---|
| `install.bat` | Set up the system on a new server PC. |
| `update.bat` | Put a new version of the system in place. |
| `restore.bat` | Put a backup back, after a server broke. |
| `health.bat` | Check that everything works. **Run this first when "the system is not working".** |
| `reset-admin-password.bat` | Give the Admin a new password when they forgot it. |

There is also `uninstall-tasks.bat`. Use it only when IT tells you to. It removes the automatic task, the two Windows services (database and web server) and the firewall rules. The system stops working until you run `install.bat` again. It never touches your data.

> **Never click "Start All" in Laragon on this server. The system starts by itself.**
> MySQL and Apache run as Windows services (CensusMySQL and CensusApache). They start when Windows starts, with nobody logged in, and Windows restarts them if they crash. Laragon is only used once, at the first install.

**How to run any of them:** right-click the file, choose **Run as administrator**, click **Yes**. If you just double-click, the file tells you in plain words to do it again as administrator. When it ends, the window waits. Press any key to close it.

**Where the record is:** every file writes a line to `system\storage\logs\scripts.log`. Passwords are never written there.

---

### 1. First install

You need: the server PC with everything in [docs/requirements.md](requirements.md) installed (Visual C++ Redistributable, Laragon Full with PHP 8.4.1 or newer and MySQL 8), the `system` folder copied onto it, the MySQL root password, and the backup folder on the second PC.

1. On the server PC, open the `system\scripts\windows` folder.
2. Right-click `install.bat`. Choose **Run as administrator**. Click **Yes**.
3. If it says it cannot find Laragon's `php.exe`, open the Laragon folder, then `bin`, then `php`, then the newest `php-8.4` (or newer) folder. Copy the full path of `php.exe` and paste it in the window.
4. The window first checks this PC against the requirements list and prints OK or FIX for each item. If anything says **FIX**, nothing has been changed yet. Do what the line says (details in [docs/requirements.md](requirements.md)), then run `install.bat` again.
5. Next the window asks you to open Laragon and click **Start All** (MySQL must run for the install). Do it, then press any key. This is the only time you use Start All.
6. Answer the questions. The MySQL root password is typed hidden. It is used once and never saved. If MySQL has no root password yet, you must set one now (see "About the MySQL root password" below). For the backup folder, see "About the backup folder" below.
7. If a line says **FIX**, do what the line says. Then run `install.bat` again.
8. When asked for the Admin and System Admin names, type them. The window then shows two **temporary passwords, once**. Write them down and put them in the sealed envelope. They cannot be shown again.
9. The window now asks you to **close Laragon**: click **Stop All**, then Exit. Do it, then press any key. The install then makes MySQL and Apache Windows services, sets them to start with Windows and restart after a crash, makes the web server show the system on the web port, and checks that `http://localhost/` shows the login page. If a file Laragon should have made is missing, it says so and tells you what to do (usually: open Laragon once, click Start All, then Stop All, close it, and run `install.bat` again).
10. If the backups go to the second PC, Windows asks for the Windows account that can write there, and its password. Type them. **Use an account whose password never expires**, made only for this job. If its password is changed later, backups stop until you run `install.bat` again and type the new password.
11. The install then runs one real backup through the automatic task and shows whether it worked. It must say it worked.
12. At the end the window prints the address for the office PCs, for example `http://CENSUS-SERVER/`. Write it on a card next to each office PC.
13. Double-click `health.bat` (as administrator). Every line must say OK. A Warning is fine for now. A Problem needs fixing.
14. From one office PC, open the address and log in with the Admin's temporary password. The system asks for a new password.

**About the backup folder.** The install asks for the backup folder. It is saved as `BACKUP_PATH` in the settings file (`.env`). Use one of these:
- **A shared folder on a second PC**, for example `//BACKUP-PC/census-backups`. This is the best choice.
- **A second disk inside the server**, for example `D:/census-backups`.

**Never use only the server's main disk (C:).** If that disk breaks, the backups break with it. Type the folder with **forward slashes** (`/`), not backslashes. The install makes the folder if it can and checks that it can write a file there. A shared folder on another PC must be switched on and reachable, or the backup fails and a red bar shows.

**About the MySQL root password.** A new Laragon starts with no root password. The install notices this and makes you choose one before it goes on: type it twice (nothing shows), at least 12 characters. When it says "Write this in the sealed envelope.", write it down and put it in the sealed envelope. Nobody else needs it, and it is never saved on the PC. You will need it for `update.bat` and `restore.bat`. If it is ever missing, System health shows a Problem for "MySQL root password".

Running `install.bat` again on a server that already works is safe. It only re-checks and repairs. It never deletes data and never creates new accounts.

### 2. Daily and weekly checks

**Every morning (Admin, 1 minute):**
1. Log in. Look for a red bar at the top. A red bar means the last backup or the test restore failed, the audit log clean-up failed, or automatic tasks are not running. Tell IT the same day. A yellow bar means the weekly USB copy or the monthly test restore is overdue.

   **The x only hides a warning. It comes back tomorrow (yellow) or at your next sign-in (red) until the problem is fixed.** Closing a bar never fixes or marks anything. The **Backups** and **System health** pages always show the full status.
2. Open **System health** (left menu, under Administration). Every line should say OK. One line is **Audit log clean-up**: OK means it ran in the last 2 days. Another is **Speed settings** (section 11).

**The custodian's tasks in one place** (the one-page card will copy this):
- **Daily:** log in, look for a red bar, open **System health**. The x on a bar only hides it until tomorrow (yellow) or your next sign-in (red).
- **Weekly:** plug the USB into the **server PC**, click **Copy to USB** on **Backups**.
- **Monthly:** check that **Test restore** on **Backups** is green (Passed).
- **Every 3 months, and every time the lists change** (a new rank, branch or diagnosis): in **Records > Import**, click **Download template**. Copy the new file to every office PC (replace the old one) and print a fresh copy for each office. The file name has the date. The PC copy and the paper copy are what the offices use when the system is down (section 8).

**Every week (Admin or custodian, 2 minutes):**
1. Go to the **server PC** and plug the USB drive into it. It must be the server, not your office PC.
2. On your own PC, open **Backups** and click **Copy to USB**. Choose the drive, click **Copy to USB** again.
3. Wait for the green message: "Copied ... to E: (... MB). Store the USB in another room." The weekly USB copy is marked as done by itself.
4. Unplug the drive and keep it in another room or a locked cabinet.

If it says "No USB drive found", the drive is in the wrong PC or is not ready. Plug it into the server, wait a few seconds and click **Look again**. Other messages are in `docs/troubleshooting.md`, section 8. The USB keeps the newest 8 backups. Nothing is downloaded to the office PC.

**Every month (Admin or custodian, 1 minute):** the system tests the latest backup **by itself** on the 1st at 03:00. It loads the backup into a scratch database and compares the number of patients, visits, users and closed months. Open **Backups**. Under **Backup care**, "Test restore" must say **Passed** with a date in the last month. A yellow reminder "The monthly test restore is overdue" means it has not passed for 35 days: click **Run test restore now**. A red bar "The automatic test restore failed" means call IT and see `docs/troubleshooting.md`, section 7. The **System health** line "Test restore of backups" shows the same.

**Every month (IT):** check free disk space on the server and the backup PC. A restore on a spare PC (section 4) is now only needed to practise, for example once a year or after moving to a new server.

**After updating from an older version:** run `install.bat` once as administrator. It creates the MySQL account `census_restore_test` (rights on the scratch database `patient_census_restore_test` only) and saves its password in `.env`. It asks for the MySQL root password once. Until then, the test restore says "The test restore account is not set up".

### Audit log: kept for 1 year

Every day at 04:30 the system removes audit log entries older than 1 year. **Before it deletes anything, it writes them to a file** in the backup folder (`BACKUP_PATH`), named `audit-archive_YYYY-MM-DD.csv.gz`, and checks the file. Then it adds one entry, **Old audit entries archived and removed**, with the count and the dates. Nobody can delete audit entries by hand: there is no button for it.

- The archive files are small. **Keep them.** The 30-day backup cleanup never deletes them. **Copy to USB** also copies the newest one.
- Open an archive file with a program that reads `.csv.gz` (for example 7-Zip, then Excel). The details column holds the old and new values as JSON.
- If the archive cannot be written (backup folder missing, disk full), **nothing is deleted** and Admin sees a red bar: "Audit log clean-up failed: ...". It tries again the next night and the bar goes away after a run that works. See Troubleshooting.
- **Data Privacy:** Confirm the 1-year audit retention with the hospital's Data Protection Officer; the archive files keep older entries if needed.

### 3. Update to a new version

1. Take the new `system` folder from the developer.
2. Copy its files over the old ones on the server. **Keep** the `.env` file and the `storage` folder. Do not delete them or replace them.
3. Right-click `update.bat`. Choose **Run as administrator**.
4. It takes a backup first. If that backup fails, it stops and changes nothing.
5. It asks for the MySQL root password (hidden). It then updates and brings the site back.
6. Double-click `health.bat`. Then log in from one office PC and check one report.

If the update stops with an error, the site stays in maintenance mode on purpose. Read the message. It tells you the exact way back. Then call the developer.

### 4. Restore after a broken server

**Anything encoded after the last backup is lost. It must be encoded again from paper.**

1. Set up a replacement PC the same way as a first install (section 1). Give it the **same backup folder** as the old server.
2. Make sure the backup PC is on and the shared folder opens.
3. Right-click `restore.bat`. Choose **Run as administrator**.
4. It lists the backups, newest first, with numbers. Type the number of the newest one. Press Enter.
5. It shows the backup's date and size. Type `RESTORE` to confirm.
6. It takes a safety backup of what is there now. Then it asks for the MySQL root password (hidden), loads the backup and the pictures, and signs everyone out.
7. At the end it shows the number of patients, visits and users, and the date of the last encoded visit. Check them with the Admin. They must match what the Admin expects.
8. Tell the office PCs the address again if it changed.

If there is no picture file for that backup, the window warns you. The logo and signature pictures then have to be added again in Hospital settings.

The backup file does not hold the rows of three throwaway tables: remembered reports (`cache`), locks (`cache_locks`) and login sessions (`sessions`). Their structure is in the file, so after a restore the tables are there, empty. Everyone logs in again, and the first report of each period is counted again. Nothing of value is lost. This keeps the backup file from growing with old rows. Every night at 1:30 AM the system also deletes the expired rows of the `cache` and `cache_locks` tables by itself. There is nothing to do.

### 5. "The system is not working"

1. Do not restart anything yet.
2. On the server PC, right-click `health.bat` and choose **Run as administrator**.
3. Read each line. A line that says Problem has a "What to do" line below it. Do exactly that.
4. Run `health.bat` again. Repeat until there is no Problem.

| If the health check says | Usually it means | Do this |
|---|---|---|
| Database not connected | The MySQL service is stopped | Open the Windows Services app, right-click **CensusMySQL**, Start. **Do not use Start All in Laragon.** If it will not start, call the developer. |
| Automatic tasks are not running | The Windows task "Census - Scheduler" is missing or stopped | Run `install.bat` again. It re-creates the task. |
| Backup failed or too old | The backup PC is off, the shared folder is not reachable, or the disk is full | Switch the backup PC on, open its shared folder from the server, free some space. |
| Disk space low | The server disk is nearly full | Free space. Do not delete anything inside the `system` folder. |
| Everything is OK but offices cannot open the page | The Apache service is stopped or the firewall changed | Services app: right-click **CensusApache**, Start. Run `install.bat` again to re-create the site file and the firewall rules. |
| Backups fail after someone changed a Windows password | The task "Census - Scheduler" still has the old password | Run `install.bat` again and type the new password. See Troubleshooting. |

If `health.bat` itself says the system is not installed, you are on the wrong PC or the `.env` file is gone. Do not run `install.bat` on a server that has real data without calling the developer first.

If you still cannot fix it, write down what `health.bat` shows (take a photo of the screen) and call the developer.

### 6. The Admin forgot their password

1. Do this **on the server PC itself**, not from an office PC.
2. Right-click `reset-admin-password.bat`. Choose **Run as administrator**.
3. It shows the Admin account's name. Confirm.
4. Type a new password twice. Nothing shows while you type.
5. Give the new password to the Admin **in person**, not by phone message or chat.
6. The new password is temporary. The Admin must choose their own right after logging in. This also unlocks the account if it was locked.

The System Admin can reset other people's passwords in the system. Only this file can reset the Admin's.

### 7. Moving to a new server

1. Keep the old server switched on until the new one works, if you can.
2. Prepare the new PC as in [docs/requirements.md](requirements.md) (fixed IP address, never sleeps, Windows updated, Visual C++ and Laragon Full installed). Open Laragon once, click Start All, then Stop All, so it makes its settings files.
3. Copy the `system` folder to the new PC. Do **not** copy the old `.env` file. The install makes a new one.
4. Run `install.bat` (section 1). Write down the two temporary passwords.
5. Run `restore.bat` with the newest backup (section 4).
6. Run `health.bat`. Everything must be OK.
7. Give the new server the old server's fixed IP address, or tell every office PC the new address.
8. After one week with no problems, wipe the old server's disk. It holds patient data.

### 8. When the system is down

**Do this NOW, while everything works. It cannot be done during the downtime.**
1. Log in as Admin. Open **Patient Records**, then the **Import** tab.
2. Click **Download template**. You get `census-downtime-template_YYYY-MM-DD.xlsx`.
3. Copy the file to **every office PC** that encodes (for example on the Desktop, in a folder called "Downtime"). Print the **Visits** sheet and the **How to fill** sheet on paper for each office.
4. Download it again every 3 months and whenever a list changes. The lists inside the file (branches, ranks, diagnoses) are copied from the system on the day you download it. An old file still works, but a rank added later is missing from its dropdowns. The office writes it under "Other (specify)" instead.

**During the downtime (the offices):**
1. Open the template on the office PC, or take the printed sheet. Write **one row for each patient seen**, with the real time the patient was seen.
2. Fill the columns marked *. Leave **Age** blank. For a returning patient write the Patient ID if known. Otherwise leave it blank.
3. Write your own name in **Written by**.
4. Do not change the column names, add columns or merge cells. If the sheet is on paper, someone types it into the **Visits** sheet afterwards.
5. Save the file with a clear name, for example `ER-2026-11-03.xlsx`, and give it to the Admin. Use a USB stick or the office PC, never e-mail. The file holds patient data.

**When the system is back (the Admin):**
1. **Patient Records > Import**. Under "2. Upload the filled file", choose the file and click **Upload and check**.
2. Read the preview. Every row is **OK**, **Warning** or **Error**, with the row number of the file and the reason in plain words.
   - **Error:** the row cannot be imported (a required value is missing, a bad date, a month that is closed, a name not on the list, a Patient ID that does not match...). Fix the file and upload it again. Rows with errors are never imported.
   - **Warning:** the row can be imported and you decide. "Will be linked to P-000012" means a patient with the same name and birthdate exists: tick **Save as a new patient instead** only if it is a different person. "Possible duplicate visit" is not ticked by default.
3. Click **Import N visits** and confirm. The chosen rows are saved together: all or none.
4. Open **Patient Records** to check the visits (they show **Imported** and **Written by**). They count in the reports like any other visit.
5. Delete the file copies from the office PCs and the USB stick.

**A month that is already closed** does not accept visits. Reopen it first (Reports), import, then close it again. The system says "<Month> is closed. Reopen <Month> first."

**Imported the wrong file?** Under **Past imports**, click **Undo import**. All its visits (and the patients only that import created) go to Trash. Undo is refused while one of its visits is in a closed month.

**Good to know:**
- Limits: 2 MB and 2,000 rows in one file. A file with more rows (even data far below the last filled row) is refused, never cut: split it. Visits before January 2026 are not accepted.
- The uploaded file is kept on the server for **one hour at most** (until you confirm or cancel), then deleted. A scheduled cleanup runs every 15 minutes, so nothing stays more than about 75 minutes. It is never in a backup.
- A file can be imported **once**. A second click on **Import**, or a second tab, says "This file was already imported." and saves nothing.
- While a month is being closed or reopened, an import (or an undo) waits a few seconds and then asks you to try again. A month closed after the preview refuses the whole import.
- CSV files: in Excel choose **CSV UTF-8**. An ordinary Windows Excel "CSV" is also read correctly (PEÑA stays PEÑA), but `.xlsx` is best.
- Only visits written during a downtime can be imported. Old Google Sheets history is not imported.
- Problems with a file: `docs/troubleshooting.md`, section 9.

### 9. Security settings in the server file (`.env`)

These are set once at install and are checked by **System health** ("Safe settings"). Change them only with IT, then run `php artisan census:install --repair` to rebuild the caches.

| Setting | Value on the hospital server | What it does |
|---|---|---|
| `APP_ENV` | `production` | Turns off developer tools. With `production` the system has no `/storage/...` address and no developer pages at all. |
| `APP_DEBUG` | `false` | A server error shows the plain "Something went wrong" page. Never a stack trace, SQL or file path. If this is `true`, **System health** shows a red problem. |
| `SESSION_SECURE_COOKIE` | `false` while the system runs on plain HTTP. `true` the day HTTPS is switched on. | With `true` the browser sends the login cookie only over HTTPS. **Never set it to `true` on plain HTTP: nobody could log in.** |

Always on, not settable (so a wrong value in `.env` cannot weaken them):
- The login cookie is **HttpOnly** (page scripts cannot read it) and **SameSite=Lax** (other sites cannot use it).
- Every page carries the headers `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` and a `Content-Security-Policy` that allows scripts, fonts and pictures from the system itself only. When a page is opened over HTTPS it also carries `Strict-Transport-Security` (one year, this server only). Over plain HTTP the header is not sent, because a browser ignores it there.
- The hospital logo on the login page is sent without a cookie or a session. Opening the login page of a signed-out visitor still starts the login session, but the logo picture itself does not.
- The log files (`storage/logs`) hide passwords, password hashes, the `APP_KEY` and the database passwords, even if an error message repeats them.

If an office PC shows a blank page after an update and the browser console mentions "Content Security Policy", run `php artisan census:update` again (the page files and the server must be the same version) and tell the developer.

### 10. After a report change: rebuild the saved copies

**You only need this when the developer tells you to.** It is not needed on a new server, where no month has been closed yet.

When a month, quarter or year is closed, the system saves its report as a copy, so the submitted numbers never change. If a later update changes how a report is made up (the developer will say so), the old copies are still in the old shape. This command counts every saved copy again in the new shape. The names, titles and signature pictures that were saved with each period are kept exactly as they were.

1. On the server PC, open a Command Prompt as administrator in the `system` folder.
2. Type `php artisan census:rebuild-snapshots` and press Enter.
3. It shows how many saved copies there are and how many are in an older shape. Type `REBUILD` and press Enter. Anything else changes nothing.
4. It says "Rebuilt N saved copies". The Audit log shows one entry, **Saved report copies rebuilt**, with the number.

If it says a month is being closed or reopened, nothing was changed. Wait a minute and run it again. If it fails halfway, nothing is changed: all copies are rebuilt together or none.

### 11. Speed settings

**System health** checks these on the line **Speed settings**. OK means all are in place. A Warning means the system works but is slower than it should be. `install.bat` and `update.bat` already do the server side; this section is for IT when that line is not OK.

| Setting | On the hospital server | Why |
|---|---|---|
| OPcache | On, with the `php.ini` lines below | Without it PHP reads and compiles about 1,500 program files on every click. |
| Laravel caches | Built: `php artisan optimize` (config, routes, views and events) | `install.bat` and `update.bat` build them. Run `php artisan optimize` again after any change to `.env`. |
| `APP_DEBUG` | `false` | Also a safety setting (section 9). Debug mode is slower too. |
| `LOG_LEVEL` | `warning` (never `debug`) | `debug` writes many lines on every click. |
| `public\hot` | Must **not** exist | It is a developer file. If it is there, pages look for a developer server and do not load. System health shows a **Problem**; delete the file. |
| Windows power plan | **High performance** (or "Best performance" in Settings > System > Power) | On "Balanced", Windows moves background programs such as the web server to the slow, power-saving cores of the processor. On the developer's laptop this made every page 2 to 4 times slower. |

**OPcache lines for `php.ini`** (Laragon: Menu > PHP > php.ini). With PHP 8.4 also make sure the line `zend_extension=opcache` has no `;` in front; PHP 8.5 has OPcache built in.

```ini
[opcache]
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
opcache.save_comments=1
```

Restart the web server after changing `php.ini` (restart the **CensusApache** service, or restart the PC). `validate_timestamps=1` keeps an update simple: new program files are picked up within 2 seconds, no restart needed.

**Kept on purpose:** sessions and the cache stay in the database (`SESSION_DRIVER=database`, `CACHE_STORE=database`). The nightly clean-up at **1:30 AM** deletes expired cache rows; expired sessions are deleted by Laravel itself now and then (about 1 in 50 clicks), using the index on the sessions table. Nothing to do.

**Built pages and pictures:** the scripts and styles in `public\build` have a new name with every update, so browsers keep them for a year (`public\.htaccess`) and download them only once per update. The hospital logo is kept for a day and downloaded again only when a new logo is uploaded.

---

## Not yet in this draft (Phase 7.5)

- Screenshots for every step.
- A tested recovery guide for a technician who has never seen the system.
- The one-page custodian card (daily: no red bar; weekly: Copy to USB; monthly: test restore is green; every 3 months and after a list change: download the downtime template again). The tasks are written in section 2 now.
