# Troubleshooting

_Last updated: 2026-10-19 · For the developer and for hospital IT. Each problem says what you see, why it happens, and what to type._

Run commands in the `system` folder. On the hospital server, use the same commands there. A quick overall check, any time:

```
php artisan census:health
```

It lists every check as OK, WARNING or PROBLEM with a "What to do" line. The same list is on the **System health** page (Admin).

---

## 1. Error 500: "Unknown column ..." or "Base table or view not found"

**What you see:** a page like "Internal Server Error", with `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'zip_name'` (or another column name), usually right after new code was copied or pulled.

**Why:** the new code needs a change in the database (a *migration*), and that change has not been run on this database yet.

**Fix on a dev PC:**

```
php artisan migrate:status --pending
php artisan migrate
php artisan migrate:status --pending
```

The first command lists what is waiting. The last one must say "No pending migrations." Then repeat what you were doing.

**Fix on the hospital server:** do not use plain `migrate`. The daily account `census_app` is not allowed to change tables, so it would fail. Run:

```
php artisan census:update
```

It takes a backup first (and stops if that fails), asks for the MySQL root password, runs the migrations, rebuilds the caches and brings the site back.

**After it:**
- If you pressed **Run backup now** when it failed, the files may have been written but the run was not recorded. Run `php artisan backup:run` once so the Backups page shows a good run.
- Never run `migrate:fresh` or `migrate:reset` on `patient_census`. They erase every table. Tests run only on `patient_census_test`.

**To avoid it:** after every `git pull` or merge on a dev PC, run `php artisan migrate`. On the server, always finish an update with `census:update`.

---

## 2. Red bar: "Automatic tasks are not running (no backup, no 8 PM sign-out). Tell IT."

**Why:** the scheduler saves a "last ran" time every minute. The bar shows when it is more than 5 minutes old, or has never run. Without the scheduler there is no 02:00 backup and no 8 PM sign-out.

### Do this now (dev PC)

Nothing starts the scheduler for you, so the bar shows whenever it is not running. This is normal on a dev PC, not a fault.

1. Open a **new terminal** in the `system` folder.
2. Type this and **leave the window open**:

   ```
   php artisan schedule:work
   ```

   It prints a line like `Running [scheduler-heartbeat] ... DONE` every minute. If you see that line, it is working.
3. Go to the browser and **reload the page** (F5). The bar goes away. It only updates when a page loads, so it can stay on screen until you reload.

Running `php artisan schedule:run` once is not enough: it clears the bar for only 5 minutes. And if you close the terminal, the bar comes back about 5 minutes later.

### Still showing after that?

Check these in order:

1. **Is the terminal still open and printing the line every minute?** If it stopped or shows an error, read the error. A database error means MySQL is not running (on a dev PC, start it in Laragon; on the hospital server see section 6).
2. **Did you reload the page?** Press F5 on the page, not only in the sidebar.
3. **Is it the same project?** Run the command from the same `system` folder that the browser is using (the one with the `.env` for `patient_census`).
4. **Same database?** The "last ran" time is saved in the cache table of the database in `.env`. If the web page and the terminal use different `.env` files or databases, the bar never clears.
5. **Check it from the terminal:**

   ```
   php artisan census:health
   ```

   Look at the line `Automatic tasks (scheduler)`. It must say `OK` and "Ran at" a time within the last 5 minutes (the time is in Philippine time).
6. **Is the PC clock right?** A clock that is wrong makes the "last ran" time look old.

**Hospital server:** a Windows Task Scheduler task must run this **every minute**, from the `system` folder:

```
php artisan schedule:run
```

Check, in this order:
1. Task Scheduler: the task "Census - Scheduler" exists, is enabled and its last result is "0x0" (success). If it is missing, run `install.bat` again (Run as administrator): it re-creates the task and changes no data.
2. The task runs `systemscriptswindowsun-scheduler.bat`, which uses the PHP found by `install.bat`.
3. The Windows account that runs it can write to `storage/`.
4. Run `php artisan schedule:run` by hand, then `php artisan census:health`. The scheduler line should now be OK.

