
<?php

/**
 * Ensures required system log tables exist with optimal indexing.
 */
function system_logs_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $done = true;
    
    // Core Audit Database Log Table Schema
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS system_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NULL,
            user_name VARCHAR(255) NULL,
            login_identifier VARCHAR(255) NULL,
            attempt_type VARCHAR(20) DEFAULT 'LOGIN',
            status VARCHAR(20) DEFAULT 'SUCCESS',
            ip_address VARCHAR(100) NULL,
            location VARCHAR(255) NULL,
            browser VARCHAR(255) NULL,
            device VARCHAR(255) NULL,
            os VARCHAR(255) NULL,
            login_time DATETIME NULL,
            logout_time DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_name (user_name(50)),
            INDEX idx_login_id (login_identifier(50)),
            INDEX idx_ip_lookup (ip_address(45)),
            INDEX idx_created_status (created_at DESC, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    
    // Asynchronous Geolocation Query Cache Schema
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS system_ip_locations (
            ip_address VARCHAR(100) PRIMARY KEY,
            location VARCHAR(255) NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * Dynamically extracts the real Client IP address, handling both local environments
 * and production multi-tier proxy/load-balancer headers safely.
 */
function system_logs_get_client_ip(): string
{
    // 1. Check Standard Cloudflare header
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $cfIp = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($cfIp, FILTER_VALIDATE_IP)) {
            return $cfIp;
        }
    }

    // 2. Check Standard Enterprise Load Balancer / Forwarded headers
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // May contain a comma-separated chain (client, proxy1, proxy2). Extract the first element.
        $ipChain = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $clientIp = trim(current($ipChain));
        if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
            return $clientIp;
        }
    }

    // 3. Alternative reverse-proxy routing headers
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $realIp = trim($_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }
    }

    // 4. Default Failover Endpoint Target Block
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '0.0.0.0';
}

/**
 * Parses the user agent string with precise rules.
 */
function system_logs_parse_user_agent(): array
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $uaLower = strtolower($ua);

    $browser = 'Unknown';
    $device = 'Desktop';
    $os = 'Unknown';

    // Device Context Parsing Matrix
    if (str_contains($uaLower, 'mobile') || str_contains($uaLower, 'android')) {
        $device = 'Mobile';
    } elseif (str_contains($uaLower, 'tablet') || str_contains($uaLower, 'ipad')) {
        $device = 'Tablet';
    }

    // Agent Engine Matrix
    if (preg_match('/edg\/([0-9\.]+)/i', $ua)) {
        $browser = 'Edge';
    } elseif (preg_match('/(chrome|chromium|crios)\/([0-9\.]+)/i', $ua)) {
        $browser = 'Chrome';
    } elseif (preg_match('/firefox\/([0-9\.]+)/i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/safari\/([0-9\.]+)/i', $ua)) {
        if (!str_contains($uaLower, 'chrome') && !str_contains($uaLower, 'chromium')) {
            $browser = 'Safari';
        }
    }

    // Kernel Target Matrix
    if (preg_match('/windows nt/i', $ua)) {
        $os = 'Windows';
    } elseif (str_contains($uaLower, 'mac os x')) {
        $os = 'macOS';
    } elseif (str_contains($uaLower, 'linux') && !str_contains($uaLower, 'android')) {
        $os = 'Linux';
    } elseif (str_contains($uaLower, 'android')) {
        $os = 'Android';
    } elseif (str_contains($uaLower, 'iphone') || str_contains($uaLower, 'ipad')) {
        $os = 'iOS';
    }

    return [$browser, $device, $os];
}

/**
 * Dynamically resolves geolocation context. 
 * Strips out loopbacks immediately to handle localhost, and processes real public geo-queries in production.
 */
