@echo off
rem ---------------------------------------------------------------------
rem Used by install.bat and uninstall-tasks.bat. Do not double-click it.
rem Makes MySQL and Apache (from Laragon) start by themselves with Windows,
rem as Windows services, and serves system\public on the web port.
rem Needs CENSUS_DIR, CENSUS_PHP, CENSUS_WEB_PORT and the CENSUS_SVC_* names
rem (call _common.bat :start and :names first). Sets SVC_RC: 0 = all good.
rem ---------------------------------------------------------------------
if "%~1"=="" (
    echo This file is used by install.bat. Do not open it.
    pause >nul
    exit /b 1
)
goto %~1

rem =====================================================================
rem :install
rem =====================================================================
:install
setlocal EnableDelayedExpansion
set "SVC_RC=0"
set "COMMON=%~dp0_common.bat"
set "HERE=%~dp0"

rem ---- 1. Find Laragon's files (stop with a plain message if not there) ----
for %%I in ("%CENSUS_PHP%\..\..\..\..") do set "LAR=%%~fI"
:checklar
if exist "!LAR!\bin\mysql\" if exist "!LAR!\bin\apache\" goto :laragon_found
echo.
echo Laragon was not found in: !LAR!
set "LAR="
set /p "LAR=Type the Laragon folder (example C:\laragon), or press Enter to stop: "
if not defined LAR (
    set "SVC_RC=1"
    goto :install_end
)
set "LAR=!LAR:"=!"
goto :checklar

:laragon_found
set "MYSQLD="
set "MYSQLDIR="
for /f "delims=" %%D in ('dir /b /ad /o-n "!LAR!\bin\mysql\mysql-*" 2^>nul') do (
    if not defined MYSQLD if exist "!LAR!\bin\mysql\%%D\bin\mysqld.exe" (
        set "MYSQLDIR=!LAR!\bin\mysql\%%D"
        set "MYSQLD=!LAR!\bin\mysql\%%D\bin\mysqld.exe"
    )
)
set "HTTPD="
set "APACHEDIR="
for /f "delims=" %%D in ('dir /b /ad /o-n "!LAR!\bin\apache\httpd-*" 2^>nul') do (
    if not defined HTTPD if exist "!LAR!\bin\apache\%%D\bin\httpd.exe" (
        set "APACHEDIR=!LAR!\bin\apache\%%D"
        set "HTTPD=!LAR!\bin\apache\%%D\bin\httpd.exe"
    )
)
set "MYINI=!MYSQLDIR!\my.ini"
set "HTTPDCONF=!APACHEDIR!\conf\httpd.conf"
set "SITESDIR=!LAR!\etc\apache2\sites-enabled"

if not defined MYSQLD (
    set "WHAT=MySQL, mysqld.exe, in !LAR!\bin\mysql"
    goto :laragon_missing
)
if not defined HTTPD (
    set "WHAT=Apache, httpd.exe, in !LAR!\bin\apache"
    goto :laragon_missing
)
if not exist "!MYINI!" (
    set "WHAT=MySQL's settings file !MYINI!"
    goto :laragon_missing
)
if not exist "!HTTPDCONF!" (
    set "WHAT=Apache's settings file !HTTPDCONF!"
    goto :laragon_missing
)
if not exist "!LAR!\etc\apache2\" (
    set "WHAT=the folder !LAR!\etc\apache2"
    goto :laragon_missing
)
findstr /i /c:"sites-enabled" "!HTTPDCONF!" >nul 2>&1
if errorlevel 1 (
    set "WHAT=the line in !HTTPDCONF! that loads the sites-enabled folder"
    goto :laragon_missing
)
findstr /i /r /b /c:"Listen.*%CENSUS_WEB_PORT%" "!HTTPDCONF!" >nul 2>&1
if errorlevel 1 (
    echo.
    echo  Apache is not set to listen on port %CENSUS_WEB_PORT%.
    echo  In Laragon: Menu, Apache, httpd.conf. Make sure it has a line "Listen %CENSUS_WEB_PORT%". Save, then run install.bat again.
    set "SVC_RC=1"
    goto :install_end
)
if not exist "!SITESDIR!\" mkdir "!SITESDIR!" >nul 2>&1
call "!COMMON!" :log "laragon found: !LAR!"
goto :laragon_ok

:laragon_missing
echo.
echo  A file that Laragon makes was not found: !WHAT!
echo.
echo  Open Laragon once, click Start All, then Stop All, close it, and run install.bat again.
echo  (Laragon makes its settings files the first time it starts.)
call "!COMMON!" :log "stopped: laragon file missing"
set "SVC_RC=1"
goto :install_end