---

## 3. Red note: "No successful backup since ..." or "The last backup failed"

**Why:** no backup was recorded in the last 26 hours, or the last one failed.

**Do:**
1. Open **Backups** and click **Run backup now**. The Backups page shows the error if it fails (the database password is hidden).
2. Or in the terminal: `php artisan backup:run`.
3. Still failing? Run `php artisan census:health` and read the lines about the backup folder and disk space.

**Common causes:**
- The backup folder (`BACKUP_PATH`) cannot be reached: the backup PC is off, or the shared folder or its permissions changed.
- The disk is almost full (warning under 10 GB, problem under 2 GB).
- `MYSQLDUMP_PATH` points to a file that is not there.
- The picture zip failed. A backup counts only when **both** files were written: the database dump and the picture zip.
- The migration in section 1 was not run (the run is made but cannot be saved).

On a dev PC the daily 02:00 backup only runs while `schedule:work` is running, so an old note there is normal after a day without it.

### Backups fail after a Windows password change

**What you see:** the red note "The last backup failed" starts on the day someone changed the password of the Windows account that runs the task "Census - Scheduler". Usually the backup folder is on another PC (`\BACKUP-PC\...`). `health.bat` shows the backup as a Problem.

**Why:** the task "Census - Scheduler" runs as a Windows account so it can write to the backup PC. Windows saved the old password inside the task. After the password changes, the task can no longer sign in, so no backup (and no 8 PM sign-out) runs.

**Fix (on the server PC):**
1. Right-click `system\scripts\windows\install.bat`. Choose **Run as administrator**.
2. It re-checks the system and changes no data. When it asks for the Windows account, type the same account and the **new password**.
3. At the end it runs one real backup through the task. It must say "The task ran a real backup and it worked."

**To avoid it:** use an account whose password never expires, made only for this job.


---

## 4. System health says "MySQL program (for restore)": Warning

**Why:** `census:restore` loads a backup with the `mysql` program, and this check runs `mysql --version`. Everything else, including backups, still works.

**Fix:** set `MYSQL_PATH` in the server settings file (`.env`) to the full path of `mysql.exe` (it is in the same folder as `mysqldump.exe`, for example `C:/laragon/bin/mysql/mysql-8.0.30/bin/mysql.exe`), then run `php artisan census:install --repair`. That also fills it in for you when it can find the file. If it is not fixed, the restore asks for the path when you run it.

---

## 5. If a restore or an update stops half-way

The site is left in **maintenance mode on purpose**, and the command prints the next steps. In short:

1. Fix the problem named in the message, then bring the site back with `php artisan up`.
2. Or undo it with the backup taken just before: `php artisan census:restore <backup file name>`.

The backup file is in `BACKUP_PATH`, named like `patient_census_2026-10-19_110711.sql`.

---

## 6. After a restart the site does not open (database or web server service)

On the hospital server, MySQL and Apache are Windows services made by `install.bat`: **CensusMySQL** and **CensusApache**. They start by themselves when Windows starts, with nobody logged in, and Windows restarts them by itself after a crash (3 tries, one minute apart).

**Never click "Start All" in Laragon on this server.** Laragon would try to start its own copy and fight with the services for the same ports. If someone did, click **Stop All**, close Laragon (Exit), then restart the services below.

**Check:**
1. Double-click `health.bat` (Run as administrator).
2. Open the Windows **Services** app. Look for CensusMySQL and CensusApache. Both must say Running and Automatic (Delayed Start).
3. If one is stopped, right-click it and choose Start. If it stops again, open Windows Event Viewer, Windows Logs, Application.

