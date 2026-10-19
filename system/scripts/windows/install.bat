@echo off
setlocal EnableDelayedExpansion
rem First install on a server PC. Safe to run again.
rem Thin wrapper: the system itself is set up by php artisan census:install.
rem After that this file makes the Windows parts: MySQL and Apache as services
rem that start with Windows, the site on the web port, the scheduler task and
rem the firewall rules. Right-click, "Run as administrator".
call "%~dp0_common.bat" :start install
if errorlevel 1 (
    set "RC=1"
    goto :done
)
call "%~dp0_common.bat" :names

rem ---- Before anything changes: is this PC ready? (docs\requirements.md) ----------
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0check-requirements.ps1" -Php "%CENSUS_PHP%" -Port %CENSUS_WEB_PORT% -WebService %CENSUS_SVC_WEB% -SystemDir "%CENSUS_DIR%"
if errorlevel 1 (
    echo.
    echo Fix the items marked FIX above ^(docs\requirements.md explains each one^), then run install.bat again.
    call "%~dp0_common.bat" :log "stopped: requirements check found items to fix"
    set "RC=1"
    goto :done
)
call "%~dp0_common.bat" :log "requirements check ok"

rem ---- 0. First install: MySQL must run once for census:install ----------------
sc query %CENSUS_SVC_DB% >nul 2>&1
if errorlevel 1 (
    echo.
    echo  Before you continue: MySQL must be running for the install.
    echo  Open Laragon and click Start All. After this install, MySQL and Apache start
    echo  by themselves as Windows services and you will not use Start All again.
    echo.
    echo  Press any key when MySQL is running...
    pause >nul
)

rem ---- 1. The system itself ------------------------------------------------
set "REPAIR="
findstr /b /c:"APP_KEY=base64:" "%CENSUS_DIR%\.env" >nul 2>&1
if not errorlevel 1 (
    echo.
    echo The system is already installed on this PC.
    echo Running the safe re-check ^(it changes no data and makes no new accounts^).
    set "REPAIR=--repair"
)
echo.
"%CENSUS_PHP%" artisan census:install !REPAIR!
set "RC=!errorlevel!"
call "%~dp0_common.bat" :log "census:install !REPAIR! ended with code !RC!"
if not "!RC!"=="0" (
    echo.
    echo The install stopped. The Windows parts were NOT set up.
    echo Fix what it says above, then run install.bat again.
    goto :done
)

rem ---- 2. MySQL and Apache as Windows services, and the site -------------------
call "%~dp0_services.bat" :install
if not "!SVC_RC!"=="0" (
    echo.
    echo MySQL, Apache or the site is not ready. The next steps were NOT done.
    echo Fix what it says above, then run install.bat again. It is safe to run again.
    set "RC=1"
    goto :done
)

rem ---- 3. Task "Census - Scheduler": php artisan schedule:run every minute ------
echo.
echo Setting up the Windows task "%CENSUS_TASK_SCHEDULER%" ^(every minute, even when nobody is logged in^)...
set "RUNAS=/RU SYSTEM"
set "NEEDACCOUNT="
findstr /b /i /r /c:"BACKUP_PATH=[/\\][/\\]" "%CENSUS_DIR%\.env" >nul 2>&1
if not errorlevel 1 set "NEEDACCOUNT=1"
if defined NEEDACCOUNT (
    echo.
    echo The backups go to another PC ^(a network folder^). The built-in SYSTEM account
    echo cannot sign in to another PC, so the task must run as a Windows account that can
    echo write to that folder. Windows will ask for that account's password.
    echo.
    echo IMPORTANT: use an account whose password never expires. If the password is
    echo changed later, backups stop until you run install.bat again and type the new one.
    echo.
    set "TASKUSER="
    set /p "TASKUSER=Windows account (example SERVER\census, or press Enter to use SYSTEM): "
    if defined TASKUSER set RUNAS=/RU "!TASKUSER!" /RP *
)
schtasks /Create /TN "%CENSUS_TASK_SCHEDULER%" /TR "\"%~dp0run-scheduler.bat\"" /SC MINUTE /MO 1 !RUNAS! /RL HIGHEST /F
if errorlevel 1 (
    echo.
    echo The task could not be created. Read the message above.
    set "RC=1"
    call "%~dp0_common.bat" :log "scheduler task FAILED"
    goto :firewall
)
call "%~dp0_common.bat" :log "scheduler task created"