function system_logs_resolve_location(PDO $pdo, string $ipAddress): string
{
    $ipAddress = trim($ipAddress);
    
    // 1. Instantly return generic labels if running on local environment loops
    if (
        $ipAddress === '127.0.0.1' || 
        $ipAddress === '::1' || 
        str_starts_with($ipAddress, '127.') || 
        str_starts_with($ipAddress, '192.168.') || 
        str_starts_with($ipAddress, '10.') ||
        str_starts_with($ipAddress, '172.16.') ||
        str_starts_with($ipAddress, '172.31.')
    ) {
        return 'Localhost Dev Env';
    }

    if ($ipAddress === '0.0.0.0' || empty($ipAddress)) {
        return 'Unknown Location';
    }

    // 2. Check Local Database Cache to maximize page performance
    $stmt = $pdo->prepare('SELECT location FROM system_ip_locations WHERE ip_address = :ip LIMIT 1');
    $stmt->execute([':ip' => $ipAddress]);
    $cachedLocation = $stmt->fetchColumn();

    if ($cachedLocation !== false && !empty($cachedLocation)) {
        return (string) $cachedLocation;
    }

    // 3. Production Phase: Live Geographical API Fallback
    $location = 'Remote Node';
    $apiUrl = "https://ip-api.com/json/" . urlencode($ipAddress) . "?fields=status,city,regionName,country,zip";
    
    // Low timeout context window to insulate authorization pipelines from upstream bottlenecks
    $streamContext = stream_context_create([
        'http' => [
            'timeout' => 1.2,
            'user_agent' => 'Enterprise System Audit Logger'
        ]
    ]);
    
    $apiResponse = @file_get_contents($apiUrl, false, $streamContext);
    
    if ($apiResponse) {
        $geoData = json_decode($apiResponse, true);
        if (($geoData['status'] ?? '') === 'success') {
            $city = $geoData['city'] ?? '';
            $region = $geoData['regionName'] ?? '';
            $zip = $geoData['zip'] ?? '';
            $country = $geoData['country'] ?? '';
            
            $locationParts = array_filter([$city, $region, $zip]);
            $locationStr = implode(', ', $locationParts);
            $location = $locationStr ? $locationStr . " ($country)" : $country;
        }
    }

    // Failover Routine: DNS pointer resolving fallback strategy
    if ($location === 'Remote Node') {
        $rdns = @gethostbyaddr($ipAddress);
        if ($rdns && $rdns !== $ipAddress) {
            $location = $rdns;
        }
    }

    // 4. Save to cache table to minimize redundant API calls
    $upsert = $pdo->prepare(
        'INSERT INTO system_ip_locations (ip_address, location) VALUES (:ip, :loc)
         ON DUPLICATE KEY UPDATE location = :loc2'
    );
    $upsert->execute([':ip' => $ipAddress, ':loc' => $location, ':loc2' => $location]);

    return $location;
}

/**
 * Enterprise write tracking API for authentication logins.
 */
function log_login_activity(PDO $pdo, ?int $userId, ?string $userName, string $status): void
{
    $ipAddress = system_logs_get_client_ip();
    [$browser, $device, $os] = system_logs_parse_user_agent();
    $location = system_logs_resolve_location($pdo, $ipAddress);
    $loginTime = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "INSERT INTO system_logs 
            (user_id, user_name, login_identifier, attempt_type, status, ip_address, location, browser, device, os, login_time)
         VALUES 
            (:userId, :userName, :loginIdentifier, 'LOGIN', :status, :ipAddress, :location, :browser, :device, :os, :loginTime)"
    );
    
    $stmt->execute([
        ':userId'          => $userId,
        ':userName'        => $userName,
        ':loginIdentifier' => (string) ($userName ?? ''),
        ':status'          => $status,
        ':ipAddress'       => $ipAddress,
        ':location'        => $location,
        ':browser'         => $browser,
        ':device'          => $device,
        ':os'              => $os,
        ':loginTime'       => $loginTime,
    ]);
}

/**
 * Enterprise write tracking API for terminations/logouts.
 */
function log_logout_activity(PDO $pdo, ?int $userId, ?string $userName): void
{
    $ipAddress = system_logs_get_client_ip();
    [$browser, $device, $os] = system_logs_parse_user_agent();
    $location = system_logs_resolve_location($pdo, $ipAddress);
    $logoutTime = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "INSERT INTO system_logs 
            (user_id, user_name, login_identifier, attempt_type, status, ip_address, location, browser, device, os, logout_time)
         VALUES 
            (:userId, :userName, :loginIdentifier, 'LOGOUT', 'SUCCESS', :ipAddress, :location, :browser, :device, :os, :logoutTime)"
    );
    
    $stmt->execute([
        ':userId'          => $userId,
        ':userName'        => $userName,
        ':loginIdentifier' => (string) ($userName ?? ''),
        ':ipAddress'       => $ipAddress,
        ':location'        => $location,
        ':browser'         => $browser,
        ':device'          => $device,
        ':os'              => $os,
        ':logoutTime'      => $logoutTime,
    ]);
}

/**
 * Ensures the export_logs table exists with optimal indexing.
 */
