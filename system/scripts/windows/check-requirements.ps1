# Checks this PC against docs\requirements.md (part 1) BEFORE install.bat changes anything.
# Each line is OK or FIX, with one plain line on what to install or do. Exit code 1 if any FIX.
# PHP extensions, folders and upload limits are checked next by php artisan census:install.
param(
    [Parameter(Mandatory = $true)][string]$Php,
    [string]$Port = '80',
    [string]$WebService = 'CensusApache',
    [string]$SystemDir = $PSScriptRoot
)

$script:fixes = 0
function Show-Ok([string]$text) { Write-Host " OK   $text" }
function Show-Fix([string]$text, [string]$todo) {
    Write-Host " FIX  $text"
    Write-Host "      What to do: $todo"
    $script:fixes++
}
function Show-Note([string]$text) { Write-Host " NOTE $text" }

Write-Host 'Checking this PC against docs\requirements.md ...'
Write-Host ''

# 1. Windows 10 or 11, 64-bit
try {
    $os = [Environment]::OSVersion.Version
    if ([Environment]::Is64BitOperatingSystem -and $os.Major -ge 10) { Show-Ok 'Windows 10 or 11, 64-bit' }
    else { Show-Fix 'This is not 64-bit Windows 10 or 11.' 'Use a PC with 64-bit Windows 10 or 11 as the server.' }
} catch { Show-Note "Could not check the Windows version: $($_.Exception.Message)" }

# 2. Memory (4 GB minimum; Windows reports a little less than the label)
try {
    $ram = (Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB
    if ($ram -ge 3.5) { Show-Ok ('Memory: {0:N1} GB (minimum 4 GB)' -f $ram) }
    else { Show-Fix ('Memory is only {0:N1} GB. The minimum is 4 GB.' -f $ram) 'Add memory, or use another PC as the server.' }
} catch { Show-Note "Could not check the memory: $($_.Exception.Message)" }

# 3. Free disk space where the system is (10 GB minimum, the same line System health uses)
try {
    $drive = (Get-Item -LiteralPath $SystemDir).PSDrive
    $freeGb = $drive.Free / 1GB
    if ($freeGb -ge 10) { Show-Ok ('Free disk space on {0}: {1:N0} GB (minimum 10 GB)' -f $drive.Name, $freeGb) }
    else { Show-Fix ('Only {0:N1} GB free on drive {1}. The minimum is 10 GB.' -f $freeGb, $drive.Name) 'Free some space on that drive, or move the system folder to a bigger drive.' }
} catch { Show-Note "Could not check the disk space: $($_.Exception.Message)" }

# 4. Microsoft Visual C++ Redistributable 2015-2022 (x64): PHP, Apache and MySQL need it
$vcOk = $false
try {
    $vc = Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64' -Name Installed -ErrorAction Stop
    $vcOk = ($vc.Installed -eq 1)
} catch { $vcOk = $false }
if ($vcOk) { Show-Ok 'Microsoft Visual C++ Redistributable (x64) is installed' }
else { Show-Fix 'Microsoft Visual C++ Redistributable 2015-2022 (x64) is not installed.' 'Install vc_redist.x64.exe from the handover USB (double-click, Yes, Install), restart the PC, then run install.bat again.' }

# 5. Laragon with MySQL 8 and Apache (Laragon Full)
$lar = $null
try { $lar = Split-Path (Split-Path (Split-Path (Split-Path $Php))) } catch { }
$mysqlDirs = @()
$apacheDirs = @()
if ($lar -and (Test-Path -LiteralPath (Join-Path $lar 'bin'))) {
    $mysqlDirs = @(Get-ChildItem -LiteralPath (Join-Path $lar 'bin\mysql') -Directory -Filter 'mysql-*' -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '^mysql-(\d+)\.' -and [int]$Matches[1] -ge 8 })
    $apacheDirs = @(Get-ChildItem -LiteralPath (Join-Path $lar 'bin\apache') -Directory -Filter 'httpd-*' -ErrorAction SilentlyContinue)
    Show-Ok "Laragon found: $lar"
} else {
    Show-Fix 'Laragon was not found next to this php.exe.' 'Install Laragon Full from the handover USB (see docs\requirements.md), then run install.bat again.'
}
if ($lar -and (Test-Path -LiteralPath (Join-Path $lar 'bin'))) {
    if ($mysqlDirs.Count -gt 0) { Show-Ok "MySQL 8 found: $($mysqlDirs[0].Name)" }
    else { Show-Fix 'MySQL 8 was not found in Laragon.' 'Use Laragon Full, which includes MySQL 8. The Lite or portable editions may not. See docs\requirements.md.' }
    if ($apacheDirs.Count -gt 0) { Show-Ok "Apache found: $($apacheDirs[0].Name)" }
    else { Show-Fix 'Apache was not found in Laragon.' 'Use Laragon Full, which includes Apache. See docs\requirements.md.' }
}

# 6. PHP starts, is 8.4.1 or newer (the locked packages need it), and is the Thread Safe build (Apache needs it)
$ver = $null
try {
    $ver = (& $Php -r 'echo PHP_VERSION;' 2>$null)
    $code = $LASTEXITCODE
} catch { $code = 1 }
if (-not $ver -or $code -ne 0) {
    if ($vcOk) { Show-Fix "php.exe does not start ($Php)." 'Choose another PHP in Laragon (Menu, PHP, Version), or add a newer PHP as in docs\requirements.md.' }
    else { Show-Note 'php.exe does not start. This is usually because of the missing Visual C++ Redistributable above.' }
} else {
    if ([version]($ver -replace '[^0-9.].*$', '') -ge [version]'8.4.1') { Show-Ok "PHP $ver" }
    else { Show-Fix "PHP is $ver. PHP 8.4.1 or newer is needed." 'Add a newer PHP to Laragon as in docs\requirements.md ("Adding PHP to Laragon"), choose it in Laragon (Menu, PHP, Version), delete php-path.txt next to this file, then run install.bat again.' }
    $zts = (& $Php -r 'echo PHP_ZTS;' 2>$null)
    if ($zts -eq '1') { Show-Ok 'PHP is the Thread Safe build (needed by Apache)' }
    else { Show-Fix 'This PHP is the Non Thread Safe build. Apache cannot use it.' 'Download the Thread Safe x64 zip of PHP 8.4 or newer (see docs\requirements.md, "Adding PHP to Laragon").' }
}

# 7. The web port is free (or already ours)
try {
    $svc = Get-Service -Name $WebService -ErrorAction SilentlyContinue
    if ($svc) { Show-Ok "The web server service $WebService is already installed" }
    else {
        $listen = @(Get-NetTCPConnection -State Listen -LocalPort ([int]$Port) -ErrorAction SilentlyContinue)
        if ($listen.Count -eq 0) { Show-Ok "Port $Port is free" }
        else {
            $name = (Get-Process -Id $listen[0].OwningProcess -ErrorAction SilentlyContinue).ProcessName
            if ($name -eq 'httpd') { Show-Note "Laragon's Apache is running on port $Port. You will be asked to close Laragon later in the install." }
            else { Show-Fix "Port $Port is used by another program ($name)." "Stop that program. Common ones: IIS (service World Wide Web Publishing Service), Skype, another web server. Then run install.bat again." }
        }
    }
} catch { Show-Note "Could not check port ${Port}: $($_.Exception.Message)" }

Write-Host ''
if ($script:fixes -gt 0) {
    Write-Host "$($script:fixes) item(s) need attention. Nothing was changed on this PC."
    exit 1
}
Write-Host 'This PC has everything the install needs.'
exit 0