**Common causes:**
- Another program uses port 80 (IIS, Skype, another web server). Apache cannot start. Stop that program.
- Laragon was opened and Start All was clicked. See above.
- The services are missing (someone ran `uninstall-tasks.bat`). Run `install.bat` again. It is safe and changes no data.
- The page shows an Apache error and not the login page: run `install.bat` again. It rewrites the site file and checks that `http://localhost/` shows the login page.

---

## 7. Red bar: "The automatic test restore failed on ..."

**What it is:** on the 1st of every month at 03:00 (and when Admin clicks **Run test restore now** on **Backups**), the server loads the latest backup into a scratch database, `patient_census_restore_test`, and compares the number of patients, visits, users and closed months with the numbers saved when the backup was made. The live database is never touched. The red bar stays until a later test restore passes. It also shows on **Backups** and as a Problem on **System health** ("Test restore of backups").

| The message says | Why | Do this |
|---|---|---|
| "There is no successful backup to test." | No backup has worked yet | Click **Run backup now**, then **Run test restore now**. |
| "The latest backup has no saved record counts" | The latest backup was made before this feature existed | Click **Run backup now**, then **Run test restore now**. |
| "The test restore account is not set up" | An older server has no `census_restore_test` account | Right-click `install.bat`, **Run as administrator**. It repairs the settings and asks for the MySQL root password once. Then click **Run test restore now**. |
| "This backup file is incomplete" or "The picture zip ... cannot be opened" | The backup file is damaged (disk full, copy stopped) | Click **Run backup now**, check free space on the backup PC, then **Run test restore now**. |
| "The restored copy does not match the backup (visits: backup 40, restored 39 ...)" | A real difference, or someone encoded while the backup was being made | Click **Run test restore now** once more. If it fails again, **do not ignore it**: tell the developer. The backups may not be good. |
| "A backup was still running at the scheduled time" | The 02:00 backup took more than an hour | Click **Run test restore now** later in the day. |
| Anything about "Access denied" or a MySQL error | The `census_restore_test` account or its password in `.env` is wrong | Run `php artisan census:install --repair` (or `install.bat`). If `RESTORE_TEST_PASSWORD` in `.env` is wrong, empty it first and run the repair again. |

If a test restore was stopped by a power cut, the scratch database may still exist. It is dropped at the start of the next test. It never holds anything the live system needs.

---

## 8. Copy to USB says it cannot copy

The **Copy to USB** button on **Backups** makes the **server** copy the latest backup to a folder `census-backups` on a USB drive. **The USB drive must be plugged into the server PC**, not into the office PC where you are clicking.

| The message says | Why | Do this |
|---|---|---|
| "No USB drive found" / "is not a removable USB drive plugged into the server" | The drive is in the wrong PC, not ready yet, or Windows calls it a fixed disk | Plug it into the server PC, wait a few seconds, click **Look again**. Some external hard disks and a few sticks show up as fixed disks and are never listed: use an ordinary USB stick. |
| "The USB drive is full" | Not enough free space for the two files | Delete old files you no longer need from the stick, or use a bigger one. Only files in `census-backups` named like a census backup are ever deleted by the system. |
| "... is not the same size as the original" | The stick is faulty or was pulled out | Nothing was kept on it. Try again; if it repeats, use another stick. |
| "The folder census-backups could not be created" | The stick is write-protected | Slide the write-protect switch, or use another stick. |
| "The latest backup files are missing" or "damaged" | The backup folder changed or the last backup is bad | Click **Run backup now**, then **Copy to USB** again. |
| "A backup or a test restore is running" | Only one of these runs at a time | Wait a few minutes and try again. |

A failed copy does not mark the weekly USB copy as done, so the yellow reminder stays. The stick keeps the newest 8 backups.

---

## 9. Still stuck

Write down the exact message, the time, and what you clicked. Check `storage/logs/laravel-<date>.log` (no passwords are written there) and, for setup problems, `storage/logs/install.log`. Then tell the developer or hospital IT.
