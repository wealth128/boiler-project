@echo off
setlocal EnableDelayedExpansion
rem Shows the backups in BACKUP_PATH (newest first), asks which one, then runs census:restore.
rem Thin wrapper: all logic is in php artisan census:restore. Run as administrator.
call "%~dp0_common.bat" :start restore needenv
if errorlevel 1 (
    set "RC=1"
    goto :done
)

set "BP="
for /f "tokens=1,* delims==" %%A in ('findstr /b /c:"BACKUP_PATH=" "%CENSUS_DIR%\.env"') do set "BP=%%B"
if defined BP set "BP=!BP:"=!"
if not defined BP (
    echo.
    echo BACKUP_PATH is empty in the .env file, so there is no backup folder to look in.
    set "RC=1"
    goto :done
)
set "BP=!BP:/=\!"
if not exist "!BP!\" (
    echo.
    echo The backup folder cannot be reached: !BP!
    echo Check that the backup PC is on and the shared folder is available, then try again.
    set "RC=1"
    goto :done
)

echo.
echo Backups in !BP!  ^(newest first^)
echo.
set "N=0"
for /f "delims=" %%F in ('dir /b /o-n "!BP!\patient_census_????-??-??_??????.sql" 2^>nul') do (
    set /a N+=1
    set "B!N!=%%F"
    for %%G in ("!BP!\%%F") do echo   !N!.  %%F   ^(%%~zG bytes^)
)
if !N! EQU 0 (
    echo There are no backup files in that folder.
    set "RC=1"
    goto :done
)

echo.
echo Anything encoded after the backup was taken will be gone.
echo.
set "PICK="
set /p "PICK=Type the number of the backup to restore (or press Enter to cancel): "
if not defined PICK (
    echo Cancelled. Nothing was changed.
    set "RC=0"
    goto :done
)
for /f "delims=0123456789" %%X in ("!PICK!") do (
    echo "!PICK!" is not a number. Nothing was changed.
    set "RC=1"
    goto :done
)
if !PICK! LSS 1 (
    echo There is no backup number !PICK!. Nothing was changed.
    set "RC=1"
    goto :done
)
if !PICK! GTR !N! (
    echo There is no backup number !PICK!. Nothing was changed.
    set "RC=1"
    goto :done
)

set "FILE=!B%PICK%!"
if not defined FILE (
    echo There is no backup number !PICK!. Nothing was changed.
    set "RC=1"
    goto :done
)
echo.
echo Restoring !FILE!
echo.
"%CENSUS_PHP%" artisan census:restore "!FILE!"
set "RC=!errorlevel!"
call "%~dp0_common.bat" :log "census:restore ended with code !RC!"

:done
call "%~dp0_common.bat" :finish %RC%
exit /b %RC%
