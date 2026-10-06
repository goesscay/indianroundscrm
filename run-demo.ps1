# Starts the Indian Rounds CRM against the isolated DEMO data on this machine.
#   .\run-demo.ps1            start the API (8012), real-time server (8085) and web app (3000)
#   .\run-demo.ps1 -Reload    first wipe and reload the demo data (your development data is never touched)
# Demo data lives in its own Postgres schema ("demo"), so the normal local database stays as it is.
param([switch]$Reload)

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$env:DB_SEARCH_PATH = "demo"
$env:MAIL_TRANSPORT = "log"            # nothing is ever e-mailed in the demo
$env:CRM_COMPANY_NAME = "Indian Rounds"
$env:CRM_COMPANY_ADDRESS = "18 Cathedral Road, Chennai 600086"
$env:CRM_COMPANY_PHONE = "+91 44 4000 1234"
$env:CRM_COMPANY_GSTIN = "33AABCI1234F1Z5"
$env:CRM_COMPANY_STATE = "Tamil Nadu"
$env:CRM_COMPANY_BANK = "HDFC Bank, A/c 50200012344821, IFSC HDFC0000123, Chennai"
$env:PHP_CLI_SERVER_WORKERS = "4"

Push-Location "$root\api"
if ($Reload) { php artisan demo:load }
php artisan optimize:clear | Out-Null
Pop-Location

Start-Process -WindowStyle Minimized -WorkingDirectory "$root\api" -FilePath "php" -ArgumentList "artisan serve --host=127.0.0.1 --port=8012 --no-reload"
Start-Process -WindowStyle Minimized -WorkingDirectory "$root\api" -FilePath "php" -ArgumentList "artisan reverb:start --host=127.0.0.1 --port=8085"
Start-Process -WindowStyle Minimized -WorkingDirectory "$root\web" -FilePath "npm.cmd" -ArgumentList "run dev"

Write-Host ""
Write-Host "Demo is starting. Open http://localhost:3000 in a minute."
Write-Host "Sign-in e-mails are printed by 'php artisan demo:load'; the shared password is DemoLoad::PASSWORD in api\app\Console\Commands\DemoLoad.php."
