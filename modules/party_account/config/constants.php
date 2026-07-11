<?php

declare(strict_types=1);

if (!defined('PARTY_ACCOUNT_MODULE_ROOT')) {
    define('PARTY_ACCOUNT_MODULE_ROOT', dirname(__DIR__));
}

function party_account_statuses(): array
{
    return ['draft', 'active', 'suspended', 'archived'];
}

function party_account_currencies(): array
{
    return ['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'NZD', 'OTHER'];
}

function party_account_currency_symbol(string $currency): string
{
    return match ($currency) {
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'AED' => 'د.إ',
        'SGD' => 'S$',
        'AUD' => 'A$',
        'NZD' => 'NZ$',
        'INR' => '₹',
        'OTHER' => '',
        default => '',
    };
}

function party_account_opening_balance_types(): array
{
    return ['receivable', 'payable'];
}

function party_account_ajax_base_url(): string
{
    return url('modules/party_account/ajax/');
}

function party_account_table_exists(PDO $pdo, string $table): bool
{
    $table = trim($table);
    if ($table === '') {
        return false;
    }

    $quoted = $pdo->quote($table);
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $quoted);
    if ($stmt && $stmt->fetchColumn()) {
        return true;
    }

    $all = $pdo->query('SHOW TABLES');
    if (!$all) {
        return false;
    }

    while ($row = $all->fetch(PDO::FETCH_NUM)) {
        if (isset($row[0]) && strcasecmp((string) $row[0], $table) === 0) {
            return true;
        }
    }

    return false;
}
