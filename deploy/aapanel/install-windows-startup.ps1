$ErrorActionPreference = "Stop"

$taskName = "Opsifin Scheduler WSL Services"
$distribution = "Ubuntu-24.04"
$scriptPath = "/home/aditya_prasetyo/project/opsifin-crontab/deploy/aapanel/wsl-start-services.sh"
$arguments = "-d $distribution -u root -- /bin/bash $scriptPath"

$action = New-ScheduledTaskAction -Execute "$env:SystemRoot\System32\wsl.exe" -Argument $arguments
$trigger = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 5)

Register-ScheduledTask `
    -TaskName $taskName `
    -Description "Starts aaPanel, Nginx, PHP-FPM, MySQL, cron, and Supervisor inside WSL." `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -RunLevel Highest `
    -Force

Write-Host "Installed scheduled task: $taskName"