:laragon_ok
rem Warn if Apache's PHP is another version than the one this install uses.
for %%I in ("%CENSUS_PHP%\..") do set "PHPDIRNAME=%%~nxI"
if exist "!LAR!\etc\apache2\mod_php.conf" (
    findstr /i /c:"!PHPDIRNAME!" "!LAR!\etc\apache2\mod_php.conf" >nul 2>&1
    if errorlevel 1 (
        echo.
        echo  WARNING: Apache may use a different PHP version than !PHPDIRNAME!.
        echo  In Laragon: Menu, PHP, choose !PHPDIRNAME!. Then run install.bat again.
    )
)

rem ---- 2. MySQL must listen on this PC only (keeps a .bak copy of my.ini) ----
echo.
echo Checking that MySQL listens on 127.0.0.1 only...
powershell -NoProfile -ExecutionPolicy Bypass -File "!HERE!set-bind-address.ps1" -Path "!MYINI!"
set "BIND=!errorlevel!"
if "!BIND!"=="1" (
    echo  FAILED to read or change my.ini.
    set "SVC_RC=1"
    goto :install_end
)
if "!BIND!"=="2" (
    echo  bind-address=127.0.0.1 was added to my.ini.
    call "!COMMON!" :log "my.ini: bind-address set to 127.0.0.1"
) else (
    echo  OK  bind-address=127.0.0.1
)

rem ---- 3. Laragon must be closed before the services take over ----
sc query %CENSUS_SVC_DB% >nul 2>&1
set "HAVE_DB=!errorlevel!"
sc query %CENSUS_SVC_WEB% >nul 2>&1
set "HAVE_WEB=!errorlevel!"
set "TRIES=0"
if "!HAVE_DB!!HAVE_WEB!"=="00" goto :closed_ok

:waitclosed
set "BUSY="
tasklist /FI "IMAGENAME eq laragon.exe" 2>nul | find /i "laragon.exe" >nul && set "BUSY=Laragon"
if not "!HAVE_DB!"=="0" tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | find /i "mysqld.exe" >nul && set "BUSY=MySQL"
if not "!HAVE_WEB!"=="0" tasklist /FI "IMAGENAME eq httpd.exe" 2>nul | find /i "httpd.exe" >nul && set "BUSY=Apache"
if not defined BUSY goto :closed_ok
set /a TRIES+=1
echo.
echo  !BUSY! is still running. In Laragon click Stop All, then close Laragon ^(Exit^).
if !TRIES! GEQ 4 (
    echo  It is still running. Close it, then run install.bat again.
    set "SVC_RC=1"
    goto :install_end
)
echo  Press any key when it is closed...
pause >nul
goto :waitclosed

:closed_ok

rem ---- 4. The Apache site file: serve system\public on the web port ----
echo.
echo Writing the Apache site file...
powershell -NoProfile -ExecutionPolicy Bypass -File "!HERE!write-vhost.ps1" -Path "!SITESDIR!\00-census.conf" -Root "%CENSUS_DIR%" -Port "%CENSUS_WEB_PORT%"
if errorlevel 1 (
    echo  FAILED to write !SITESDIR!\00-census.conf
    set "SVC_RC=1"
    goto :install_end
)
"!HTTPD!" -t -f "!HTTPDCONF!" >nul 2>&1
if errorlevel 1 (
    echo  FAILED: Apache does not accept its settings. The new site file was removed.
    echo  To see the reason, open a Command Prompt and run:
    echo      "!HTTPD!" -t -f "!HTTPDCONF!"
    del "!SITESDIR!\00-census.conf" >nul 2>&1
    if exist "!SITESDIR!\00-census.conf.bak" move /y "!SITESDIR!\00-census.conf.bak" "!SITESDIR!\00-census.conf" >nul
    set "SVC_RC=1"
    goto :install_end
)
echo  OK  !SITESDIR!\00-census.conf

