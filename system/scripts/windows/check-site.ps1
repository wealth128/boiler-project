# Asks the web server for the front page and checks it is the login page. Exit 0 = yes.
param([Parameter(Mandatory = $true)][string]$Url)
$last = ''
for ($i = 0; $i -lt 15; $i++) {
    try {
        $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -MaximumRedirection 5 -TimeoutSec 10
        if ($r.StatusCode -eq 200 -and $r.Content -match '(?i)login') { exit 0 }
        $last = "The page answered, but it is not the login page (code $($r.StatusCode))."
    } catch { $last = $_.Exception.Message }
    Start-Sleep -Seconds 2
}
Write-Host $last
exit 1
