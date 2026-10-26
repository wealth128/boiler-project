# Developer tool (Step 7g, docs/developer-guide.md "Performance"). Never used on the server.
# Starts php with the given arguments, opts that one process out of Windows power throttling
# at once (see unthrottle.ps1), waits for it and prints its output. Example:
#   powershell -File scripts\perf\run-unthrottled.ps1 scripts/perf/export-breakdown.php <system dir> monthly 2026-09
param([Parameter(ValueFromRemainingArguments = $true)][string[]]$PhpArgs)

$out = [IO.Path]::GetTempFileName()
$err = [IO.Path]::GetTempFileName()
$quoted = $PhpArgs | ForEach-Object { if ($_ -match '\s') { '"' + $_ + '"' } else { $_ } }
$p = Start-Process -FilePath php -ArgumentList $quoted -NoNewWindow -PassThru -RedirectStandardOutput $out -RedirectStandardError $err
& "$PSScriptRoot\unthrottle.ps1" -ProcessId $p.Id | Out-Null
$p.WaitForExit()
Get-Content -Raw $out
Remove-Item $out, $err -ErrorAction SilentlyContinue
exit $p.ExitCode