function export_logs_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS export_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NULL,
            user_name VARCHAR(255) NULL,
            module_name VARCHAR(100) NOT NULL,
            page_name VARCHAR(100) NOT NULL,
            action_type VARCHAR(20) NOT NULL,
            export_format VARCHAR(20) NULL,
            total_records INT DEFAULT 0,
            filters_json JSON NULL,
            status VARCHAR(20) DEFAULT 'SUCCESS',
            remarks TEXT NULL,
            ip_address VARCHAR(100) NULL,
            browser VARCHAR(100) NULL,
            device VARCHAR(100) NULL,
            os VARCHAR(100) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_el_user (user_id),
            INDEX idx_el_module (module_name),
            INDEX idx_el_action (action_type),
            INDEX idx_el_status (status),
            INDEX idx_el_created (created_at DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * Logs an export/import action for audit trail.
 *
 * @return bool True on success, false on failure
 */
function log_export_activity(
    PDO $pdo,
    ?int $userId,
    ?string $userName,
    string $moduleName,
    string $pageName,
    string $actionType,
    ?string $exportFormat,
    int $totalRecords,
    ?array $filters,
    string $status,
    ?string $remarks = null,
    ?string $browser = null,
    ?string $device = null,
    ?string $os = null
): bool {
    try {
        export_logs_ensure_schema($pdo);

        $ipAddress = system_logs_get_client_ip();

        $stmt = $pdo->prepare(
            "INSERT INTO export_logs 
                (user_id, user_name, module_name, page_name, action_type, export_format, total_records, filters_json, status, remarks, ip_address, browser, device, os)
             VALUES 
                (:userId, :userName, :moduleName, :pageName, :actionType, :exportFormat, :totalRecords, :filtersJson, :status, :remarks, :ipAddress, :browser, :device, :os)"
        );

        $stmt->execute([
            ':userId'       => $userId,
            ':userName'     => $userName,
            ':moduleName'   => $moduleName,
            ':pageName'     => $pageName,
            ':actionType'   => $actionType,
            ':exportFormat' => $exportFormat,
            ':totalRecords' => $totalRecords,
            ':filtersJson'  => $filters !== null ? json_encode($filters, JSON_UNESCAPED_UNICODE) : null,
            ':status'       => $status,
            ':remarks'      => $remarks,
            ':ipAddress'    => $ipAddress,
            ':browser'      => $browser,
            ':device'       => $device,
            ':os'           => $os,
        ]);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Returns a list of export logs with pagination and filters.
 */
function export_logs_list(
    PDO $pdo,
    array $filters = [],
    int $page = 1,
    int $perPage = 50
): array {
    export_logs_ensure_schema($pdo);

    $where = [];
    $params = [];

    if (!empty($filters['search'])) {
        $where[] = '(el.user_name LIKE :search_user OR el.module_name LIKE :search_module OR el.page_name LIKE :search_page)';
        $params[':search_user'] = '%' . $filters['search'] . '%';
        $params[':search_module'] = '%' . $filters['search'] . '%';
        $params[':search_page'] = '%' . $filters['search'] . '%';
    }

    if (!empty($filters['module_name'])) {
        $where[] = 'el.module_name = :module_name';
        $params[':module_name'] = $filters['module_name'];
    }

    if (!empty($filters['page_name'])) {
        $where[] = 'el.page_name = :page_name';
        $params[':page_name'] = $filters['page_name'];
    }

    if (!empty($filters['action_type'])) {
        $where[] = 'el.action_type = :action_type';
        $params[':action_type'] = $filters['action_type'];
    }

    if (!empty($filters['export_format'])) {
        $where[] = 'el.export_format = :export_format';
        $params[':export_format'] = $filters['export_format'];
    }

    if (!empty($filters['status'])) {
        $where[] = 'el.status = :status';
        $params[':status'] = $filters['status'];
    }

    if (!empty($filters['date_from'])) {
        $where[] = 'DATE(el.created_at) >= :date_from';
        $params[':date_from'] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $where[] = 'DATE(el.created_at) <= :date_to';
        $params[':date_to'] = $filters['date_to'];
    }

    if (!empty($filters['user_id'])) {
        $where[] = 'el.user_id = :user_id';
        $params[':user_id'] = $filters['user_id'];
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM export_logs el {$whereSql}");
    foreach ($params as $key => $val) {
        $countStmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $page = max(1, $page);
    $perPage = max(1, $perPage);
    $totalPages = (int) ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT 
            el.id,
            el.user_id,
            el.user_name,
            el.module_name,
            el.page_name,
            el.action_type,
            el.export_format,
            el.total_records,
            el.filters_json,
            el.status,
            el.remarks,
            el.ip_address,
            el.created_at
         FROM export_logs el
         {$whereSql}
         ORDER BY el.created_at DESC
         LIMIT :limit OFFSET :offset"
    );

    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'logs'        => $stmt->fetchAll() ?: [],
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $totalPages,
        'offset'      => $offset,
    ];
}
