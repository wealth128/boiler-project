@echo off
rem ---------------------------------------------------------------------
rem Shared by the other .bat files in this folder. Do not double-click it.
rem It only holds the small helpers every file needs. All real work is
rem done by the php artisan census:* commands.
rem ---------------------------------------------------------------------
if "%~1"=="" (
    echo This file is used by the other scripts. Do not open it.
    echo Double-click install.bat, update.bat, restore.bat, health.bat or reset-admin-password.bat instead.
    pause >nul
    exit /b 1
)
goto %~1

rem ---- Names used by install.bat and uninstall-tasks.bat (one place only) ----
:names
set "CENSUS_TASK_SCHEDULER=Census - Scheduler"
set "CENSUS_FW_WEB=Census - Web (local network only)"
set "CENSUS_FW_DB=Census - Block MySQL from outside"
set "CENSUS_WEB_PORT=80"
set "CENSUS_DB_PORT=3306"
set "CENSUS_SVC_DB=CensusMySQL"
set "CENSUS_SVC_WEB=CensusApache"
exit /b 0

rem ---- :start <name> [needenv] -----------------------------------------
rem Sets CENSUS_DIR, CENSUS_LOG, CENSUS_PHP, goes to the system folder.
rem Fails (errorlevel 1) with a plain message if not run as administrator,
rem if Laragon's php.exe is not found, or (needenv) if nothing is installed.
:start
set "CENSUS_SCRIPT=%~2"
for %%I in ("%~dp0..\..") do set "CENSUS_DIR=%%~fI"
set "CENSUS_LOG=%CENSUS_DIR%\storage\logs\scripts.log"
if not exist "%CENSUS_DIR%\artisan" (
    echo.
    echo This script is not inside the system folder, so it cannot find the system.
    echo Keep it where it was delivered: system\scripts\windows\
    exit /b 1
)
cd /d "%CENSUS_DIR%"
if not exist "%CENSUS_DIR%\storage\logs" mkdir "%CENSUS_DIR%\storage\logs" >nul 2>&1

net session >nul 2>&1
if errorlevel 1 (
    echo.
    echo  THIS FILE MUST BE RUN AS ADMINISTRATOR.
    echo.
    echo  Close this window. Right-click the file and choose "Run as administrator".
    echo  Then click Yes when Windows asks.
    call "%~f0" :log "stopped: not run as administrator"
    exit /b 1
)

call "%~f0" :log "started"
call :findphp
if errorlevel 1 (
    call "%~f0" :log "stopped: php.exe not found"
    exit /b 1
)
if /i "%~3"=="needenv" (
    if not exist "%CENSUS_DIR%\.env" (
        echo.
        echo The system is not installed on this PC yet ^(there is no .env file^).
        echo Run install.bat first. To put back a backup on a new server, run install.bat, then restore.bat.
        call "%~f0" :log "stopped: no .env, system not installed"
        exit /b 1
    )
)
exit /b 0

rem ---- :findphp : Laragon's newest php.exe, remembered in php-path.txt ----
:findphp
set "CENSUS_PHP="
if exist "%~dp0php-path.txt" set /p CENSUS_PHP=<"%~dp0php-path.txt"
if defined CENSUS_PHP if not exist "%CENSUS_PHP%" set "CENSUS_PHP="
if defined CENSUS_PHP exit /b 0

for %%R in (C D E) do (
    for /f "delims=" %%P in ('dir /b /ad /o-n "%%R:\laragon\bin\php\php-*" 2^>nul') do (
        if not defined CENSUS_PHP if exist "%%R:\laragon\bin\php\%%P\php.exe" set "CENSUS_PHP=%%R:\laragon\bin\php\%%P\php.exe"
    )
)
if defined CENSUS_PHP goto :phpfound

echo.
echo Laragon's php.exe was not found in the usual place ^(C:\laragon\bin\php\^).
echo Open the folder where Laragon is installed, then bin, then php, then the php-8.3 folder.
echo Copy the full path of php.exe and paste it here.
:askphp
set "CENSUS_PHP="
set /p "CENSUS_PHP=Full path to php.exe (or press Enter to stop): "
if not defined CENSUS_PHP exit /b 1
set "CENSUS_PHP=%CENSUS_PHP:"=%"
if not exist "%CENSUS_PHP%" (
    echo That file does not exist. Try again.
    goto :askphp
)
:phpfound
>"%~dp0php-path.txt" echo %CENSUS_PHP%
call "%~f0" :log "php found: %CENSUS_PHP%"
exit /b 0

rem ---- :log "text" : one line in storage\logs\scripts.log. Never put secrets here. ----
:log
>>"%CENSUS_LOG%" echo %date% %time% [%CENSUS_SCRIPT%] %~2
exit /b 0

rem ---- :finish <exit code> : say how it went, keep the window open ----
:finish
call "%~f0" :log "finished with code %~2"
echo.
if "%~2"=="0" (
    echo Finished.
) else (
    echo Something needs attention ^(code %~2^). Read the messages above.
    echo Details are saved in storage\logs\scripts.log
)
echo.
echo Press any key to close...
pause >nul
exit /b 0
