<?php

declare(strict_types=1);

/**
 * Validates Party Account payloads (server-side). Returns list of `[field -> message]` entries.
 */

function party_account_validate_payload(array $row, bool $isUpdate): array
{
    $errors = [];

    $name = isset($row['party_name']) ? trim((string) $row['party_name']) : '';
    if ($name === '') {
        $errors['party_name'] = 'Party name is required.';
    } elseif (mb_strlen($name) > 255) {
        $errors['party_name'] = 'Party name cannot exceed 255 characters.';
    }

    $email = isset($row['party_email']) ? trim((string) $row['party_email']) : '';
    if ($email !== '') {
        if (mb_strlen($email) > 255) {
            $errors['party_email'] = 'Email cannot exceed 255 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['party_email'] = 'Enter a valid email address.';
        }
    }

    $additionalEmails = $row['additional_emails'] ?? [];
    if (!is_array($additionalEmails)) {
        $additionalEmails = [];
    }
    if (count($additionalEmails) > 24) {
        $errors['additional_emails'] = 'Too many additional email addresses (max 24).';
    }
    $primaryKey = $email !== '' ? strtolower($email) : '';
    $seenAdditional = [];

    foreach ($additionalEmails as $addr) {
        $addr = trim((string) $addr);
        if ($addr === '') {
            continue;
        }
        $addrKey = strtolower($addr);
        if ($primaryKey !== '' && $addrKey === $primaryKey) {
            $errors['additional_emails'] = 'Additional emails cannot repeat the primary email.';

            break;
        }
        if (isset($seenAdditional[$addrKey])) {
            $errors['additional_emails'] = 'Duplicate additional email addresses are not allowed.';

            break;
        }
        $seenAdditional[$addrKey] = true;
        if (mb_strlen($addr) > 255) {
            $errors['additional_emails'] = 'Each email cannot exceed 255 characters.';

            break;
        }
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            $errors['additional_emails'] = 'Enter a valid email for each additional address.';

            break;
        }
    }

    $phone = isset($row['party_phone']) ? trim((string) $row['party_phone']) : '';
    $country = isset($row['country']) ? trim((string) $row['country']) : '';
    if ($phone !== '') {
        $phoneErr = party_account_validate_phone_for_country($phone, $country);
        if ($phoneErr !== null) {
            $errors['party_phone'] = $phoneErr;
        } elseif (mb_strlen(party_account_normalize_phone($phone, $country)) > 60) {
            $errors['party_phone'] = 'Phone looks too long (max 60 characters).';
        }
    }

    if ($country !== '' && party_account_resolve_country_name($country) === null) {
        $errors['country'] = 'Select a supported country from the list.';
    }

