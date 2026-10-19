# Operations Guide

_Version: DRAFT (Step 6i-3) · Matches system: Step 6i-3 · Phase 7.5 will finish this guide, add screenshots and have it tested by someone who did not write it._

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
1. Log in. Look for a red bar at the top. A red bar means the last backup or the test restore failed, or automatic tasks are not running. Tell IT the same day. A yellow bar means the weekly USB copy or the monthly test restore is overdue.

   **The x only hides a warning. It comes back tomorrow (yellow) or at your next sign-in (red) until the problem is fixed.** Closing a bar never fixes or marks anything. The **Backups** and **System health** pages always show the full status.
2. Open **System health** (left menu, under Administration). Every line should say OK.

**The custodian's three tasks in one place** (the one-page card will copy this):
- **Daily:** log in, look for a red bar, open **System health**. The x on a bar only hides it until tomorrow (yellow) or your next sign-in (red).
- **Weekly:** plug the USB into the **server PC**, click **Copy to USB** on **Backups**.
- **Monthly:** check that **Test restore** on **Backups** is green (Passed).

**Every week (Admin or custodian, 2 minutes):**
1. Go to the **server PC** and plug the USB drive into it. It must be the server, not your office PC.
2. On your own PC, open **Backups** and click **Copy to USB**. Choose the drive, click **Copy to USB** again.
3. Wait for the green message: "Copied ... to E: (... MB). Store the USB in another room." The weekly USB copy is marked as done by itself.
4. Unplug the drive and keep it in another room or a locked cabinet.

If it says "No USB drive found", the drive is in the wrong PC or is not ready. Plug it into the server, wait a few seconds and click **Look again**. Other messages are in `docs/troubleshooting.md`, section 8. The USB keeps the newest 8 backups. Nothing is downloaded to the office PC.

**Every month (Admin or custodian, 1 minute):** the system tests the latest backup **by itself** on the 1st at 03:00. It loads the backup into a scratch database and compares the number of patients, visits, users and closed months. Open **Backups**. Under **Backup care**, "Test restore" must say **Passed** with a date in the last month. A yellow reminder "The monthly test restore is overdue" means it has not passed for 35 days: click **Run test restore now**. A red bar "The automatic test restore failed" means call IT and see `docs/troubleshooting.md`, section 7. The **System health** line "Test restore of backups" shows the same.

**Every month (IT):** check free disk space on the server and the backup PC. A restore on a spare PC (section 4) is now only needed to practise, for example once a year or after moving to a new server.

**After updating from an older version:** run `install.bat` once as administrator. It creates the MySQL account `census_restore_test` (rights on the scratch database `patient_census_restore_test` only) and saves its password in `.env`. It asks for the MySQL root password once. Until then, the test restore says "The test restore account is not set up".

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

---

## Not yet in this draft (Phase 7.5)

- Screenshots for every step.
- A tested recovery guide for a technician who has never seen the system.
- The one-page custodian card (daily: no red bar; weekly: Copy to USB; monthly: test restore is green). The tasks are written in section 2 now.
