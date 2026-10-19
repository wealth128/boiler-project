# Writes the Apache site file so the web port serves system\public. Called by install.bat.
# It is named 00-census.conf so it loads first and answers for any address (name or IP).
param(
    [Parameter(Mandatory = $true)][string]$Path,
    [Parameter(Mandatory = $true)][string]$Root,
    [Parameter(Mandatory = $true)][string]$Port
)
try {
    $public = $Root.Replace('\', '/').TrimEnd('/') + '/public'
    $text = @"
# Written by the Census install.bat. Safe to overwrite: run install.bat again.
<VirtualHost *:$Port>
    ServerName census.local
    ServerAlias *
    DocumentRoot "$public"
    <Directory "$public">
        AllowOverride All
        Options -Indexes +FollowSymLinks
        Require all granted
    </Directory>
</VirtualHost>
"@
    if (Test-Path $Path) { Copy-Item -LiteralPath $Path -Destination "$Path.bak" -Force }
    [IO.File]::WriteAllText($Path, $text, (New-Object Text.UTF8Encoding $false))
    exit 0
} catch { Write-Host $_.Exception.Message; exit 1 }
