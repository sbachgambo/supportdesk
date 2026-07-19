# ─────────────────────────────────────────────────────────────
# P3A — start a local dev server for browser testing.
#   powershell -ExecutionPolicy Bypass -File scripts/serve.ps1
#
# Serves public/ through the front controller (emulating the production .htaccess
# rewrite) at http://localhost:8000. Browse to http://localhost:8000 — use
# "localhost", not 127.0.0.1, so the session cookie host matches APP_URL.
# Ctrl+C to stop.
# ─────────────────────────────────────────────────────────────
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

Write-Host "P3A dev server → http://localhost:8000" -ForegroundColor Cyan
Write-Host "DB: p3a_dev   (browse to localhost, not 127.0.0.1)" -ForegroundColor DarkGray
Write-Host "Stop with Ctrl+C." -ForegroundColor DarkGray
Write-Host ""

php -d display_errors=0 -S localhost:8000 -t public tests/_router.php
