# Makes sure MySQL listens on 127.0.0.1 only (this PC). Called by install.bat.
# Keeps a copy of my.ini first. Exit code: 0 = already right, 2 = changed, 1 = error.
param([Parameter(Mandatory = $true)][string]$Path)
try {
    $lines = [System.Collections.Generic.List[string]]([IO.File]::ReadAllLines($Path))
    $wanted = 'bind-address=127.0.0.1'
    $idx = -1; $section = -1
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match '^\s*\[mysqld\]\s*$') { $section = $i }
        if ($lines[$i] -match '^\s*bind-address\s*=') { $idx = $i }
    }
    if ($idx -ge 0 -and $lines[$idx] -match '^\s*bind-address\s*=\s*127\.0\.0\.1\s*$') { exit 0 }

    $bak = "$Path.bak"
    if (Test-Path $bak) { $bak = "$Path.bak-" + (Get-Date -Format 'yyyyMMdd-HHmmss') }
    Copy-Item -LiteralPath $Path -Destination $bak
    Write-Host "A copy of my.ini was saved as $bak"

    if ($idx -ge 0) { $lines[$idx] = $wanted }
    elseif ($section -ge 0) { $lines.Insert($section + 1, $wanted) }
    else { $lines.Add('[mysqld]'); $lines.Add($wanted) }
    [IO.File]::WriteAllLines($Path, $lines, (New-Object Text.UTF8Encoding $false))
    exit 2
} catch { Write-Host $_.Exception.Message; exit 1 }
