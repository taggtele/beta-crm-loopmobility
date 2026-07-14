<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__) . '/log_helper.php';

app_session_start();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST only.']);
        exit;
    }

    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Empty body.']);
        exit;
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON.']);
        exit;
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken = (string) ($body['csrf_token'] ?? '');
    if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }

    $lat = (float) ($body['lat'] ?? 0);
    $lng = (float) ($body['lng'] ?? 0);
    $ipAddress = system_logs_get_client_ip();

    if ($lat === 0.0 && $lng === 0.0) {
        echo json_encode(['success' => true, 'message' => 'No coordinates provided.']);
        exit;
    }

    $nominatimUrl = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lng}&accept-language=en";

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'user_agent' => 'CRM-System-Logger/1.0',
            'header' => "Accept: application/json\r\n"
        ]
    ]);

    $response = @file_get_contents($nominatimUrl, false, $ctx);

    $location = 'Location Unavailable';

    if ($response) {
        $geoData = json_decode($response, true);
        $address = $geoData['address'] ?? [];

        $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['county'] ?? '';
        $state = $address['state'] ?? '';
        $zip = $address['postcode'] ?? '';
        $country = $address['country'] ?? '';

        if (!$state && !empty($address['ISO3166-2-lvl4'])) {
            $parts = explode('-', $address['ISO3166-2-lvl4']);
            $stateCode = end($parts);
            $stateNames = [
                'DL' => 'Delhi', 'MH' => 'Maharashtra', 'KA' => 'Karnataka', 'TN' => 'Tamil Nadu',
                'UP' => 'Uttar Pradesh', 'GJ' => 'Gujarat', 'RJ' => 'Rajasthan', 'WB' => 'West Bengal',
                'MP' => 'Madhya Pradesh', 'PB' => 'Punjab', 'HR' => 'Haryana', 'JK' => 'Jammu and Kashmir',
                'HP' => 'Himachal Pradesh', 'UK' => 'Uttarakhand', 'BR' => 'Bihar', 'JH' => 'Jharkhand',
                'OR' => 'Odisha', 'CG' => 'Chhattisgarh', 'AS' => 'Assam', 'KA' => 'Karnataka',
                'KL' => 'Kerala', 'TS' => 'Telangana', 'AP' => 'Andhra Pradesh', 'GA' => 'Goa',
                'MN' => 'Manipur', 'ML' => 'Meghalaya', 'MZ' => 'Mizoram', 'NL' => 'Nagaland',
                'TR' => 'Tripura', 'AR' => 'Arunachal Pradesh', 'SK' => 'Sikkim', 'AN' => 'Andaman and Nicobar',
                'CH' => 'Chandigarh', 'DN' => 'Dadra and Nagar Haveli', 'DD' => 'Daman and Diu',
                'LD' => 'Lakshadweep', 'PY' => 'Puducherry',
                'CA' => 'California', 'TX' => 'Texas', 'NY' => 'New York', 'FL' => 'Florida',
                'WA' => 'Washington', 'AZ' => 'Arizona', 'CO' => 'Colorado', 'OR' => 'Oregon',
            ];
            $state = $stateNames[$stateCode] ?? $stateCode;
        }

        $parts = array_filter([$city, $state, $zip]);
        $locationStr = implode(', ', $parts);
        $location = $locationStr ? $locationStr . " ($country)" : ($country ?: 'Location Unavailable');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO system_ip_locations (ip_address, location) VALUES (:ip, :loc)
         ON DUPLICATE KEY UPDATE location = :loc2, updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([':ip' => $ipAddress, ':loc' => $location, ':loc2' => $location]);

    echo json_encode(['success' => true, 'location' => $location]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
