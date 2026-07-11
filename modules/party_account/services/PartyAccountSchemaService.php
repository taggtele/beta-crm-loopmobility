<?php

declare(strict_types=1);

function party_account_ensure_schema(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $required = ['loop_entities', 'party_accounts', 'party_account_activity_logs'];
    $missing = [];
    foreach ($required as $table) {
        if (!party_account_table_exists($pdo, $table)) {
            $missing[] = $table;
        }
    }

    if ($missing === []) {
        party_account_ensure_party_account_am_bm_columns($pdo);
        party_account_ensure_opening_balance_columns($pdo);
        party_account_ensure_ledger_tables($pdo);
        party_account_ensure_multicurrency_schema($pdo);
        party_account_ensure_ledger_currency_unique($pdo);
        $ready = true;

        return;
    }

    if (in_array('loop_entities', $missing, true)) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `loop_entities` (
              `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `name` varchar(180) NOT NULL,
              `code` varchar(32) DEFAULT NULL,
              `status` enum(\'active\',\'inactive\') NOT NULL DEFAULT \'active\',
              `sort_order` int NOT NULL DEFAULT 0,
              `created_by` bigint UNSIGNED DEFAULT NULL,
              `updated_by` bigint UNSIGNED DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `deleted_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_loop_entities_status` (`status`),
              KEY `idx_loop_entities_deleted` (`deleted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (in_array('party_accounts', $missing, true)) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `party_accounts` (
              `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `party_name` varchar(255) NOT NULL,
              `party_email` varchar(255) DEFAULT NULL,
              `party_phone` varchar(60) DEFAULT NULL,
              `address` varchar(500) DEFAULT NULL,
              `country` varchar(120) DEFAULT NULL,
              `bank_name` varchar(180) DEFAULT NULL,
              `account_holder_name` varchar(180) DEFAULT NULL,
              `account_number` varchar(64) DEFAULT NULL,
              `ifsc_swift_code` varchar(64) DEFAULT NULL,
              `iban_number` varchar(64) DEFAULT NULL,
              `credit_limit` decimal(15,2) DEFAULT NULL,
              `currency` varchar(10) DEFAULT NULL,
              `opening_balance` decimal(15,2) DEFAULT NULL,
              `opening_balance_type` enum(\'receivable\',\'payable\') DEFAULT NULL,
              `payment_terms` varchar(255) DEFAULT NULL,
              `loop_entity_id` bigint UNSIGNED DEFAULT NULL,
              `assistant_manager_name` varchar(180) DEFAULT NULL,
              `business_manager_name` varchar(180) DEFAULT NULL,
              `notes` text,
              `status` varchar(40) NOT NULL DEFAULT \'draft\',
              `is_multi_currency` TINYINT(1) NOT NULL DEFAULT 0,
              `created_by` bigint UNSIGNED DEFAULT NULL,
              `updated_by` bigint UNSIGNED DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `deleted_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_party_accounts_party_name` (`party_name`(128)),
              KEY `idx_party_accounts_email` (`party_email`(128)),
              KEY `idx_party_accounts_status_deleted` (`status`,`deleted_at`),
              KEY `idx_party_accounts_country` (`country`(64)),
              KEY `fk_party_accounts_loop_entity` (`loop_entity_id`),
              CONSTRAINT `fk_party_accounts_loop_entity` FOREIGN KEY (`loop_entity_id`) REFERENCES `loop_entities` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (in_array('party_account_activity_logs', $missing, true)) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `party_account_activity_logs` (
              `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `party_account_id` bigint UNSIGNED NOT NULL,
              `actor_user_id` varchar(50) DEFAULT NULL,
              `actor_name` varchar(180) DEFAULT NULL,
              `action` varchar(64) NOT NULL,
              `summary` varchar(500) DEFAULT NULL,
              `metadata` json DEFAULT NULL,
              `ip_address` varchar(45) DEFAULT NULL,
              `user_agent` varchar(255) DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_pa_act_party` (`party_account_id`),
              KEY `idx_pa_act_created` (`created_at`),
              CONSTRAINT `fk_pa_act_party` FOREIGN KEY (`party_account_id`) REFERENCES `party_accounts` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    party_account_ensure_party_account_am_bm_columns($pdo);
    party_account_ensure_opening_balance_columns($pdo);
    party_account_ensure_ledger_tables($pdo);
    party_account_ensure_emails_table($pdo);
    party_account_ensure_import_logs_table($pdo);
    party_account_ensure_multicurrency_schema($pdo);
    party_account_ensure_ledger_currency_unique($pdo);
    $ready = true;
}

function party_account_ensure_ledger_tables(PDO $pdo): void
{
    if (!party_account_table_exists($pdo, 'party_ledger_transactions')) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `party_ledger_transactions` (
              `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `party_account_id` bigint UNSIGNED NOT NULL,
              `currency` varchar(10) NOT NULL DEFAULT \'INR\',
              `invoice_period` char(7) NOT NULL,
              `customer_invoice_no` varchar(120) DEFAULT NULL,
              `customer_invoice_value` decimal(15,2) NOT NULL DEFAULT 0.00,
              `vendor_invoice_no` varchar(120) DEFAULT NULL,
              `vendor_invoice_value` decimal(15,2) NOT NULL DEFAULT 0.00,
              `payment_in` decimal(15,2) NOT NULL DEFAULT 0.00,
              `payment_out` decimal(15,2) NOT NULL DEFAULT 0.00,
              `payment_in_date` date DEFAULT NULL,
              `payment_out_date` date DEFAULT NULL,
              `notes` varchar(500) DEFAULT NULL,
              `created_by` bigint UNSIGNED DEFAULT NULL,
              `updated_by` bigint UNSIGNED DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `deleted_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_party_ledger_party_period` (`party_account_id`, `invoice_period`),
              KEY `idx_party_ledger_currency` (`party_account_id`, `currency`, `invoice_period`),
              CONSTRAINT `fk_party_ledger_party`
                FOREIGN KEY (`party_account_id`) REFERENCES `party_accounts` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $existingCols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM `party_ledger_transactions`') as $col) {
            $existingCols[strtolower((string) ($col['Field'] ?? ''))] = true;
        }
        if (empty($existingCols['currency'])) {
            $pdo->exec('ALTER TABLE `party_ledger_transactions` ADD COLUMN `currency` varchar(10) NOT NULL DEFAULT \'INR\'');
        }
        if (!empty($existingCols['transaction_date'])) {
            $pdo->exec('ALTER TABLE `party_ledger_transactions` DROP COLUMN `transaction_date`');
            $pdo->exec('ALTER TABLE `party_ledger_transactions` DROP INDEX `idx_party_ledger_date`');
        }
        if (empty($existingCols['payment_in_date'])) {
            $pdo->exec('ALTER TABLE `party_ledger_transactions` ADD COLUMN `payment_in_date` date DEFAULT NULL');
        }
        if (empty($existingCols['payment_out_date'])) {
            $pdo->exec('ALTER TABLE `party_ledger_transactions` ADD COLUMN `payment_out_date` date DEFAULT NULL');
        }
    }

    if (!party_account_table_exists($pdo, 'party_ledger_monthly_closing')) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `party_ledger_monthly_closing` (
              `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `party_account_id` bigint UNSIGNED NOT NULL,
              `currency` varchar(10) NOT NULL DEFAULT \'INR\',
              `period_month` char(7) NOT NULL,
              `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
              `closing_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
              `closed_by` bigint UNSIGNED DEFAULT NULL,
              `closed_at` datetime DEFAULT NULL,
              `reopened_by` bigint UNSIGNED DEFAULT NULL,
              `reopened_at` datetime DEFAULT NULL,
              `status` enum(\'closed\',\'reopened\') NOT NULL DEFAULT \'closed\',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_party_ledger_closing_currency` (`party_account_id`, `currency`, `period_month`),
              KEY `idx_party_ledger_closing_status` (`status`),
              CONSTRAINT `fk_party_ledger_closing_party`
                FOREIGN KEY (`party_account_id`) REFERENCES `party_accounts` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $existingCols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM `party_ledger_monthly_closing`') as $col) {
            $existingCols[strtolower((string) ($col['Field'] ?? ''))] = true;
        }
        if (empty($existingCols['currency'])) {
            $pdo->exec('ALTER TABLE `party_ledger_monthly_closing` ADD COLUMN `currency` varchar(10) NOT NULL DEFAULT \'INR\'');
        }
    }
}

