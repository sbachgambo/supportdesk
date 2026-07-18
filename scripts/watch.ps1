# ─────────────────────────────────────────────────────────────
# P3A — concurrent test watcher.
# Watches app/, bin/, tests/, public/, database/ and re-runs the fast static
# suite + the current phase test on every save. Keep this running in a terminal
# while development happens; watch it stay green.
#
#   Usage:  powershell -ExecutionPolicy Bypass -File scripts/watch.ps1
#           powershell -ExecutionPolicy Bypass -File scripts/watch.ps1 -Phase 2
#
# -Phase N   also runs tests/phases/PhaseNTest.php after the static suite.
#            Defaults to the highest-numbered phase test that exists.
# ─────────────────────────────────────────────────────────────
param(
    [int]$Phase = 0
)

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

# Default -Phase to the highest-numbered phase test that exists.
if ($Phase -eq 0) {
    $files = Get-ChildItem -Path "$root\tests\phases" -Filter "Phase*Test.php" -ErrorAction SilentlyContinue
    foreach ($f in $files) {
        if ($f.Name -match 'Phase(\d+)Test\.php') { $n = [int]$Matches[1]; if ($n -gt $Phase) { $Phase = $n } }
    }
}

# Run the static suite, then (optionally) the current phase test.
function Invoke-Suite {
    param([int]$Ph)
    Clear-Host
    Write-Host "P3A watcher — $(Get-Date -Format 'HH:mm:ss')  (phase $Ph)" -ForegroundColor Cyan
    Write-Host ("─" * 50)
    & php "$root\tests\StaticSuite.php"
    $staticCode = $LASTEXITCODE
    $phaseCode = 0
    $phaseFile = "$root\tests\phases\Phase${Ph}Test.php"
    if ($Ph -gt 0 -and (Test-Path $phaseFile)) {
        & php $phaseFile
        $phaseCode = $LASTEXITCODE
    }
    Write-Host ("─" * 50)
    if ($staticCode -eq 0 -and $phaseCode -eq 0) {
        Write-Host "GREEN — waiting for changes…" -ForegroundColor Green
    } else {
        Write-Host "RED — fix and save again" -ForegroundColor Red
    }
}

# Initial run
Invoke-Suite -Ph $Phase

# Debounced FileSystemWatcher over the source dirs.
$watchers = @()
foreach ($dir in @('app', 'bin', 'tests', 'public', 'database', 'scripts')) {
    $full = Join-Path $root $dir
    if (-not (Test-Path $full)) { continue }
    $w = New-Object System.IO.FileSystemWatcher
    $w.Path = $full
    $w.IncludeSubdirectories = $true
    $w.EnableRaisingEvents = $true
    $watchers += $w
}

Write-Host "Watching for changes (Ctrl+C to stop)…" -ForegroundColor DarkGray
$last = [DateTime]::MinValue
while ($true) {
    $changed = $false
    foreach ($w in $watchers) {
        $r = $w.WaitForChanged([System.IO.WatcherChangeTypes]::All, 500)
        if (-not $r.TimedOut) { $changed = $true; break }
    }
    if ($changed) {
        # debounce: coalesce bursts of save events
        Start-Sleep -Milliseconds 250
        $now = [DateTime]::Now
        if (($now - $last).TotalMilliseconds -gt 400) {
            $last = $now
            Invoke-Suite -Ph $Phase
            Write-Host "Watching for changes (Ctrl+C to stop)…" -ForegroundColor DarkGray
        }
    }
}
