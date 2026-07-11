#!/bin/bash

# =========================================
# LOOP MOBILITY CRM - CRON SETUP
# =========================================

set -e

APP_PATH="/var/www/crm.loopmobility.com"

PHP_BIN=$(which php)

LOG_FILE="/var/log/loopmobility_mail.log"

CRON_FILE="$APP_PATH/cron/run_mail_cycle.php"

SETUP_FILE="$APP_PATH/cron/setup_email_account.php"

IMPORT_FILE="$APP_PATH/cron/import_imap_tickets.php"

echo ">>> Starting setup..."

# -----------------------------------------
# VALIDATION
# -----------------------------------------

if [ ! -d "$APP_PATH" ]; then
    echo "ERROR: App path not found"
    exit 1
fi

if [ ! -f "$CRON_FILE" ]; then
    echo "ERROR: run_mail_cycle.php missing"
    exit 1
fi

if [ -z "$PHP_BIN" ]; then
    echo "ERROR: PHP not installed"
    exit 1
fi

echo ">>> Validation passed"

# -----------------------------------------
# LOG FILE
# -----------------------------------------

sudo touch $LOG_FILE
sudo chmod 664 $LOG_FILE

# -----------------------------------------
# EMAIL ACCOUNT SETUP
# -----------------------------------------

echo ">>> Running email account setup..."

$PHP_BIN $SETUP_FILE

# -----------------------------------------
# INSTALL CRON
# -----------------------------------------

echo ">>> Installing cron..."

CRON_JOB="*/5 * * * * flock -n /tmp/loopmobility_mail.lock $PHP_BIN $CRON_FILE >> $LOG_FILE 2>&1"

(
    crontab -l 2>/dev/null | grep -v "$CRON_FILE"
    echo "$CRON_JOB"
) | crontab -

# -----------------------------------------
# INITIAL IMPORT
# -----------------------------------------

echo ">>> Running initial import..."

$PHP_BIN $IMPORT_FILE

echo ""
echo "===================================="
echo "SETUP COMPLETED SUCCESSFULLY"
echo "===================================="

echo ""
echo "Check cron:"
echo "crontab -l"

echo ""
echo "Check logs:"
echo "tail -f $LOG_FILE"
