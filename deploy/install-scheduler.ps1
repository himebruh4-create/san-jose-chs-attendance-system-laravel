# Creates (or updates) the Windows Task Scheduler task that runs `artisan schedule:run` every minute.
#
# The task runs php.exe inside `conhost.exe --headless`, a console that is never shown. Do not point the task at
# php-win.exe instead: it has no console, so every `cmd.exe` that Laravel starts (it probes the terminal size with
# `stty` and `mode CON` on each run) opens its own window, which flashes on screen once a minute.
#
# Usage (from the project folder, in PowerShell):
#   powershell -ExecutionPolicy Bypass -File deploy\install-scheduler.ps1
#   powershell -ExecutionPolicy Bypass -File deploy\install-scheduler.ps1 -Php D:\xampp\php\php.exe

param(
    [string] $Php = 'C:\xampp\php\php.exe',
    [string] $ProjectPath = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path,
    [string] $TaskName = 'SJCHS Attendance Scheduler'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $Php)) { throw "php.exe not found at $Php (pass -Php <path>)." }
if ((Split-Path $Php -Leaf) -ieq 'php-win.exe') { throw 'Use php.exe, not php-win.exe (see the comment at the top of this script).' }

$artisan = Join-Path $ProjectPath 'artisan'
if (-not (Test-Path $artisan)) { throw "artisan not found in $ProjectPath." }

$action = New-ScheduledTaskAction `
    -Execute (Join-Path $env:SystemRoot 'System32\conhost.exe') `
    -Argument "--headless `"$Php`" `"$artisan`" schedule:run" `
    -WorkingDirectory $ProjectPath
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 1)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10) `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Set-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings | Out-Null
    Write-Host "Updated task '$TaskName'."
} else {
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings `
        -Description 'Runs the SJCHS Attendance scheduler (backups, kiosk photo cleanup) every minute.' | Out-Null
    Write-Host "Created task '$TaskName'."
}

Write-Host 'On the school server, also open the task in Task Scheduler and choose "Run whether user is logged on or not".'