rem ---- 3b. One real backup, run by that task ---------------------------------------
echo.
echo Running one real backup through the task, to prove it works ^(this can take a minute^)...
set "FLAG=%CENSUS_DIR%\storage\app\backup-test.flag"
set "BRES=%CENSUS_DIR%\storage\logs\backup-test.txt"
set "BCODE=%CENSUS_DIR%\storage\logs\backup-test.code"
del "%BCODE%" "%BRES%" >nul 2>&1
type nul > "%FLAG%"
schtasks /Run /TN "%CENSUS_TASK_SCHEDULER%" >nul
set "WAITED=0"

:waitbackup
if exist "%BCODE%" goto :backup_finished
if !WAITED! GEQ 100 goto :backup_timeout
ping -n 4 127.0.0.1 >nul
set /a WAITED+=1
goto :waitbackup

:backup_timeout
del "%FLAG%" >nul 2>&1
echo.
echo  FAILED: the backup did not finish in 5 minutes. Open the Backups page in the system
echo  and look at the last run, or run health.bat.
set "RC=1"
call "%~dp0_common.bat" :log "backup test timed out"
goto :firewall

:backup_finished
set "BC=1"
set /p BC=<"%BCODE%"
if exist "%BRES%" type "%BRES%"
echo.
if "!BC!"=="0" (
    echo  OK  The task ran a real backup and it worked.
    call "%~dp0_common.bat" :log "backup test through the task: ok"
) else (
    echo  FAILED: the backup did not work when run by the task.
    echo  If the backup folder is on another PC: check the folder is shared, the backup PC is on,
    echo  and the Windows account can write there. Then run install.bat again.
    set "RC=1"
    call "%~dp0_common.bat" :log "backup test through the task: FAILED"
)
del "%BCODE%" "%BRES%" >nul 2>&1

rem ---- 4. Windows Firewall --------------------------------------------------------
:firewall
echo.
echo Setting up the firewall rules...
netsh advfirewall firewall delete rule name="%CENSUS_FW_WEB%" >nul 2>&1
netsh advfirewall firewall delete rule name="%CENSUS_FW_DB%" >nul 2>&1
netsh advfirewall firewall add rule name="%CENSUS_FW_WEB%" dir=in action=allow protocol=TCP localport=%CENSUS_WEB_PORT% remoteip=LocalSubnet profile=any >nul
if errorlevel 1 (
    echo FAILED to add the web rule.
    set "RC=1"
) else (
    echo OK  Port %CENSUS_WEB_PORT% is open to the local network only.
)
netsh advfirewall firewall add rule name="%CENSUS_FW_DB%" dir=in action=block protocol=TCP localport=%CENSUS_DB_PORT% remoteip=any profile=any >nul
if errorlevel 1 (
    echo FAILED to add the MySQL block rule.
    set "RC=1"
) else (
    echo OK  Port %CENSUS_DB_PORT% ^(MySQL^) is blocked from outside. The system on this PC still works.
)
call "%~dp0_common.bat" :log "firewall rules done, code !RC!"

rem ---- 5. The address for the office PCs ---------------------------------------------
set "PORTPART="
if not "%CENSUS_WEB_PORT%"=="80" set "PORTPART=:%CENSUS_WEB_PORT%"
echo.
echo ==========================================================================
echo  Office PCs open this address in their browser:
echo.
echo      http://%COMPUTERNAME%!PORTPART!/
for /f "tokens=2 delims=:" %%A in ('ipconfig ^| findstr /c:"IPv4"') do (
    set "IP=%%A"
    set "IP=!IP: =!"
    echo      http://!IP!!PORTPART!/      ^(use this one if the name does not work^)
)
echo.
echo  Give this PC a fixed IP address so the address never changes.
echo  Never click Start All in Laragon on this server: the system starts by itself.
echo ==========================================================================

:done
call "%~dp0_common.bat" :finish %RC%
exit /b %RC%
