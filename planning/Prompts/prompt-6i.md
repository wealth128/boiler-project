# Prompt 6i for Claude Code: Self-setup and self-care

_Paste after 6x is merged and pushed. Split in two, like Reports:_
- **6i-1** builds the commands and the System health page inside the app. Pest can test these.
- **6i-2** adds the Windows double-click files (install, update, restore, health) and the Task Scheduler setup. You test these by hand on a PC.

_Goal: the hospital can install, update, restore and check the system with little or no IT help._

## 6i-1 (paste first)

```
Step 6i-1 – Self-setup and self-care commands + System health page. Create branch step-6i-1-selfcare. Rules: planning/deployment-handover.md, planning/architecture.md section 4 (backups), planning/security.md section 2, planning/rbac.md. Never run the dev seeder. Never store or print a MySQL root password, and never write it to logs, .env or the audit log. Ask before adding any package.
1. Backups include the picture files: every backup (daily and "Run backup now") also zips storage/app/private (logo, signatures, frozen signature folders) next to the database dump, with the same date stamp in the file name. Use the same 30-day cleanup. A run is Success only if both files were written. The Backups page shows both files and their sizes. Store the zip file name and size on backup_runs (migration).
2. php artisan census:install (interactive, safe to run again):
   a. Check PHP 8.3+, the extensions (gd, intl, zip, mbstring, pdo_mysql, fileinfo, openssl), that storage/ and bootstrap/cache are writable, upload_max_filesize and post_max_size are at least 2M, and that MySQL can be reached. Print each as OK / FIX with a plain fix line, and stop on any FIX.
   b. Ask for the MySQL root password (hidden input, used only in memory). Create the database patient_census and the user census_app@localhost with a random strong password. census_app gets only SELECT, INSERT, UPDATE, DELETE, plus the minimum extra rights mysqldump needs (e.g. LOCK TABLES, SHOW VIEW, TRIGGER). List them in planning/security.md.
   c. Write .env from .env.example: APP_ENV=production, APP_DEBUG=false, a new APP_KEY, the census_app credentials, LOG_STACK=daily, MYSQLDUMP_PATH found automatically in Laragon (ask if not found), BACKUP_PATH asked with a default (create the folder and test that it can be written).
   d. Run the migrations with the root connection (runtime config only), then seed ONLY the lists and settings, then ask for the Admin and System Admin usernames and create them with temporary passwords, shown once on screen ("Write these down and put them in the sealed envelope"). After that: config:cache, route:cache, view:cache.
   e. If the system is already installed (APP_KEY set and the users table has rows), refuse unless run with --repair. --repair re-checks, fixes .env keys that are missing and re-runs the caches. It never deletes data or creates new accounts.
   f. Write storage/logs/install.log with what was done, without any passwords.
3. php artisan census:update: run a backup first (stop if it fails), php artisan down, ask for the root password, migrate --force with root, clear and rebuild the caches, php artisan up. Keep .env and storage/. Print the result and write audit entry system.updated.
4. php artisan census:restore {file}:
   a. Show the backup's date and size, and ask to type RESTORE.
   b. Take a safety backup of the current data first, then run php artisan down.
   c. Ask for the root password, load the dump into patient_census and unzip the matching picture zip into storage/app/private. If it is missing, warn and continue.
   d. Run migrate --force (in case the backup is older), clear the caches, then php artisan up.
   e. Print record counts (patients, visits, users) and the date of the last encoded visit. Write audit entry system.restored.
   f. Refuse files outside BACKUP_PATH, and names that don't match the backup naming.
5. Scheduler heartbeat: the scheduler saves a "last ran" time every minute. If it is more than 5 minutes old, Admin sees a red bar on every page: "Automatic tasks are not running (no backup, no 8 PM sign-out). Tell IT." with a link to System health.
6. System health page (Admin only, sidebar under ADMINISTRATION, GET /system/health, role:admin + a view-health Gate; encoder, viewer and system_admin get 403). Show each check as OK / Warning / Problem with one plain "What to do" line:
   - database connected;
   - last successful backup (26 h rule) and the picture zip present;
   - backup folder reachable and its free space;
   - server disk free (warning under 10 GB, problem under 2 GB);
   - scheduler heartbeat;
   - APP_ENV=production and APP_DEBUG=false;
   - storage writable;
   - upload limits ≥ 2M;
   - PHP version;
   - system version (from config).
   Never show passwords, the .env contents or connection details. The same checks run in the terminal with php artisan census:health, which exits with code 1 if any check is a Problem.
7. Add any new shortcuts to docs/keyboard-shortcuts.md. Add the new route to the route map in planning/setup-and-structure.md and the new column to planning/database-schema.md.
8. Pest tests (fake mysqldump, MySQL admin calls and the zip, as the existing backup tests do):
   - backup writes both files and fails if the zip fails; cleanup removes old zips too;
   - install: refuses when already installed; --repair changes no data; never seeds dev users; the password appears in no log or file;
   - update: stops when the backup fails;
   - restore: needs RESTORE typed, takes a safety backup first, refuses outside paths and wrong names;
   - heartbeat: red bar for Admin only after 5 minutes;
   - health page: each check's states; 403 for the other roles; census:health exit codes.
9. When I approve, before the commit, update planning/project-history.md (section 0, section 5, decisions log) and planning/deployment-handover.md (sections 3 and 5 now use these commands).
Run the FULL php artisan test (patient_census_test only), npm run types:check and npm run build. Fix errors. Stop and summarize, with steps to test it in the browser and in the terminal. Don't commit until I say okay.
```

## 6i-2 (paste after 6i-1 is merged and pushed)

```
Step 6i-2 – Windows double-click files and Task Scheduler. Create branch step-6i-2-windows-scripts. Put the files in system/scripts/windows/. Each .bat is a thin wrapper: it finds Laragon's php.exe (ask if not found), changes to the system folder and runs the 6i-1 artisan command. All logic stays in the artisan commands. Each file checks it is "Run as administrator" and says so in plain words if not, keeps the window open at the end ("Press any key to close"), and writes to storage/logs/scripts.log.
1. install.bat: runs census:install. Then:
   a. Creates a Task Scheduler task "Census - Scheduler" that runs php artisan schedule:run every minute, whether or not anyone is logged in.
   b. Creates a start-up task so MySQL and Apache start when Windows starts, with no one logged in. First, tell me how you plan to do this with Laragon (Windows services or a start-up task) and wait for my OK before building it.
   c. Adds Windows Firewall rules: allow the web port from the local network only, and block 3306 from outside.
   d. At the end, prints the address office PCs should open (http://<server name or IP>/).
2. update.bat: runs census:update.
3. restore.bat: lists the backups in BACKUP_PATH (newest first, numbered), asks which number, then runs census:restore with that file.
4. health.bat: runs census:health and shows the result. Double-clicking it is the first thing to do when "the system is not working".
5. uninstall-tasks.bat: removes the two scheduled tasks and the firewall rules only (no data is touched).
6. Draft docs/operations-guide.md, section "Start here", in plain language with steps for: first install, daily and weekly checks, update, restore after a broken server, "the system is not working" (run health.bat and follow it), and moving to a new server. Phase 7.5 will finish it.
7. You can't test .bat files with Pest. Give me a step-by-step manual test list for a spare PC or VM: fresh install, run again, update, restore, reboot to check the auto-start, office PC access, firewall.
8. When I approve, before the commit, update planning/project-history.md (section 0, section 5, decisions log) and planning/deployment-handover.md.
Run the FULL php artisan test (patient_census_test only) and npm run build. Stop and summarize. Don't commit until I say okay.
```
