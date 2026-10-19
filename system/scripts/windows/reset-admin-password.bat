@echo off
setlocal
rem Gives the Admin a new temporary password. For a forgotten password.
rem Thin wrapper: all logic is in php artisan census:reset-admin-password.
rem Run it on the SERVER PC itself, right-click, "Run as administrator".
call "%~dp0_common.bat" :start reset-admin-password needenv
if errorlevel 1 (
    set "RC=1"
    goto :done
)
echo.
echo This sets a new TEMPORARY password for the Admin account.
echo Run this on the server PC itself. Nobody else should be watching the screen.
echo.
"%CENSUS_PHP%" artisan census:reset-admin-password
set "RC=%errorlevel%"
rem The password itself is never written to the log.
call "%~dp0_common.bat" :log "census:reset-admin-password ended with code %RC%"
:done
call "%~dp0_common.bat" :finish %RC%
exit /b %RC%