function party_account_ensure_multicurrency_schema(PDO $pdo): void
{
    if (!party_account_table_exists($pdo, 'party_accounts')) {
        return;
    }

    $existingCols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `party_accounts`') as $col) {
        $existingCols[strtolower((string) ($col['Field'] ?? ''))] = true;
    }

    if (empty($existingCols['is_multi_currency'])) {
        $pdo->exec('ALTER TABLE `party_accounts` ADD COLUMN `is_multi_currency` TINYINT(1) NOT NULL DEFAULT 0 AFTER `currency`');
    }

    if (!party_account_table_exists($pdo, 'party_currency_ledgers')) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `party_currency_ledgers` (
              `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
              `party_account_id` bigint UNSIGNED NOT NULL,
              `currency` varchar(10) NOT NULL,
              `opening_balance` decimal(15,2) DEFAULT NULL,
              `opening_balance_type` enum(\'receivable\',\'payable\') DEFAULT NULL,
              `status` varchar(40) NOT NULL DEFAULT \'active\',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `deleted_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_party_currency` (`party_account_id`, `currency`),
              KEY `idx_party_currency_status` (`status`),
              KEY `idx_party_currency_deleted` (`deleted_at`),
              CONSTRAINT `fk_party_currency_party`
                FOREIGN KEY (`party_account_id`) REFERENCES `party_accounts` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}

function party_account_ensure_ledger_currency_unique(PDO $pdo): void
{
    if (!party_account_table_exists($pdo, 'party_ledger_monthly_closing')) {
        return;
    }

    $existingCols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `party_ledger_monthly_closing`') as $col) {
        $existingCols[strtolower((string) ($col['Field'] ?? ''))] = true;
    }
    if (empty($existingCols['currency'])) {
        $pdo->exec('ALTER TABLE `party_ledger_monthly_closing` ADD COLUMN `currency` varchar(10) NOT NULL DEFAULT \'INR\' AFTER `party_account_id`');
    }

    $hasOldUnique = false;
    $hasNewUnique = false;

    foreach ($pdo->query('SHOW INDEX FROM `party_ledger_monthly_closing`') as $idx) {
        $keyName = (string) ($idx['Key_name'] ?? '');
        if ($keyName === 'uq_party_ledger_closing') {
            $hasOldUnique = true;
        }
        if ($keyName === 'uq_party_ledger_closing_currency') {
            $hasNewUnique = true;
        }
    }

    if ($hasOldUnique && !$hasNewUnique) {
        $pdo->exec('ALTER TABLE `party_ledger_monthly_closing` DROP INDEX `uq_party_ledger_closing`');
        $pdo->exec('ALTER TABLE `party_ledger_monthly_closing` ADD UNIQUE KEY `uq_party_ledger_closing_currency` (`party_account_id`, `currency`, `period_month`)');
    } else if (!$hasNewUnique) {
        $pdo->exec('ALTER TABLE `party_ledger_monthly_closing` ADD UNIQUE KEY `uq_party_ledger_closing_currency` (`party_account_id`, `currency`, `period_month`)');
    }
}

function party_account_ensure_opening_balance_columns(PDO $pdo): void
{
    static $columnsReady = false;

    if ($columnsReady || !party_account_table_exists($pdo, 'party_accounts')) {
        $columnsReady = true;
        return;
    }

    $existing = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `party_accounts`') as $col) {
        $field = strtolower((string) ($col['Field'] ?? ''));
        if ($field !== '') {
            $existing[$field] = true;
        }
    }

    if (empty($existing['opening_balance'])) {
        $pdo->exec(
            'ALTER TABLE `party_accounts`
             ADD COLUMN `opening_balance` DECIMAL(15,2) NULL DEFAULT NULL AFTER `currency`'
        );
    }

    if (empty($existing['opening_balance_type'])) {
        $pdo->exec(
            'ALTER TABLE `party_accounts`
             ADD COLUMN `opening_balance_type` ENUM(\'receivable\',\'payable\') NULL DEFAULT NULL
             AFTER `opening_balance`'
        );
    }

    if (!empty($existing['currency'])) {
        $stmt = $pdo->query("SHOW COLUMNS FROM `party_accounts` WHERE Field = 'currency'");
        $colInfo = $stmt->fetch();
        $isNullable = isset($colInfo['Null']) && $colInfo['Null'] === 'YES';
        if (!$isNullable) {
            $pdo->exec('ALTER TABLE `party_accounts` MODIFY COLUMN `currency` varchar(10) NULL');
        }
    }

    $columnsReady = true;
}

function party_account_ensure_party_account_am_bm_columns(PDO $pdo): void
{
    static $columnsReady = false;

    if ($columnsReady || !party_account_table_exists($pdo, 'party_accounts')) {
        $columnsReady = true;
        return;
    }

    $existing = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `party_accounts`') as $col) {
        $field = strtolower((string) ($col['Field'] ?? ''));
        if ($field !== '') {
            $existing[$field] = true;
        }
    }

    if (empty($existing['assistant_manager_name'])) {
        $pdo->exec(
            'ALTER TABLE `party_accounts`
             ADD COLUMN `assistant_manager_name` VARCHAR(180) NULL DEFAULT NULL AFTER `loop_entity_id`'
        );
    }

    if (empty($existing['business_manager_name'])) {
        $pdo->exec(
            'ALTER TABLE `party_accounts`
             ADD COLUMN `business_manager_name` VARCHAR(180) NULL DEFAULT NULL AFTER `assistant_manager_name`'
        );
        $existing['business_manager_name'] = true;
    }

    party_account_drop_legacy_am_bm_link_columns($pdo, $existing);

    if (empty($existing['bank_branch_address'])) {
        $pdo->exec(
            'ALTER TABLE `party_accounts`
             ADD COLUMN `bank_branch_address` VARCHAR(500) NULL DEFAULT NULL AFTER `iban_number`'
        );
    }

    $columnsReady = true;
}

function party_account_drop_legacy_am_bm_link_columns(PDO $pdo, array $existing): void
{
    if (!empty($existing['assistant_manager_id'])) {
        try {
            $pdo->exec('ALTER TABLE `party_accounts` DROP INDEX `idx_party_accounts_am`');
        } catch (Throwable $ignored) {
        }
        try {
            $pdo->exec('ALTER TABLE `party_accounts` DROP COLUMN `assistant_manager_id`');
        } catch (Throwable $ignored) {
        }
    }

    if (!empty($existing['business_manager_email'])) {
        try {
            $pdo->exec('ALTER TABLE `party_accounts` DROP COLUMN `business_manager_email`');
        } catch (Throwable $ignored) {
        }
    }
}

function party_account_db_diagnostics(PDO $pdo): array
{
    $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $tables = [];
    $stmt = $pdo->query('SHOW TABLES');
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            if (!empty($row[0])) {
                $tables[] = (string) $row[0];
            }
        }
    }

    $ready = party_account_table_exists($pdo, 'loop_entities')
        && party_account_table_exists($pdo, 'party_accounts')
        && party_account_table_exists($pdo, 'party_account_activity_logs');

    return [
        'database' => $db,
        'tables' => $tables,
        'party_module_ready' => $ready,
    ];
}

function party_account_sync_multicurrency_ledgers(PDO $pdo): int
{
    if (!party_account_table_exists($pdo, 'party_currency_ledgers')) {
        return 0;
    }

    $stmt = $pdo->query(
        'SELECT id, currency, opening_balance, opening_balance_type
         FROM party_accounts
         WHERE is_multi_currency = 1 AND deleted_at IS NULL'
    );
    $count = 0;
    while ($party = $stmt->fetch()) {
        $check = $pdo->prepare(
            'SELECT 1 FROM party_currency_ledgers
             WHERE party_account_id = :id AND currency = :currency AND deleted_at IS NULL
             LIMIT 1'
        );
        $check->execute([':id' => $party['id'], ':currency' => $party['currency']]);
        if (!$check->fetch()) {
            $insert = $pdo->prepare(
                'INSERT INTO party_currency_ledgers (party_account_id, currency, opening_balance, opening_balance_type, status, created_at, updated_at)
                 VALUES (:party_id, :currency, :opening_balance, :opening_balance_type, \'active\', NOW(), NOW())'
            );
            $signedOpening = ($party['opening_balance_type'] === 'payable')
                ? -round((float) ($party['opening_balance'] ?? 0), 2)
                : round((float) ($party['opening_balance'] ?? 0), 2);
            $insert->execute([
                ':party_id' => $party['id'],
                ':currency' => $party['currency'],
                ':opening_balance' => $signedOpening,
                ':opening_balance_type' => $party['opening_balance_type'],
            ]);
            $count++;
        }
    }
    return $count;
}

function party_account_pdo_error_payload(PDO $pdo, PDOException $e): array
{
    $diag = party_account_db_diagnostics($pdo);
    $msg = $e->getMessage();

    $hint = 'Tables missing in database "' . $diag['database'] . '". '
        . 'App expects: loop_entities, party_accounts, party_account_activity_logs. '
        . 'phpMyAdmin mein same DB select karke verify karo (.env DB_NAME=' . (string) env_value('DB_NAME', '') . ').';

    if (app_debug_enabled()) {
        return [
            'ok' => false,
            'error' => 'party_account_db',
            'message' => $msg,
            'hint' => $hint,
            'diagnostics' => $diag,
        ];
    }

    return [
        'ok' => false,
        'error' => 'party_account_db',
        'message' => $hint,
    ];
}