rem ---- 5. Register the two services (only if not there yet) ----
echo.
echo Setting up the Windows services...
if not "!HAVE_DB!"=="0" (
    "!MYSQLD!" --validate-config --defaults-file="!MYINI!" >nul 2>&1
    if errorlevel 1 (
        echo  FAILED: MySQL does not accept its settings file ^(!MYINI!^).
        echo  Open a Command Prompt and run this to see why:
        echo      "!MYSQLD!" --validate-config --defaults-file="!MYINI!"
        set "SVC_RC=1"
        goto :install_end
    )
    "!MYSQLD!" --install %CENSUS_SVC_DB% --defaults-file="!MYINI!"
    if errorlevel 1 (
        echo  FAILED to create the service %CENSUS_SVC_DB%.
        set "SVC_RC=1"
        goto :install_end
    )
)
if not "!HAVE_WEB!"=="0" (
    "!HTTPD!" -k install -n %CENSUS_SVC_WEB% -f "!HTTPDCONF!"
    if errorlevel 1 (
        echo  FAILED to create the service %CENSUS_SVC_WEB%.
        set "SVC_RC=1"
        goto :install_end
    )
)

rem Automatic (delayed) start, and restart by itself after a crash (3 tries, 1 minute apart).
for %%S in (%CENSUS_SVC_DB% %CENSUS_SVC_WEB%) do (
    sc config %%S start= delayed-auto >nul
    sc failure %%S reset= 86400 actions= restart/60000/restart/60000/restart/60000 >nul
    sc failureflag %%S 1 >nul
)
sc description %CENSUS_SVC_DB% "Census system: database (MySQL from Laragon). Do not use Start All in Laragon." >nul
sc description %CENSUS_SVC_WEB% "Census system: web server (Apache from Laragon). Do not use Start All in Laragon." >nul
echo  OK  %CENSUS_SVC_DB% and %CENSUS_SVC_WEB%: start with Windows, restart after a crash
call "!COMMON!" :log "services registered and configured"

rem ---- 6. Start them now (MySQL first) ----
for %%S in (%CENSUS_SVC_DB% %CENSUS_SVC_WEB%) do (
    sc query %%S | find "RUNNING" >nul
    if errorlevel 1 (
        net start %%S >nul
        if errorlevel 1 (
            echo  FAILED to start %%S.
            if "%%S"=="%CENSUS_SVC_WEB%" echo  Another program may be using port %CENSUS_WEB_PORT% ^(IIS, Skype, another web server^).
            echo  Details: Windows Event Viewer, Windows Logs, Application.
            set "SVC_RC=1"
        ) else (
            echo  OK  %%S started
        )
    ) else (
        echo  OK  %%S is running
        if "!BIND!"=="2" if "%%S"=="%CENSUS_SVC_DB%" (
            net stop %%S >nul
            net start %%S >nul
            echo  OK  %%S restarted for the new setting
        )
    )
)
call "!COMMON!" :log "services start done, code !SVC_RC!"

rem ---- 7. Laragon's own auto-start off ----
powershell -NoProfile -ExecutionPolicy Bypass -File "!HERE!laragon-autostart-off.ps1" -Ini "!LAR!\usr\laragon.ini"

rem ---- 8. Does the site answer? ----
echo.
echo Checking that http://localhost:%CENSUS_WEB_PORT%/ shows the login page...
powershell -NoProfile -ExecutionPolicy Bypass -File "!HERE!check-site.ps1" -Url "http://localhost:%CENSUS_WEB_PORT%/"
if errorlevel 1 (
    echo  FAILED: the web page did not show the login page.
    echo  Look in Apache's error log: !APACHEDIR!\logs\error.log
    echo  Then run install.bat again.
    call "!COMMON!" :log "site check FAILED"
    set "SVC_RC=1"
) else (
    echo  OK  http://localhost:%CENSUS_WEB_PORT%/ shows the login page
    call "!COMMON!" :log "site check ok"
)

echo.
echo  ----------------------------------------------------------------------
echo   NEVER click "Start All" in Laragon on this server.
echo   MySQL and Apache start by themselves when Windows starts.
echo   To check them, double-click health.bat.
echo  ----------------------------------------------------------------------

:install_end
endlocal & set "SVC_RC=%SVC_RC%"
exit /b 0

rem =====================================================================
rem :remove  - stops and removes the two services. Data is not touched.
rem =====================================================================
:remove
setlocal
set "SVC_RC=0"
for %%S in (%CENSUS_SVC_WEB% %CENSUS_SVC_DB%) do (
    sc query %%S >nul 2>&1
    if errorlevel 1 (
        echo Service %%S was not there.
    ) else (
        net stop %%S >nul 2>&1
        sc delete %%S >nul
        if errorlevel 1 (
            echo FAILED to remove service %%S.
            set "SVC_RC=1"
        ) else (
            echo Removed service %%S.
        )
    )
)
endlocal & set "SVC_RC=%SVC_RC%"
exit /b 0
