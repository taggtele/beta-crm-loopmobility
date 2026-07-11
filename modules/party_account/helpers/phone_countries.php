<?php

declare(strict_types=1);

/**
 * Country dial metadata for Party Account phone validation (E.164 storage).
 */

function party_account_phone_country_aliases(): array
{
    return [
        'uae' => 'United Arab Emirates',
        'usa' => 'United States',
        'us' => 'United States',
        'uk' => 'United Kingdom',
        'gb' => 'United Kingdom',
    ];
}

/**
 * @return array<string, array{iso:string,dial:string,min:int,max:int}>
 */
function party_account_phone_rules_by_country(): array
{
    static $rules = null;
    if ($rules !== null) {
        return $rules;
    }

    $rules = [
        'Afghanistan' => ['iso' => 'AF', 'dial' => '93', 'min' => 9, 'max' => 9],
        'Albania' => ['iso' => 'AL', 'dial' => '355', 'min' => 9, 'max' => 9],
        'Algeria' => ['iso' => 'DZ', 'dial' => '213', 'min' => 9, 'max' => 9],
        'Argentina' => ['iso' => 'AR', 'dial' => '54', 'min' => 10, 'max' => 10],
        'Armenia' => ['iso' => 'AM', 'dial' => '374', 'min' => 8, 'max' => 8],
        'Australia' => ['iso' => 'AU', 'dial' => '61', 'min' => 9, 'max' => 9],
        'Austria' => ['iso' => 'AT', 'dial' => '43', 'min' => 10, 'max' => 13],
        'Azerbaijan' => ['iso' => 'AZ', 'dial' => '994', 'min' => 9, 'max' => 9],
        'Bahrain' => ['iso' => 'BH', 'dial' => '973', 'min' => 8, 'max' => 8],
        'Bangladesh' => ['iso' => 'BD', 'dial' => '880', 'min' => 10, 'max' => 10],
        'Belarus' => ['iso' => 'BY', 'dial' => '375', 'min' => 9, 'max' => 9],
        'Belgium' => ['iso' => 'BE', 'dial' => '32', 'min' => 9, 'max' => 9],
        'Bolivia' => ['iso' => 'BO', 'dial' => '591', 'min' => 8, 'max' => 8],
        'Bosnia and Herzegovina' => ['iso' => 'BA', 'dial' => '387', 'min' => 8, 'max' => 8],
        'Botswana' => ['iso' => 'BW', 'dial' => '267', 'min' => 8, 'max' => 8],
        'Brazil' => ['iso' => 'BR', 'dial' => '55', 'min' => 10, 'max' => 11],
        'Brunei' => ['iso' => 'BN', 'dial' => '673', 'min' => 7, 'max' => 7],
        'Bulgaria' => ['iso' => 'BG', 'dial' => '359', 'min' => 9, 'max' => 9],
        'Cambodia' => ['iso' => 'KH', 'dial' => '855', 'min' => 8, 'max' => 9],
        'Cameroon' => ['iso' => 'CM', 'dial' => '237', 'min' => 9, 'max' => 9],
        'Canada' => ['iso' => 'CA', 'dial' => '1', 'min' => 10, 'max' => 10],
        'Chile' => ['iso' => 'CL', 'dial' => '56', 'min' => 9, 'max' => 9],
        'China' => ['iso' => 'CN', 'dial' => '86', 'min' => 11, 'max' => 11],
        'Colombia' => ['iso' => 'CO', 'dial' => '57', 'min' => 10, 'max' => 10],
        'Costa Rica' => ['iso' => 'CR', 'dial' => '506', 'min' => 8, 'max' => 8],
        'Croatia' => ['iso' => 'HR', 'dial' => '385', 'min' => 9, 'max' => 9],
        'Cyprus' => ['iso' => 'CY', 'dial' => '357', 'min' => 8, 'max' => 8],
        'Czech Republic' => ['iso' => 'CZ', 'dial' => '420', 'min' => 9, 'max' => 9],
        'Denmark' => ['iso' => 'DK', 'dial' => '45', 'min' => 8, 'max' => 8],
        'Dominican Republic' => ['iso' => 'DO', 'dial' => '1', 'min' => 10, 'max' => 10],
        'Ecuador' => ['iso' => 'EC', 'dial' => '593', 'min' => 9, 'max' => 9],
        'Egypt' => ['iso' => 'EG', 'dial' => '20', 'min' => 10, 'max' => 10],
        'El Salvador' => ['iso' => 'SV', 'dial' => '503', 'min' => 8, 'max' => 8],
        'Estonia' => ['iso' => 'EE', 'dial' => '372', 'min' => 7, 'max' => 8],
        'Ethiopia' => ['iso' => 'ET', 'dial' => '251', 'min' => 9, 'max' => 9],
        'Finland' => ['iso' => 'FI', 'dial' => '358', 'min' => 9, 'max' => 10],
        'France' => ['iso' => 'FR', 'dial' => '33', 'min' => 9, 'max' => 9],
        'Georgia' => ['iso' => 'GE', 'dial' => '995', 'min' => 9, 'max' => 9],
        'Germany' => ['iso' => 'DE', 'dial' => '49', 'min' => 10, 'max' => 11],
        'Ghana' => ['iso' => 'GH', 'dial' => '233', 'min' => 9, 'max' => 9],
        'Greece' => ['iso' => 'GR', 'dial' => '30', 'min' => 10, 'max' => 10],
        'Guatemala' => ['iso' => 'GT', 'dial' => '502', 'min' => 8, 'max' => 8],
        'Honduras' => ['iso' => 'HN', 'dial' => '504', 'min' => 8, 'max' => 8],
        'Hong Kong SAR' => ['iso' => 'HK', 'dial' => '852', 'min' => 8, 'max' => 8],
        'Hungary' => ['iso' => 'HU', 'dial' => '36', 'min' => 9, 'max' => 9],
        'India' => ['iso' => 'IN', 'dial' => '91', 'min' => 10, 'max' => 10],
        'Indonesia' => ['iso' => 'ID', 'dial' => '62', 'min' => 9, 'max' => 11],
        'Iran' => ['iso' => 'IR', 'dial' => '98', 'min' => 10, 'max' => 10],
        'Iraq' => ['iso' => 'IQ', 'dial' => '964', 'min' => 10, 'max' => 10],
        'Ireland' => ['iso' => 'IE', 'dial' => '353', 'min' => 9, 'max' => 9],
        'Israel' => ['iso' => 'IL', 'dial' => '972', 'min' => 9, 'max' => 9],
        'Italy' => ['iso' => 'IT', 'dial' => '39', 'min' => 9, 'max' => 10],
        'Ivory Coast' => ['iso' => 'CI', 'dial' => '225', 'min' => 10, 'max' => 10],
        'Jamaica' => ['iso' => 'JM', 'dial' => '1', 'min' => 10, 'max' => 10],
        'Japan' => ['iso' => 'JP', 'dial' => '81', 'min' => 10, 'max' => 10],
        'Jordan' => ['iso' => 'JO', 'dial' => '962', 'min' => 9, 'max' => 9],
        'Kazakhstan' => ['iso' => 'KZ', 'dial' => '7', 'min' => 10, 'max' => 10],
        'Kenya' => ['iso' => 'KE', 'dial' => '254', 'min' => 9, 'max' => 9],
        'Kuwait' => ['iso' => 'KW', 'dial' => '965', 'min' => 8, 'max' => 8],
        'Latvia' => ['iso' => 'LV', 'dial' => '371', 'min' => 8, 'max' => 8],
        'Lebanon' => ['iso' => 'LB', 'dial' => '961', 'min' => 7, 'max' => 8],
        'Lithuania' => ['iso' => 'LT', 'dial' => '370', 'min' => 8, 'max' => 8],
        'Luxembourg' => ['iso' => 'LU', 'dial' => '352', 'min' => 9, 'max' => 9],
        'Macao SAR' => ['iso' => 'MO', 'dial' => '853', 'min' => 8, 'max' => 8],
        'Malaysia' => ['iso' => 'MY', 'dial' => '60', 'min' => 9, 'max' => 10],
        'Maldives' => ['iso' => 'MV', 'dial' => '960', 'min' => 7, 'max' => 7],
        'Malta' => ['iso' => 'MT', 'dial' => '356', 'min' => 8, 'max' => 8],
        'Mauritius' => ['iso' => 'MU', 'dial' => '230', 'min' => 8, 'max' => 8],
        'Mexico' => ['iso' => 'MX', 'dial' => '52', 'min' => 10, 'max' => 10],
        'Moldova' => ['iso' => 'MD', 'dial' => '373', 'min' => 8, 'max' => 8],
        'Mongolia' => ['iso' => 'MN', 'dial' => '976', 'min' => 8, 'max' => 8],
        'Morocco' => ['iso' => 'MA', 'dial' => '212', 'min' => 9, 'max' => 9],
        'Myanmar' => ['iso' => 'MM', 'dial' => '95', 'min' => 8, 'max' => 10],
        'Namibia' => ['iso' => 'NA', 'dial' => '264', 'min' => 9, 'max' => 9],
        'Nepal' => ['iso' => 'NP', 'dial' => '977', 'min' => 10, 'max' => 10],
        'Netherlands' => ['iso' => 'NL', 'dial' => '31', 'min' => 9, 'max' => 9],
        'New Zealand' => ['iso' => 'NZ', 'dial' => '64', 'min' => 8, 'max' => 10],
        'Nicaragua' => ['iso' => 'NI', 'dial' => '505', 'min' => 8, 'max' => 8],
        'Nigeria' => ['iso' => 'NG', 'dial' => '234', 'min' => 10, 'max' => 10],
        'North Macedonia' => ['iso' => 'MK', 'dial' => '389', 'min' => 8, 'max' => 8],
        'Norway' => ['iso' => 'NO', 'dial' => '47', 'min' => 8, 'max' => 8],
        'Oman' => ['iso' => 'OM', 'dial' => '968', 'min' => 8, 'max' => 8],
        'Pakistan' => ['iso' => 'PK', 'dial' => '92', 'min' => 10, 'max' => 10],
        'Panama' => ['iso' => 'PA', 'dial' => '507', 'min' => 8, 'max' => 8],
        'Paraguay' => ['iso' => 'PY', 'dial' => '595', 'min' => 9, 'max' => 9],
        'Peru' => ['iso' => 'PE', 'dial' => '51', 'min' => 9, 'max' => 9],
        'Philippines' => ['iso' => 'PH', 'dial' => '63', 'min' => 10, 'max' => 10],
        'Poland' => ['iso' => 'PL', 'dial' => '48', 'min' => 9, 'max' => 9],
        'Portugal' => ['iso' => 'PT', 'dial' => '351', 'min' => 9, 'max' => 9],
        'Puerto Rico' => ['iso' => 'PR', 'dial' => '1', 'min' => 10, 'max' => 10],
        'Qatar' => ['iso' => 'QA', 'dial' => '974', 'min' => 8, 'max' => 8],
        'Romania' => ['iso' => 'RO', 'dial' => '40', 'min' => 9, 'max' => 9],
        'Russia' => ['iso' => 'RU', 'dial' => '7', 'min' => 10, 'max' => 10],
        'Rwanda' => ['iso' => 'RW', 'dial' => '250', 'min' => 9, 'max' => 9],
        'Saudi Arabia' => ['iso' => 'SA', 'dial' => '966', 'min' => 9, 'max' => 9],
        'Senegal' => ['iso' => 'SN', 'dial' => '221', 'min' => 9, 'max' => 9],
        'Serbia' => ['iso' => 'RS', 'dial' => '381', 'min' => 8, 'max' => 9],
        'Singapore' => ['iso' => 'SG', 'dial' => '65', 'min' => 8, 'max' => 8],
        'Slovakia' => ['iso' => 'SK', 'dial' => '421', 'min' => 9, 'max' => 9],
        'Slovenia' => ['iso' => 'SI', 'dial' => '386', 'min' => 8, 'max' => 8],
        'South Africa' => ['iso' => 'ZA', 'dial' => '27', 'min' => 9, 'max' => 9],
        'South Korea' => ['iso' => 'KR', 'dial' => '82', 'min' => 9, 'max' => 10],
        'Spain' => ['iso' => 'ES', 'dial' => '34', 'min' => 9, 'max' => 9],
        'Sri Lanka' => ['iso' => 'LK', 'dial' => '94', 'min' => 9, 'max' => 9],
        'Sudan' => ['iso' => 'SD', 'dial' => '249', 'min' => 9, 'max' => 9],
        'Sweden' => ['iso' => 'SE', 'dial' => '46', 'min' => 9, 'max' => 10],
        'Switzerland' => ['iso' => 'CH', 'dial' => '41', 'min' => 9, 'max' => 9],
        'Taiwan' => ['iso' => 'TW', 'dial' => '886', 'min' => 9, 'max' => 9],
        'Tanzania' => ['iso' => 'TZ', 'dial' => '255', 'min' => 9, 'max' => 9],
        'Thailand' => ['iso' => 'TH', 'dial' => '66', 'min' => 9, 'max' => 9],
        'Trinidad and Tobago' => ['iso' => 'TT', 'dial' => '1', 'min' => 10, 'max' => 10],
        'Tunisia' => ['iso' => 'TN', 'dial' => '216', 'min' => 8, 'max' => 8],
        'Turkey' => ['iso' => 'TR', 'dial' => '90', 'min' => 10, 'max' => 10],
        'Uganda' => ['iso' => 'UG', 'dial' => '256', 'min' => 9, 'max' => 9],
        'Ukraine' => ['iso' => 'UA', 'dial' => '380', 'min' => 9, 'max' => 9],
        'United Arab Emirates' => ['iso' => 'AE', 'dial' => '971', 'min' => 9, 'max' => 9],
        'United Kingdom' => ['iso' => 'GB', 'dial' => '44', 'min' => 10, 'max' => 10],
        'United States' => ['iso' => 'US', 'dial' => '1', 'min' => 10, 'max' => 10],
        'Uruguay' => ['iso' => 'UY', 'dial' => '598', 'min' => 8, 'max' => 8],
        'Uzbekistan' => ['iso' => 'UZ', 'dial' => '998', 'min' => 9, 'max' => 9],
        'Venezuela' => ['iso' => 'VE', 'dial' => '58', 'min' => 10, 'max' => 10],
        'Vietnam' => ['iso' => 'VN', 'dial' => '84', 'min' => 9, 'max' => 10],
        'Yemen' => ['iso' => 'YE', 'dial' => '967', 'min' => 9, 'max' => 9],
        'Zambia' => ['iso' => 'ZM', 'dial' => '260', 'min' => 9, 'max' => 9],
        'Zimbabwe' => ['iso' => 'ZW', 'dial' => '263', 'min' => 9, 'max' => 9],
    ];

    return $rules;
}

