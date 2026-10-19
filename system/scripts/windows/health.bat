@echo off
setlocal
rem Checks that the system is healthy. First thing to run when the system is not working.
rem Thin wrapper: all logic is in php artisan census:health. Right-click, "Run as administrator".
call "%~dp0_common.bat" :start health needenv
if errorlevel 1 (
    set "RC=1"
    goto :done
)
echo.
"%CENSUS_PHP%" artisan census:health
set "RC=%errorlevel%"
call "%~dp0_common.bat" :log "census:health ended with code %RC%"
:done
call "%~dp0_common.bat" :finish %RC%
exit /b %RC%
