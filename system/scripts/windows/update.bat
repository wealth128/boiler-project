@echo off
setlocal
rem Updates the system after new files were copied over the old ones.
rem Thin wrapper: all logic is in php artisan census:update. Right-click, "Run as administrator".
call "%~dp0_common.bat" :start update needenv
if errorlevel 1 (
    set "RC=1"
    goto :done
)
echo.
"%CENSUS_PHP%" artisan census:update
set "RC=%errorlevel%"
call "%~dp0_common.bat" :log "census:update ended with code %RC%"
:done
call "%~dp0_common.bat" :finish %RC%
exit /b %RC%