function party_account_flag_image_url(string $iso2, int $width = 40): string
{
    $iso2 = strtolower(preg_replace('/[^a-z]/i', '', $iso2) ?? '');
    if (strlen($iso2) !== 2) {
        return '';
    }

    $width = max(20, min(80, $width));

    return 'https://flagcdn.com/w' . $width . '/' . $iso2 . '.png';
}

function party_account_resolve_country_name(string $input): ?string
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    $rules = party_account_phone_rules_by_country();
    if (isset($rules[$input])) {
        return $input;
    }

    $lower = strtolower($input);
    foreach (party_account_phone_country_aliases() as $alias => $canonical) {
        if ($lower === $alias && isset($rules[$canonical])) {
            return $canonical;
        }
    }

    foreach (array_keys($rules) as $name) {
        if (strcasecmp($name, $input) === 0) {
            return $name;
        }
    }

    return null;
}

/**
 * @return array{name:string,iso:string,dial:string,min:int,max:int,flag_url:string}|null
 */
function party_account_phone_meta_for_country(string $country): ?array
{
    $canonical = party_account_resolve_country_name($country);
    if ($canonical === null) {
        return null;
    }

    $rule = party_account_phone_rules_by_country()[$canonical];

    return [
        'name' => $canonical,
        'iso' => $rule['iso'],
        'dial' => $rule['dial'],
        'min' => $rule['min'],
        'max' => $rule['max'],
        'flag_url' => party_account_flag_image_url($rule['iso']),
    ];
}

