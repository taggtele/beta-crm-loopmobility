#!/bin/bash
# NOC System - One Command Setup for Linux/Digital Ocean
# Usage: ./setup_all.sh
# 
# This script will:
# 1. Create tables if not exist
# 2. Setup email account (edit credentials first)
# 3. Install cron job (every 5 minutes)
# 4. Run initial import

NOC_PATH="/var/www/html/noc"
PHP_PATH="/usr/bin/php"
LOG_PATH="/var/log/noc_mail.log"
DB_NAME="taggteleservices_noc_db"

echo "=========================================="
echo "NOC System - One Command Setup"
echo "=========================================="

# Step 1: Create email_accounts table if not exists
echo "[1/4] Creating database tables..."
mysql -u root -p $DB_NAME -e "
CREATE TABLE IF NOT EXISTS email_accounts (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100) NULL,
    email VARCHAR(150) NOT NULL,
    password VARCHAR(255) NOT NULL,
    imap_host VARCHAR(255) NULL,
    imap_port INT DEFAULT 993,
    encryption ENUM('ssl', 'tls', 'none') DEFAULT 'ssl',
    smtp_host VARCHAR(255) NULL,
    smtp_port INT DEFAULT 587,
    smtp_encryption ENUM('ssl', 'tls', 'none') DEFAULT 'tls',
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    last_checked_at DATETIME NULL,
    import_cutoff_at DATETIME NULL,
    last_seen_uid BIGINT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_accounts_active (is_active, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
" 2>/dev/null || echo "Table may already exist"

# Step 2: Add email account (edit cron/setup_email_account.php first!)
echo "[2/4] Adding email account..."
$PHP_PATH $NOC_PATH/cron/setup_email_account.php

# Step 3: Create log file
touch $LOG_PATH 2>/dev/null || true

# Step 4: Install cron job
echo "[3/4] Installing cron job (every 5 minutes)..."
(crontab -l 2>/dev/null | grep -v "run_mail_cycle.php"; echo "*/5 * * * * $PHP_PATH $NOC_PATH/cron/run_mail_cycle.php >> $LOG_PATH 2>&1") | crontab -

# Step 5: Run initial import
echo "[4/4] Running initial email import..."
$PHP_PATH $NOC_PATH/cron/import_imap_tickets.php

echo ""
echo "=========================================="
echo "Setup Complete!"
echo "=========================================="
echo "Cron: */5 * * * * (every 5 minutes)"
echo "Logs: tail -f $LOG_PATH"
echo "Check tickets: mysql -u root -p $DB_NAME -e \"SELECT ticket_id, external_ticket_id, issue FROM tickets ORDER BY ticket_id DESC LIMIT 5\""