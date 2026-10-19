# Turns off Laragon's own start-up options, so only the Windows services start the system.
# It only changes settings that already exist in laragon.ini, after keeping a copy.
param([Parameter(Mandatory = $true)][string]$Ini)
try {
    if (Test-Path $Ini) {
        $lines = [IO.File]::ReadAllLines($Ini)
        $changed = $false
        for ($i = 0; $i -lt $lines.Count; $i++) {
            if ($lines[$i] -match '(?i)^\s*(AutoStart|AutoRun|RunOnStartup|StartAll|StartWithWindows|RunAtStartup)\s*=\s*(1|true|yes|on)\s*$') {
                $lines[$i] = ($lines[$i] -replace '=.*$', '=0'); $changed = $true
            }
        }
        if ($changed) {
            Copy-Item -LiteralPath $Ini -Destination "$Ini.bak" -Force
            [IO.File]::WriteAllLines($Ini, $lines, (New-Object Text.UTF8Encoding $false))
            Write-Host "Laragon's start-up options were turned off in laragon.ini."
        }
    }
    foreach ($key in 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run', 'HKLM:\Software\Microsoft\Windows\CurrentVersion\Run') {
        if (Get-ItemProperty -Path $key -Name 'Laragon' -ErrorAction SilentlyContinue) {
            Remove-ItemProperty -Path $key -Name 'Laragon' -ErrorAction SilentlyContinue
            Write-Host "Laragon was removed from the Windows start-up list."
        }
    }
    foreach ($dir in [Environment]::GetFolderPath('Startup'), [Environment]::GetFolderPath('CommonStartup')) {
        foreach ($f in Get-ChildItem -Path $dir -Filter 'Laragon*.lnk' -ErrorAction SilentlyContinue) {
            Remove-Item -LiteralPath $f.FullName -Force
            Write-Host "Laragon's start-up shortcut was removed."
        }
    }
    exit 0
} catch { Write-Host $_.Exception.Message; exit 1 }
