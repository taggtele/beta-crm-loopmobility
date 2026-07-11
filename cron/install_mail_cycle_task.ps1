$taskName = 'NOC Mail Cycle'
$phpPath = 'C:\xampp\php\php.exe'
$scriptPath = 'C:\xampp\htdocs\noc\cron\run_mail_cycle.php'
$taskCommand = '"' + $phpPath + '" "' + $scriptPath + '"'

schtasks /Create /SC MINUTE /MO 5 /TN $taskName /TR $taskCommand /F