/**
 * @return list<array{name:string,iso:string,dial:string,min:int,max:int,flag_url:string}>
 */
function party_account_country_phone_catalog(): array
{
    $out = [];
    foreach (party_account_phone_rules_by_country() as $name => $rule) {
        $out[] = [
            'name' => $name,
            'iso' => $rule['iso'],
            'dial' => $rule['dial'],
            'min' => $rule['min'],
            'max' => $rule['max'],
            'flag_url' => party_account_flag_image_url($rule['iso']),
        ];
    }

    usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    return $out;
}

function party_account_phone_digits_only(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

/**
 * Split stored / typed phone into dial + national parts for a known country.
 *
 * @return array{dial:string,national:string,full:string}|null
 */
function party_account_phone_parse(string $phone, string $country): ?array
{
    $meta = party_account_phone_meta_for_country($country);
    if ($meta === null) {
        return null;
    }

    $digits = party_account_phone_digits_only($phone);
    if ($digits === '') {
        return ['dial' => $meta['dial'], 'national' => '', 'full' => ''];
    }

    $dial = $meta['dial'];
    if (str_starts_with($digits, $dial)) {
        $national = substr($digits, strlen($dial));
    } else {
        $national = $digits;
    }

    $national = ltrim($national, '0');

    return [
        'dial' => $dial,
        'national' => $national,
        'full' => $national === '' ? '' : '+' . $dial . $national,
    ];
}

function party_account_normalize_phone(string $phone, string $country): string
{
    $parsed = party_account_phone_parse($phone, $country);
    if ($parsed === null) {
        return trim($phone);
    }

    return $parsed['full'];
}

function party_account_validate_phone_for_country(string $phone, string $country): ?string
{
    $phone = trim($phone);
    if ($phone === '') {
        return null;
    }

    if (trim($country) === '') {
        return 'Select a country before entering a phone number.';
    }

    $meta = party_account_phone_meta_for_country($country);
    if ($meta === null) {
        return 'Unsupported country for phone validation.';
    }

    $parsed = party_account_phone_parse($phone, $meta['name']);
    if ($parsed === null || $parsed['national'] === '') {
        return 'Enter a valid phone number.';
    }

    $national = $parsed['national'];
    if (!ctype_digit($national)) {
        return 'Phone must contain digits only (no spaces or symbols).';
    }

    $len = strlen($national);
    if ($len < $meta['min'] || $len > $meta['max']) {
        return sprintf(
            'For %s, enter %d–%d digits after country code +%s.',
            $meta['name'],
            $meta['min'],
            $meta['max'],
            $meta['dial']
        );
    }

    if ($meta['iso'] === 'IN' && $national[0] < '6') {
        return 'Indian mobile numbers must start with 6–9.';
    }

    return null;
}
