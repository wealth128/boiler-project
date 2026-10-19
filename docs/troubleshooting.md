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

1. **Is the terminal still open and printing the line every minute?** If it stopped or shows an error, read the error. A database error means MySQL is not running (start it in Laragon).
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
1. Task Scheduler: the task exists, is enabled and its last result is "0x0" (success).
2. The task uses the same PHP as Laragon (full path to `php.exe`) and starts in the `system` folder.
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

## 6. Still stuck

Write down the exact message, the time, and what you clicked. Check `storage/logs/laravel-<date>.log` (no passwords are written there) and, for setup problems, `storage/logs/install.log`. Then tell the developer or hospital IT.
