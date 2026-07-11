# NOC System - Linux/Digital Ocean Setup

## Quick Setup Commands

### 1. Database Create Karein

```sql
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
```

### 2. Email Account Setup Karein

```bash
# Edit cron/setup_email_account.php with your credentials first
nano /var/www/html/noc/cron/setup_email_account.php
```

Phir run karein:
```bash
php /var/www/html/noc/cron/setup_email_account.php
```

### 3. Test Email Import

```bash
# First run - will set baseline for future emails
php /var/www/html/noc/cron/import_imap_tickets.php
```

### 4. Setup Cron (Har 5 Minute)

```bash
# Crontab edit karein
crontab -e

# Add this line:
*/5 * * * * /usr/bin/php /var/www/html/noc/cron/run_mail_cycle.php >> /var/log/noc_mail.log 2>&1
```

### 5. Verify Cron Running

```bash
# Check crontab
crontab -l

# View logs
tail -f /var/log/noc_mail.log
```

### 6. Test Mail Cycle Manually

```bash
php /var/www/html/noc/cron/run_mail_cycle.php
```

## Common Commands

```bash
# Check recent tickets
mysql -u root -p taggteleservices_noc_db -e "SELECT ticket_id, external_ticket_id, issue, created_at FROM tickets ORDER BY ticket_id DESC LIMIT 10"

# Check notifications
mysql -u root -p taggteleservices_noc_db -e "SELECT id, title, created_at FROM notifications ORDER BY id DESC LIMIT 10"

# Check email inbox log
mysql -u root -p taggteleservices_noc_db -e "SELECT id, subject, processing_result, ticket_id FROM email_inbox_log ORDER BY id DESC LIMIT 10"

# Check email account status
mysql -u root -p taggteleservices_noc_db -e "SELECT id, email, is_active, last_checked_at, last_seen_uid FROM email_accounts"
```

## Troubleshooting

```bash
# If IMAP connection fails
# Check firewall
sudo ufw status

# Check PHP IMAP extension
php -m | grep imap

# Install if needed
sudo apt-get install php-imap
```