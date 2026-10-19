# Manual test list: Windows scripts (Step 6i-2)

Pest cannot test `.bat` files. Do this on a **spare PC or VM** with Windows and Laragon Full (PHP 8.4.1+, MySQL 8). Never use the real server. Tick each box and write down anything odd. Have a second PC (an "office PC") on the same network, and a place for backups, e.g. `\\BACKUP-PC\census-backups`.

The `.bat` files were only tested with fakes, away from a real Laragon. **Expect to find small problems in sections 1, 2, 8 and 9.** Write down the exact text on screen when something fails.

**Prepare**
- [ ] Also do section 0 (fresh Windows PC) once, before the rest.
- [ ] Spare PC with Laragon installed. **Open Laragon once, Start All, Stop All, Exit**, so Laragon makes `my.ini` and `httpd.conf`.
- [ ] Copy the built `system` folder to the spare PC (with `vendor` and `public\build`, no `.env`).
- [ ] Know the MySQL root password (Laragon's default is empty).
- [ ] A local Windows account for the backup task (password never expires) and the shared backup folder.

## 0. Fresh Windows PC with only the items from docs/requirements.md installed
Use a clean Windows 10 or 11 64-bit PC or VM (a snapshot you can go back to is best). Install **only** what `docs/requirements.md` part 1 lists, in that order, from the handover USB. **No** Composer, Node.js or Git, and no internet.
1. [ ] Fill in the "Version tested" column in `docs/requirements.md` with the exact file names and versions you used (Visual C++ Redistributable, Laragon, PHP zip if you needed it).
2. [ ] Follow the step-by-step list in `docs/requirements.md` word for word. Write down each place you got stuck and each step that was unclear. Take the `[screenshot: ...]` pictures as you go.
3. [ ] Copy the ready-to-install folder from the USB (built as in `docs/requirements.md` part 2). Check it has `vendor\` and `public\build\`, and no `.env`, `node_modules` or `.git`.
4. [ ] **Before installing Visual C++:** run `install.bat` as administrator. Expect FIX for the Visual C++ Redistributable ("Install vc_redist.x64.exe from the handover USB ..."), and **nothing changed** (no `.env`, no database, no services). Then install it, restart.
5. [ ] With Laragon's PHP older than 8.4.1 (if it is): run `install.bat`. Expect FIX with "PHP is x.y.z. PHP 8.4.1 or newer is needed." and the pointer to "Adding PHP to Laragon". Follow that section, run again.
6. [ ] Start another web server (or IIS) on port 80 and run `install.bat`. Expect FIX naming the program. Stop it.
7. [ ] Turn off one PHP extension (for example `gd`) in Laragon and run `install.bat`. Expect, after the requirements list, `census:install` to say FIX for that extension. Turn it back on.
8. [ ] With everything right, the requirements list says OK for every item and the install goes on (continue with section 1).
9. [ ] **No internet needed at any point.** Confirm the PC was offline, and the install worked.

## 1. Fresh install
1. [ ] Double-click `install.bat` **without** administrator. Expect: plain message "THIS FILE MUST BE RUN AS ADMINISTRATOR", the window waits, a line in `storage\logs\scripts.log`.
2. [ ] Right-click, Run as administrator. If Laragon is not on `C:`, expect the question for the `php.exe` path. Type a wrong path first (expect "does not exist"), then the right one.
3. [ ] Expect: "Open Laragon and click Start All". Do it, press a key.
4. [ ] Answer the install questions. Expect the two temporary passwords once.
5. [ ] Expect: "close Laragon" (Stop All, Exit). Leave Laragon open on purpose and press a key. Expect "Laragon is still running". Close it, press a key.
6. [ ] Expect these lines: `bind-address=127.0.0.1` OK, `00-census.conf` OK, both services OK and started, `http://localhost/ shows the login page`.
7. [ ] Open `Laragon\bin\mysql\...\my.ini`: it has `bind-address=127.0.0.1`. If the line was added, `my.ini.bak` exists.
8. [ ] Open `Laragon\etc\apache2\sites-enabled\00-census.conf`: it points to `...\system\public`.
9. [ ] Windows **Services** app: **CensusMySQL** and **CensusApache** are Running, Automatic (Delayed Start). Right-click, Properties, Recovery: first, second and subsequent failures say "Restart the Service".
10. [ ] Open Laragon. Its auto-start options are off (Menu, Preferences: "Run Laragon when Windows starts" and "Start All automatically" unticked). Tick **nothing** back on. Do **not** click Start All.
11. [ ] Backup question: type the Windows account and password. Expect "The task ran a real backup and it worked" and a new `.sql` and `.zip` in the backup folder.
12. [ ] Task Scheduler: a task **Census - Scheduler**, every 1 minute, "Run whether user is logged on or not".
13. [ ] Wait 3 minutes. Run `health.bat`. Expect the scheduler line OK.
14. [ ] Firewall, Inbound rules: **Census - Web (local network only)** (allow, remote = local subnet) and **Census - Block MySQL from outside** (block, port 3306).
15. [ ] The window ends with `http://<server name>/` and the IP addresses.
16. [ ] `storage\logs\scripts.log` and `install.log` contain **no passwords**.

**Failure paths (do once):**
- [ ] Rename `my.ini` and run `install.bat` on a spare copy: expect "A file that Laragon makes was not found" with "Open Laragon once, click Start All, then Stop All...". Rename it back.
- [ ] Start another program on port 80 (or stop CensusApache and run IIS/another server) and run `install.bat`: expect "FAILED to start CensusApache" and "Another program may be using port 80".

## 2. Run again
1. [ ] Run `install.bat` as administrator again. Expect "already installed", the safe re-check (`--repair`), **no** "Start All" prompt, no new accounts, no data change.
2. [ ] Services keep running (not recreated). The step "Checking that MySQL listens..." says OK.
3. [ ] Task Scheduler still has exactly one Census - Scheduler task. Firewall has exactly one of each rule. `sites-enabled` still has one `00-census.conf`.
4. [ ] The real backup through the task runs again and works.

## 3. A real backup running as census_app (not root)
1. [ ] Log in at `http://localhost/` as Admin, encode two test visits.
2. [ ] Admin: Backups, Run backup now. Expect Success, a `.sql` and a `.zip` in `BACKUP_PATH`, both sizes shown.
3. [ ] Check it ran as `census_app`: in `.env`, `DB_USERNAME=census_app`; no root password was asked.
4. [ ] Stop the backup PC (or rename the shared folder), Run backup now. Expect a failed run and the red bar. Put it back.
5. [ ] Log off (do not shut down). Next morning the 02:00 backup exists, made by the task with no one logged in.

## 4. Backups fail after a Windows password change
1. [ ] Change the password of the Windows account the task uses.
2. [ ] Wait 2 minutes. `health.bat` shows a Problem for the scheduler or the backup, or the next Run backup now fails. (Task Scheduler shows a last result other than 0x0.)
3. [ ] Run `install.bat` as administrator, type the account and the **new** password. Expect "The task ran a real backup and it worked".
4. [ ] `health.bat` is all OK again.

## 5. Update
1. [ ] Note the patient, visit and user counts.
2. [ ] Copy the same `system` files over the install (keep `.env` and `storage`).
3. [ ] Run `update.bat` as administrator. Expect: a backup first, maintenance mode, root password asked (hidden), site back.
4. [ ] Counts are the same. Open the site and log in.
5. [ ] Break the backup folder, run `update.bat`. Expect it to stop before changing anything.

## 6. Restore of that backup (counts match)
1. [ ] Note the counts and the date of the last visit. Run Backups, Run backup now.
2. [ ] Encode one more test visit (so the counts differ).
3. [ ] Run `restore.bat` as administrator. Expect a numbered list, newest first.
4. [ ] Type `abc`, then a number that does not exist. Expect "Nothing was changed".
5. [ ] Type the number of the backup from step 1. Type `RESTORE`. Give the root password.
6. [ ] The counts printed at the end equal the counts from step 1 (not the extra visit). The logo and signature pictures are back.
7. [ ] Log in again (everyone was signed out). The extra visit is gone.

## 7. reset-admin-password.bat
1. [ ] Run it as administrator on the spare PC. Type two different passwords. Expect "do not match", nothing changed.
2. [ ] Run it again with a good password. Expect success.
3. [ ] Log in as Admin with it. Expect the forced "choose a new password" step.
4. [ ] Lock the Admin with 5 wrong passwords first, then run the file. Expect the account unlocked.
5. [ ] The Audit log shows `user.password_reset`, and `scripts.log` has no password.

## 8. Reboot with no one logged in (auto-start)
1. [ ] Restart the PC. **Do not log in on it.**
2. [ ] After 3 to 5 minutes, from the office PC open `http://<server name>/`. Expect the login page. (Delayed start can take a few minutes.)
3. [ ] Log in at the office PC, encode a visit. Then log in on the spare PC: `health.bat` is all OK, and the scheduler line is fresh. Laragon is **not** running (check the tray).
4. [ ] Cut the power (VM: power off), switch on, repeat steps 2 and 3.
5. [ ] Wait until the next 02:00, or check the day after a reboot: the backup exists (the task also works with no one logged in).

## 9. Crash: a service restarts by itself
1. [ ] Task Manager, Details, right-click **mysqld.exe**, End task. (Or in an administrator Command Prompt: `taskkill /F /IM mysqld.exe`.)
2. [ ] The site shows an error at first. Within about 1 to 2 minutes `mysqld.exe` is back and the Services app shows CensusMySQL Running. `http://<server name>/` works again.
3. [ ] Do the same with **httpd.exe** (there are two processes: end both). CensusApache comes back within about 1 to 2 minutes.
4. [ ] Test records are still there (nothing lost: a crash can lose the last seconds of work, so note anything encoded just before).

## 10. Office PC access
1. [ ] From another PC on the same network, open `http://<server name>/`. Expect the login page.
2. [ ] Open `http://<server IP>/`. Same page (the site answers for any name or IP).
3. [ ] Log in as each role, encode a visit, run a report, export PDF and Excel.

## 11. Firewall: port 3306 and port 80
1. [ ] From the office PC, in PowerShell: `Test-NetConnection <server IP> -Port 3306`. Expect `TcpTestSucceeded : False`.
2. [ ] `Test-NetConnection <server IP> -Port 80`. Expect `True`.
3. [ ] From a phone on mobile data, or a PC on another network, port 80 does not answer.
4. [ ] On the server, the system still works (it reaches MySQL on its own PC).
5. [ ] Temporarily delete the block rule only: 3306 from the office PC still fails, because MySQL listens on `127.0.0.1` only (the second layer). Run `install.bat` to put the rule back.

## 12. uninstall-tasks.bat (no data touched)
1. [ ] Run it, type `NO`. Expect "Cancelled".
2. [ ] Run it, type `YES`. Expect: the task, both services and both firewall rules removed. The Apache site file and `my.ini.bak` stay.
3. [ ] Services app no longer lists CensusMySQL and CensusApache. The site is down (expected).
4. [ ] Run `install.bat` again (no data loss: the install says "already installed", repairs, recreates services, task and rules). Log in: all test data is still there.

## 13. health.bat
1. [ ] Run it. Expect all OK or Warning.
2. [ ] Stop the CensusMySQL service. Run it. Expect a Problem with a "What to do" line, and the window waits. Start the service again.
