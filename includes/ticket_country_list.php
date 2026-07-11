<?php

declare(strict_types=1);

/**
 * English short names for ticket country suggestions (non-exhaustive).
 * Merged at runtime with distinct values from parties.country and tickets.country.
 */
function ticket_country_list_standard(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $raw = <<<'TXT'
Afghanistan
Albania
Algeria
Argentina
Armenia
Australia
Austria
Azerbaijan
Bahrain
Bangladesh
Belarus
Belgium
Bolivia
Bosnia and Herzegovina
Botswana
Brazil
Brunei
Bulgaria
Cambodia
Cameroon
Canada
Chile
China
Colombia
Costa Rica
Croatia
Cyprus
Czech Republic
Denmark
Dominican Republic
Ecuador
Egypt
El Salvador
Estonia
Ethiopia
Finland
France
Georgia
Germany
Ghana
Greece
Guatemala
Honduras
Hong Kong SAR
Hungary
Iceland
India
Indonesia
Iran
Iraq
Ireland
Israel
Italy
Ivory Coast
Jamaica
Japan
Jordan
Kazakhstan
Kenya
Kuwait
Latvia
Lebanon
Lithuania
Luxembourg
Macao SAR
Malaysia
Maldives
Malta
Mauritius
Mexico
Moldova
Mongolia
Morocco
Myanmar
Namibia
Nepal
Netherlands
New Zealand
Nicaragua
Nigeria
North Macedonia
Norway
Oman
Pakistan
Panama
Paraguay
Peru
Philippines
Poland
Portugal
Puerto Rico
Qatar
Romania
Russia
Rwanda
Saudi Arabia
Senegal
Serbia
Singapore
Slovakia
Slovenia
South Africa
South Korea
Spain
Sri Lanka
Sudan
Sweden
Switzerland
Taiwan
Tanzania
Thailand
Trinidad and Tobago
Tunisia
Turkey
Uganda
Ukraine
United Arab Emirates
United Kingdom
United States
Uruguay
Uzbekistan
Venezuela
Vietnam
Yemen
Zambia
Zimbabwe
TXT;

    $lines = preg_split('/\R/', $raw) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    $out = array_values(array_unique($out));
    sort($out, SORT_STRING | SORT_FLAG_CASE);
    $cache = $out;

    return $cache;
}