foreach (
        ['address' => [500], 'country' => [120], 'bank_name' => [180], 'account_holder_name' => [180],
            'iban_number' => [64], 'ifsc_swift_code' => [64], 'bank_branch_address' => [500], 'payment_terms' => [255]]
        as $field => [$maxLen]
        ) {
        $v = isset($row[$field]) ? trim((string) $row[$field]) : '';
        if ($v !== '' && mb_strlen($v) > $maxLen) {
            $errors[$field] = sprintf('Must be at most %u characters.', $maxLen);
        }
    }

    $acctNum = isset($row['account_number']) ? trim((string) $row['account_number']) : '';
    if ($acctNum !== '' && mb_strlen($acctNum) > 64) {
        $errors['account_number'] = 'Account number cannot exceed 64 characters.';
    }

    $credit = $row['credit_limit'] ?? null;
    if ($credit !== null && $credit !== '') {
        if (!is_numeric($credit)) {
            $errors['credit_limit'] = 'Credit limit must be numeric.';
        }
    }

    $openingBalance = $row['opening_balance'] ?? null;
    $openingBalanceStr = $openingBalance === null ? '' : trim((string) $openingBalance);
    $openingType = isset($row['opening_balance_type']) ? trim((string) $row['opening_balance_type']) : '';
    $isMultiCurrency = !empty($row['is_multi_currency']);

    if (!$isMultiCurrency) {
        if ($openingBalanceStr !== '') {
            if (!is_numeric($openingBalanceStr)) {
                $errors['opening_balance'] = 'Opening balance must be numeric.';
            } elseif ((float) $openingBalanceStr < 0) {
                $errors['opening_balance'] = 'Opening balance cannot be negative.';
            } elseif ($openingType === '' || !in_array($openingType, party_account_opening_balance_types(), true)) {
                $errors['opening_balance_type'] = 'Select Receivable or Payable when opening balance is set.';
            }
        } elseif ($openingType !== '') {
            if (!in_array($openingType, party_account_opening_balance_types(), true)) {
                $errors['opening_balance_type'] = 'Unsupported opening balance type.';
            } else {
                $errors['opening_balance'] = 'Enter opening balance when type is selected.';
            }
        }

        $currency = isset($row['currency']) ? trim((string) $row['currency']) : 'INR';
        if ($currency !== '' && !in_array($currency, party_account_currencies(), true)) {
            $errors['currency'] = 'Unsupported currency.';
        }
    }

    $currencies = $row['currencies'] ?? [];
    if (!is_array($currencies)) {
        $currencies = [];
    }
    if (count($currencies) > 8) {
        $errors['currencies'] = 'Maximum 8 currencies allowed per multi-currency party.';
    }
    if ($isMultiCurrency && count($currencies) === 0) {
        $errors['currencies'] = 'Multi-currency party must have at least one currency ledger.';
    }
    $seenCurrencies = [];
    foreach ($currencies as $idx => $curData) {
        if (!is_array($curData)) {
            continue;
        }
        $cur = trim((string) ($curData['currency'] ?? ''));
        if ($cur === '' || !in_array($cur, party_account_currencies(), true)) {
            $errors['currencies'] = 'Each currency ledger must have a valid currency.';
            break;
        }
        if (in_array($cur, $seenCurrencies, true)) {
            $errors['currencies'] = 'Currency already exists for this party.';
            break;
        }
        $seenCurrencies[] = $cur;
        $curOpen = $curData['opening_balance'] ?? null;
        $curOpenStr = $curOpen === null ? '' : trim((string) $curOpen);
        $curType = trim((string) ($curData['opening_balance_type'] ?? ''));
        if ($curOpenStr !== '') {
            if (!is_numeric($curOpenStr)) {
                $errors['currencies'] = "Currency $cur: Opening balance must be numeric.";
                break;
            }
        } elseif ($curType !== '') {
            $errors['currencies'] = "Currency $cur: Enter opening balance when type is selected.";
            break;
        }
    }

    $status = isset($row['status']) ? trim((string) $row['status']) : 'draft';
    if (!in_array($status, party_account_statuses(), true)) {
        $errors['status'] = 'Unsupported status.';
    }

    $loopEntityId = $row['loop_entity_id'] ?? null;
    if ($loopEntityId !== null && $loopEntityId !== '' && filter_var((string) $loopEntityId, FILTER_VALIDATE_INT) === false) {
        $errors['loop_entity_id'] = 'Loop entity reference is invalid.';
    }

    foreach (
        ['assistant_manager_name' => [180], 'business_manager_name' => [180]]
        as $field => [$maxLen]
    ) {
        $v = isset($row[$field]) ? trim((string) $row[$field]) : '';
        if ($v !== '' && mb_strlen($v) > $maxLen) {
            $errors[$field] = sprintf('Must be at most %u characters.', $maxLen);
        }
    }

    if (!empty($row['notes']) && mb_strlen((string) $row['notes']) > 50000) {
        $errors['notes'] = 'Notes are too long.';
    }

    return $errors;
}

