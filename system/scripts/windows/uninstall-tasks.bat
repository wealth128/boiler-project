@echo off
setlocal
rem Removes what install.bat made in Windows: the scheduled task, the MySQL and Apache
rem services and the firewall rules. It touches NO data: no database, no backups,
rem no files of the system. Run as administrator.
call "%~dp0_common.bat" :start uninstall-tasks
if errorlevel 1 (
    set "RC=1"
    goto :done
)
call "%~dp0_common.bat" :names

echo.
echo This removes:
echo   - the Windows task "%CENSUS_TASK_SCHEDULER%"
echo   - the Windows services %CENSUS_SVC_DB% and %CENSUS_SVC_WEB%
echo   - the firewall rules "%CENSUS_FW_WEB%" and "%CENSUS_FW_DB%"
echo Your data, database and backups are not touched.
echo After this the system does NOT work: there is no database service, no web server,
echo no automatic backup and no 8 PM sign-out. Run install.bat to put them back.
echo.
set "OK="
set /p "OK=Type YES to continue: "
if /i not "%OK%"=="YES" (
    echo Cancelled. Nothing was removed.
    set "RC=0"
    goto :done
)

set "RC=0"
schtasks /Query /TN "%CENSUS_TASK_SCHEDULER%" >nul 2>&1
if not errorlevel 1 (
    schtasks /Delete /TN "%CENSUS_TASK_SCHEDULER%" /F
    if errorlevel 1 set "RC=1"
) else (
    echo Task "%CENSUS_TASK_SCHEDULER%" was not there.
)
call "%~dp0_services.bat" :remove
if not "%SVC_RC%"=="0" set "RC=1"
call :fw "%CENSUS_FW_WEB%"
call :fw "%CENSUS_FW_DB%"
echo.
echo The Apache site file and the my.ini copy were left in place. They do no harm.
call "%~dp0_common.bat" :log "tasks, services and firewall rules removed, code %RC%"
goto :done

:fw
netsh advfirewall firewall show rule name="%~1" >nul 2>&1
if errorlevel 1 (
    echo Firewall rule "%~1" was not there.
    exit /b 0
)
netsh advfirewall firewall delete rule name="%~1" >nul
if errorlevel 1 (set "RC=1") else (echo Removed firewall rule "%~1".)
exit /b 0

:done
call "%~dp0_common.bat" :finish %RC%
exit /b %RC%
