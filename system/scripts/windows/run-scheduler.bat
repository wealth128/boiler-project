@echo off
rem Run by the Windows task "Census - Scheduler" every minute. Not for double-clicking.
rem Runs php artisan schedule:run (daily backup, 8 PM sign-out, heartbeat).
rem If install.bat left the file storage\app\backup-test.flag, it runs ONE real backup
rem instead, so install.bat can prove the task (and its account) can make backups.
set "PHP="
if exist "%~dp0php-path.txt" set /p PHP=<"%~dp0php-path.txt"
if not defined PHP exit /b 1
cd /d "%~dp0..\.." || exit /b 1
if not exist "storage\app\backup-test.flag" goto :normal
del "storage\app\backup-test.flag"
"%PHP%" artisan backup:run > "storage\logs\backup-test.txt" 2>&1
set "CODE=%errorlevel%"
>"storage\logs\backup-test.code" echo %CODE%
exit /b 0

:normal
"%PHP%" artisan schedule:run >nul 2>&1
exit /b %errorlevel%