function party_account_normalize_payload(array $input): array
{
    $currencies = party_account_normalize_currency_ledgers($input['currencies'] ?? []);
    $isMultiCurrency = count($currencies) > 0;
    $primaryCurrency = $isMultiCurrency ? trim((string) ($input['currency'] ?? 'INR')) : trim((string) ($input['currency'] ?? 'INR'));
    $primaryOpening = $isMultiCurrency ? ($input['opening_balance'] ?? null) : ($input['opening_balance'] ?? null);
    $primaryOpeningType = $isMultiCurrency ? trim((string) ($input['opening_balance_type'] ?? '')) : trim((string) ($input['opening_balance_type'] ?? ''));

    return [
        'party_name' => trim((string) ($input['party_name'] ?? '')),
        'party_email' => trim((string) ($input['party_email'] ?? '')),
        'additional_emails' => party_account_normalize_additional_emails($input['additional_emails'] ?? []),
        'party_phone' => party_account_normalize_phone(
            trim((string) ($input['party_phone'] ?? '')),
            trim((string) ($input['country'] ?? ''))
        ),
        'address' => trim((string) ($input['address'] ?? '')),
        'country' => ($resolved = party_account_resolve_country_name(trim((string) ($input['country'] ?? ''))))
            ? $resolved
            : trim((string) ($input['country'] ?? '')),
        'bank_name' => trim((string) ($input['bank_name'] ?? '')),
        'account_holder_name' => trim((string) ($input['account_holder_name'] ?? '')),
        'account_number' => trim((string) ($input['account_number'] ?? '')),
        'ifsc_swift_code' => trim((string) ($input['ifsc_swift_code'] ?? '')),
        'iban_number' => trim((string) ($input['iban_number'] ?? '')),
        'bank_branch_address' => trim((string) ($input['bank_branch_address'] ?? '')),
        'credit_limit' => $input['credit_limit'] ?? null,
        'opening_balance' => $isMultiCurrency ? null : ($input['opening_balance'] ?? null),
        'opening_balance_type' => $isMultiCurrency ? null : trim((string) ($input['opening_balance_type'] ?? '')),
        'currency' => $isMultiCurrency ? null : $primaryCurrency,
        'primary_currency' => $primaryCurrency,
        'primary_opening_balance' => $primaryOpening,
        'primary_opening_balance_type' => $primaryOpeningType,
        'currencies' => $currencies,
        'is_multi_currency' => $isMultiCurrency,
        'payment_terms' => trim((string) ($input['payment_terms'] ?? '')),
        'loop_entity_id' => isset($input['loop_entity_id']) && $input['loop_entity_id'] !== ''
            ? (int) $input['loop_entity_id'] : null,
        'assistant_manager_name' => trim((string) ($input['assistant_manager_name'] ?? '')),
        'business_manager_name' => trim((string) ($input['business_manager_name'] ?? '')),
        'notes' => trim((string) ($input['notes'] ?? '')),
        'status' => trim((string) ($input['status'] ?? 'draft')),
    ];
}

function party_account_normalize_currency_ledgers(array $input): array
{
    $ledgers = [];
    foreach ($input as $ledger) {
        $currency = trim((string) ($ledger['currency'] ?? ''));
        if ($currency === '' || !in_array($currency, party_account_currencies(), true)) {
            continue;
        }
        $openingBalance = $ledger['opening_balance'] ?? null;
        $openingBalanceStr = $openingBalance === null ? '' : trim((string) $openingBalance);
        $openingType = trim((string) ($ledger['opening_balance_type'] ?? ''));

        $signedBalance = 0.0;
        if ($openingBalanceStr !== '') {
            $amount = round((float) $openingBalanceStr, 2);
            $signedBalance = ($openingType === 'payable') ? -$amount : $amount;
        }

        $ledgers[] = [
            'currency' => $currency,
            'opening_balance' => $signedBalance,
            'opening_balance_type' => $openingBalanceStr !== '' && in_array($openingType, party_account_opening_balance_types(), true)
                ? $openingType
                : null,
        ];
    }
    return $ledgers;
}
