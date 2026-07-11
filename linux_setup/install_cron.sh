#!/bin/bash
# NOC System - Install Cron Job on Linux/Digital Ocean
# Usage: ./install_cron.sh

NOC_PATH="/var/www/html/noc"
PHP_PATH="/usr/bin/php"
LOG_PATH="/var/log/noc_mail.log"

# Create log file if not exists
touch $LOG_PATH

# Add cron job
(crontab -l 2>/dev/null | grep -v "run_mail_cycle.php"; echo "*/5 * * * * $PHP_PATH $NOC_PATH/cron/run_mail_cycle.php >> $LOG_PATH 2>&1") | crontab -

echo "Cron installed!"
echo "Run: crontab -l"
echo "Logs: tail -f $LOG_PATH"