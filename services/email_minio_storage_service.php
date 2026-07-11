<?php

/**
 * Optional MinIO object storage pipeline for email files.
 *
 * This MinIO + compression pipeline is currently for local testing. Production enable/disable controlled via config flag USE_MINIO.
 */

defined('APP_ROOT') || define('APP_ROOT', realpath(dirname(__DIR__)));
defined('STORAGE_PATH') || define('STORAGE_PATH', APP_ROOT . '/storage');

if (!function_exists('env_value')) {
    require_once APP_ROOT . '/core/env.php';
    app_load_env(APP_ROOT . '/.env');
}

function email_minio_enabled(): bool
{
    return filter_var(env_value('USE_MINIO', false), FILTER_VALIDATE_BOOL);
}

function email_minio_bucket(): string
{
    $bucket = trim((string) env_value('MINIO_MAIL_BUCKET', env_value('MINIO_BUCKET', 'crm-mail')));

    return $bucket !== '' ? $bucket : 'crm-mail';
}

function email_minio_config(): array
{
    $endpoint = trim((string) env_value('MINIO_ENDPOINT', 'http://127.0.0.1:9000'));
    if ($endpoint !== '' && !preg_match('#^https?://#i', $endpoint)) {
        $endpoint = 'http://' . $endpoint;
    }

    return [
        'endpoint' => rtrim($endpoint, '/'),
        'access_key' => trim((string) env_value('MINIO_ACCESS_KEY', '')),
        'secret_key' => trim((string) env_value('MINIO_SECRET_KEY', '')),
        'region' => trim((string) env_value('MINIO_REGION', 'us-east-1')) ?: 'us-east-1',
        'bucket' => email_minio_bucket(),
        'public_base_url' => rtrim(trim((string) env_value('MINIO_MAIL_PUBLIC_BASE_URL', env_value('MINIO_PUBLIC_BASE_URL', ''))), '/'),
        'ssl_verify' => filter_var(env_value('MINIO_SSL_VERIFY', true), FILTER_VALIDATE_BOOL),
    ];
}

function email_minio_ensure_mapping_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS email_files_map (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            source VARCHAR(20) NOT NULL,
            email_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            file_name VARCHAR(255) NOT NULL,
            file_type VARCHAR(20) NOT NULL DEFAULT \'attachment\',
            mime_type VARCHAR(120) NOT NULL DEFAULT \'application/octet-stream\',
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            file_hash CHAR(64) NOT NULL,
            storage_type VARCHAR(20) NOT NULL DEFAULT \'minio\',
            minio_url VARCHAR(1000) NOT NULL,
            object_key VARCHAR(700) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_email_files_source_email (source, email_log_id),
            KEY idx_email_files_hash (file_hash),
            UNIQUE KEY uq_email_files_map_item (source, email_log_id, file_hash, file_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

    $ready = true;
}

function email_minio_ensure_audit_table(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS email_storage_audit_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(60) NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT \'\',
            email_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            file_type VARCHAR(20) NOT NULL DEFAULT \'\',
            file_hash CHAR(64) NULL,
            object_key VARCHAR(700) NULL,
            storage_type VARCHAR(20) NOT NULL DEFAULT \'minio\',
            user_id VARCHAR(80) NULL,
            correlation_id VARCHAR(120) NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'success\',
            error_message TEXT NULL,
            meta_json TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_email_storage_audit_email (source, email_log_id, created_at),
            KEY idx_email_storage_audit_hash (file_hash),
            KEY idx_email_storage_audit_event (event_type, created_at),
            KEY idx_email_storage_audit_correlation (correlation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

    $ready = true;
}

function email_minio_pdo(?PDO $pdo = null): ?PDO
{
    if ($pdo instanceof PDO) {
        try {
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (PDOException $e) {
            $lost = function_exists('pdo_connection_is_lost')
                ? pdo_connection_is_lost($e)
                : (
                    (int) ($e->errorInfo[1] ?? 0) === 2006
                    || (int) ($e->errorInfo[1] ?? 0) === 2013
                    || stripos($e->getMessage(), 'server has gone away') !== false
                    || stripos($e->getMessage(), 'lost connection') !== false
                );
            if (!$lost || !function_exists('get_pdo')) {
                return $pdo;
            }
        }
    }

    if (function_exists('get_pdo')) {
        try {
            return get_pdo();
        } catch (Throwable $ignored) {
            return null;
        }
    }

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    return null;
}

function email_minio_actor_id(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    foreach (['user_id', 'id', 'username', 'email'] as $key) {
        if (isset($_SESSION[$key]) && trim((string) $_SESSION[$key]) !== '') {
            return mb_substr((string) $_SESSION[$key], 0, 80);
        }
    }

    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        foreach (['user_id', 'id', 'username', 'email'] as $key) {
            if (isset($_SESSION['user'][$key]) && trim((string) $_SESSION['user'][$key]) !== '') {
                return mb_substr((string) $_SESSION['user'][$key], 0, 80);
            }
        }
    }

    return null;
}

function email_minio_correlation_id(string $source, int $emailId, string $fileHash = ''): string
{
    $base = email_minio_source($source) . ':' . max(0, $emailId);
    if ($fileHash !== '') {
        $base .= ':' . strtolower($fileHash);
    }

    return hash('sha256', $base);
}

function email_minio_audit_event(array $event, ?PDO $pdo = null): void
{
    $pdo = email_minio_pdo($pdo);
    if (!$pdo) {
        return;
    }

    try {
        email_minio_ensure_audit_table($pdo);
        $meta = $event['meta'] ?? [];
        $metaJson = is_array($meta) ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null;
        if ($metaJson === false) {
            $metaJson = null;
        }

        $source = email_minio_source((string) ($event['source'] ?? ''));
        $emailId = max(0, (int) ($event['email_log_id'] ?? $event['email_id'] ?? 0));
        $fileHash = strtolower(trim((string) ($event['file_hash'] ?? '')));
        $stmt = $pdo->prepare(
            'INSERT INTO email_storage_audit_logs (
                event_type,
                source,
                email_log_id,
                file_type,
                file_hash,
                object_key,
                storage_type,
                user_id,
                correlation_id,
                ip_address,
                user_agent,
                status,
                error_message,
                meta_json,
                created_at
            ) VALUES (
                :event_type,
                :source,
                :email_log_id,
                :file_type,
                :file_hash,
                :object_key,
                :storage_type,
                :user_id,
                :correlation_id,
                :ip_address,
                :user_agent,
                :status,
                :error_message,
                :meta_json,
                NOW()
            )'
        );
        $stmt->execute([
            ':event_type' => mb_substr((string) ($event['event_type'] ?? 'storage.event'), 0, 60),
            ':source' => $source,
            ':email_log_id' => $emailId,
            ':file_type' => mb_substr((string) ($event['file_type'] ?? ''), 0, 20),
            ':file_hash' => $fileHash !== '' ? $fileHash : null,
            ':object_key' => trim((string) ($event['object_key'] ?? '')) ?: null,
            ':storage_type' => mb_substr((string) ($event['storage_type'] ?? 'minio'), 0, 20),
            ':user_id' => mb_substr((string) ($event['user_id'] ?? email_minio_actor_id() ?? ''), 0, 80) ?: null,
            ':correlation_id' => mb_substr((string) ($event['correlation_id'] ?? email_minio_correlation_id($source, $emailId, $fileHash)), 0, 120),
            ':ip_address' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) ?: null,
            ':user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            ':status' => mb_substr((string) ($event['status'] ?? 'success'), 0, 20),
            ':error_message' => trim((string) ($event['error_message'] ?? '')) ?: null,
            ':meta_json' => $metaJson,
        ]);
    } catch (Throwable $ignored) {
        // Audit failures must never break the production mail flow.
    }
}

function email_minio_safe_filename(string $name, string $fallback = 'file.bin'): string
{
    $name = basename(str_replace(["\0", "\r", "\n"], '', trim($name)));
    $name = preg_replace('/[^\w.\- ()\[\]]+/', '_', $name) ?? '';
    $name = trim($name, " ._\t\r\n");

    return $name !== '' ? $name : $fallback;
}

function email_minio_mime_extension(string $mime, string $fallbackName = ''): string
{
    $mime = strtolower(trim($mime));
    $map = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    if (isset($map[$mime])) {
        return $map[$mime];
    }

    $ext = strtolower((string) pathinfo($fallbackName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';

    return $ext !== '' ? $ext : 'bin';
}

function email_minio_rename_for_mime(string $name, string $mime): string
{
    $name = email_minio_safe_filename($name);
    $targetExt = email_minio_mime_extension($mime, $name);
    $currentExt = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    if ($targetExt === '' || $currentExt === $targetExt) {
        return $name;
    }

    $stem = (string) pathinfo($name, PATHINFO_FILENAME);
    $stem = $stem !== '' ? $stem : 'file';

    return $stem . '.' . $targetExt;
}

function email_minio_is_image_mime(string $mime): bool
{
    return preg_match('#^image/(jpe?g|png|gif|webp)$#i', trim($mime)) === 1;
}

function email_minio_file_type(string $fileType): string
{
    $fileType = strtolower(trim($fileType));

    return in_array($fileType, ['inline', 'attachment', 'raw'], true) ? $fileType : 'attachment';
}

function email_minio_object_key(string $fileType, int $emailId, string $fileHash, string $mime = '', string $fileName = '', ?int $timestamp = null): string
{
    $fileType = email_minio_file_type($fileType);
    $emailId = max(0, $emailId);
    $fileHash = strtolower(preg_replace('/[^a-f0-9]/i', '', $fileHash) ?? '');
    if ($emailId <= 0 || $fileHash === '') {
        return '';
    }

    $time = $timestamp !== null && $timestamp > 0 ? $timestamp : time();
    $year = date('Y', $time);
    $month = date('m', $time);
    $prefix = $fileType === 'inline' ? 'inline' : ($fileType === 'raw' ? 'raw' : 'attachments');
    $name = $emailId . '_' . $fileHash;
    if ($fileType === 'raw') {
        $lowerName = strtolower($fileName);
        if (str_ends_with($lowerName, '.eml.zst')) {
            $name .= '.eml.zst';
        } elseif (str_ends_with($lowerName, '.eml.gz')) {
            $name .= '.eml.gz';
        } else {
            $name .= '.eml';
        }
    } elseif ($fileType === 'attachment') {
        $lowerName = strtolower($fileName);
        if (str_ends_with($lowerName, '.zst')) {
            $name .= '.zst';
        } elseif (str_ends_with($lowerName, '.gz')) {
            $name .= '.gz';
        }
    }

    return $prefix . '/' . $year . '/' . $month . '/' . $name;
}

function email_minio_is_flat_object_key(string $objectKey, string $fileType = ''): bool
{
    $objectKey = trim(str_replace('\\', '/', $objectKey), '/');
    if ($objectKey === '') {
        return false;
    }

    $prefixes = [];
    $fileType = email_minio_file_type($fileType);
    if ($fileType === 'inline') {
        $prefixes[] = 'inline';
    } elseif ($fileType === 'raw') {
        $prefixes[] = 'raw';
    } else {
        $prefixes[] = 'attachments';
    }

    foreach ($prefixes as $prefix) {
        if ($fileType === 'raw') {
            if (preg_match('#^raw/\d{4}/\d{2}/\d+_[a-f0-9]{64}\.eml(?:\.(?:gz|zst))?$#i', $objectKey) === 1) {
                return true;
            }
            continue;
        }

        if ($fileType === 'attachment') {
            if (preg_match('#^attachments/\d{4}/\d{2}/\d+_[a-f0-9]{64}(?:\.(?:gz|zst))?$#i', $objectKey) === 1) {
                return true;
            }
            continue;
        }

        if (preg_match('#^' . preg_quote($prefix, '#') . '/\d{4}/\d{2}/\d+_[a-f0-9]{64}$#i', $objectKey) === 1) {
            return true;
        }
    }

    return false;
}

function email_minio_is_compressed_raw_object_key(string $objectKey): bool
{
    return preg_match('#^raw/\d{4}/\d{2}/\d+_[a-f0-9]{64}\.eml\.(?:gz|zst)$#i', trim(str_replace('\\', '/', $objectKey), '/')) === 1;
}

function email_minio_is_compressed_object_key(string $objectKey): bool
{
    return preg_match('#\.(?:gz|zst)$#i', trim(str_replace('\\', '/', $objectKey), '/')) === 1;
}

function email_minio_image_target_max_bytes(): int
{
    return 150 * 1024;
}

function email_minio_detect_mime(string $filePath, string $fallback = 'application/octet-stream'): string
{
    $mime = '';
    if (function_exists('mime_content_type') && is_readable($filePath)) {
        $mime = (string) (@mime_content_type($filePath) ?: '');
    }

    return $mime !== '' ? $mime : $fallback;
}

function email_minio_tmp_dir(): string
{
    $dir = rtrim(str_replace('\\', '/', (string) STORAGE_PATH), '/') . '/minio_tmp';
    if (!is_dir($dir)) {
        $parentDir = dirname($dir);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    email_minio_cleanup_stale_temp_files($dir);

    return $dir;
}

function email_minio_cleanup_stale_temp_files(string $dir): void
{
    static $checked = false;
    if ($checked || !is_dir($dir)) {
        return;
    }
    $checked = true;

    $cutoff = time() - 3600;
    foreach (glob(rtrim($dir, '/\\') . '/mail_*') ?: [] as $path) {
        if (is_file($path) && (int) (@filemtime($path) ?: time()) < $cutoff) {
            @unlink($path);
        }
    }
}

function email_minio_temp_file(string $extension = 'bin'): string
{
    $extension = preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'bin';
    $base = tempnam(email_minio_tmp_dir(), 'mail_');
    if ($base === false) {
        throw new RuntimeException('Unable to create temporary email storage file.');
    }

    $path = $base . '.' . strtolower($extension);
    rename($base, $path);

    return $path;
}

function email_minio_image_resource(string $filePath, string $mime)
{
    $mime = strtolower($mime);
    if (($mime === 'image/jpeg' || $mime === 'image/jpg') && function_exists('imagecreatefromjpeg')) {
        return @imagecreatefromjpeg($filePath);
    }
    if ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        return @imagecreatefrompng($filePath);
    }
    if ($mime === 'image/gif' && function_exists('imagecreatefromgif')) {
        return @imagecreatefromgif($filePath);
    }
    if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($filePath);
    }

    return false;
}

function email_minio_scaled_canvas($source, int $width, int $height, float $scale)
{
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));
    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$canvas) {
        return false;
    }

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

    return $canvas;
}

function email_minio_write_image($image, string $targetPath, string $mime, int $quality): bool
{
    if ($mime === 'image/webp' && function_exists('imagewebp')) {
        return @imagewebp($image, $targetPath, max(45, min(90, $quality)));
    }

    if (function_exists('imagejpeg')) {
        $jpeg = imagecreatetruecolor(imagesx($image), imagesy($image));
        if (!$jpeg) {
            return false;
        }
        $white = imagecolorallocate($jpeg, 255, 255, 255);
        imagefilledrectangle($jpeg, 0, 0, imagesx($jpeg), imagesy($jpeg), $white);
        imagecopy($jpeg, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        $ok = @imagejpeg($jpeg, $targetPath, max(45, min(90, $quality)));
        imagedestroy($jpeg);

        return $ok;
    }

    return false;
}

function email_minio_output_image_mime(): string
{
    return function_exists('imagewebp') ? 'image/webp' : 'image/jpeg';
}

function email_minio_skip_binary_compression_mime(string $mime): bool
{
    $mime = strtolower(trim($mime));
    if ($mime === '') {
        return false;
    }

    if (str_starts_with($mime, 'image/')
        || str_starts_with($mime, 'video/')
        || str_starts_with($mime, 'audio/')
    ) {
        return true;
    }

    return in_array($mime, [
        'application/zip',
        'application/x-zip-compressed',
        'application/gzip',
        'application/x-gzip',
        'application/x-7z-compressed',
        'application/x-rar-compressed',
        'application/vnd.rar',
        'application/zstd',
        'application/x-zstd',
    ], true);
}

function email_minio_compress_file_to_temp(string $filePath, string $mime): array
{
    if (!is_readable($filePath) || email_minio_skip_binary_compression_mime($mime)) {
        return [];
    }

    $originalSize = (int) (@filesize($filePath) ?: 0);
    if ($originalSize <= 0) {
        return [];
    }

    $source = @file_get_contents($filePath);
    if (!is_string($source) || $source === '') {
        return [];
    }

    $algorithm = '';
    $compressed = '';
    $extension = '';
    $mimeOut = '';

    if (function_exists('zstd_compress')) {
        try {
            $zstd = zstd_compress($source, 10);
            if (is_string($zstd) && $zstd !== '') {
                $algorithm = 'zstd';
                $compressed = $zstd;
                $extension = 'zst';
                $mimeOut = 'application/zstd';
            }
        } catch (Throwable $ignored) {
            $compressed = '';
        }
    }

    if ($compressed === '' && function_exists('gzencode')) {
        $gzip = gzencode($source, 6, FORCE_GZIP);
        if (is_string($gzip) && $gzip !== '') {
            $algorithm = 'gzip';
            $compressed = $gzip;
            $extension = 'gz';
            $mimeOut = 'application/gzip';
        }
    }

    if ($compressed === '') {
        return [];
    }

    $compressedSize = strlen($compressed);
    if ($compressedSize <= 0 || $compressedSize >= (int) floor($originalSize * 0.98)) {
        return [];
    }

    $tmp = email_minio_temp_file($extension);
    if (file_put_contents($tmp, $compressed, LOCK_EX) === false) {
        @unlink($tmp);
        return [];
    }

    return [
        'path' => $tmp,
        'algorithm' => $algorithm,
        'extension' => $extension,
        'mime' => $mimeOut,
        'original_size' => $originalSize,
        'compressed_size' => $compressedSize,
    ];
}

function email_minio_decompress_binary(string $binary, string $objectKey): string
{
    if ($binary === '' || !email_minio_is_compressed_object_key($objectKey)) {
        return $binary;
    }

    if (preg_match('#\.gz$#i', $objectKey) === 1 && function_exists('gzdecode')) {
        $decoded = @gzdecode($binary);

        return is_string($decoded) && $decoded !== '' ? $decoded : $binary;
    }

    if (preg_match('#\.zst$#i', $objectKey) === 1 && function_exists('zstd_uncompress')) {
        try {
            $decoded = zstd_uncompress($binary);

            return is_string($decoded) && $decoded !== '' ? $decoded : $binary;
        } catch (Throwable $ignored) {
            return $binary;
        }
    }

    return $binary;
}

function email_minio_normalize_image_quality(int $quality): int
{
    return max(45, min(90, $quality));
}

function compressImage(string $filePath, int $quality = 82): string
{
    if (!is_readable($filePath)) {
        return $filePath;
    }

    $info = @getimagesize($filePath);
    if (!is_array($info) || empty($info[0]) || empty($info[1])) {
        return $filePath;
    }

    $mime = strtolower((string) ($info['mime'] ?? email_minio_detect_mime($filePath)));
    if (!email_minio_is_image_mime($mime)) {
        return $filePath;
    }

    if (!extension_loaded('gd')) {
        return $filePath;
    }

    $source = email_minio_image_resource($filePath, $mime);
    if (!$source) {
        return $filePath;
    }

    $width = (int) $info[0];
    $height = (int) $info[1];
    $originalSize = (int) (@filesize($filePath) ?: 0);
    $targetMax = email_minio_image_target_max_bytes();
    $outputMime = email_minio_output_image_mime();
    $ext = email_minio_mime_extension($outputMime);
    $bestPath = '';
    $bestSize = PHP_INT_MAX;

    $qualities = array_values(array_unique([
        email_minio_normalize_image_quality($quality),
        78,
        72,
        66,
        60,
        55,
    ]));
    $scales = [1.0, 0.85, 0.72, 0.6, 0.5, 0.42];

    foreach ($scales as $scale) {
        $canvas = email_minio_scaled_canvas($source, $width, $height, $scale);
        if (!$canvas) {
            continue;
        }

        foreach ($qualities as $q) {
            $candidate = email_minio_temp_file($ext);
            if (!email_minio_write_image($canvas, $candidate, $outputMime, (int) $q)) {
                @unlink($candidate);
                continue;
            }

            $size = (int) (@filesize($candidate) ?: 0);
            if ($size > 0 && $size < $bestSize) {
                if ($bestPath !== '') {
                    @unlink($bestPath);
                }
                $bestPath = $candidate;
                $bestSize = $size;
            } else {
                @unlink($candidate);
            }

            if ($size > 0 && $size <= $targetMax) {
                break 2;
            }
        }

        imagedestroy($canvas);
    }

    imagedestroy($source);

    if ($bestPath === '') {
        return $filePath;
    }

    return $bestPath;
}

function generateFileHash(string $filePath): string
{
    if (!is_readable($filePath)) {
        return '';
    }

    return hash_file('sha256', $filePath) ?: '';
}

function isDuplicate(string $fileHash, ?PDO $pdo = null): ?array
{
    $fileHash = strtolower(trim($fileHash));
    if ($fileHash === '') {
        return null;
    }

    $pdo = email_minio_pdo($pdo);
    if (!$pdo) {
        return null;
    }

    email_minio_ensure_mapping_table($pdo);
    $stmt = $pdo->prepare(
        'SELECT file_hash, minio_url, object_key, mime_type, file_size
         FROM email_files_map
         WHERE file_hash = :file_hash
         AND storage_type = :storage_type
         ORDER BY id ASC
         LIMIT 1'
    );
    $stmt->execute([
        ':file_hash' => $fileHash,
        ':storage_type' => 'minio',
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function storeFileMapping(array $metadata, ?PDO $pdo = null): bool
{
    $pdo = email_minio_pdo($pdo);
    if (!$pdo) {
        return false;
    }

    email_minio_ensure_mapping_table($pdo);
    $source = email_minio_source((string) ($metadata['source'] ?? ''));
    $emailId = max(0, (int) ($metadata['email_log_id'] ?? $metadata['email_id'] ?? 0));
    $fileHash = strtolower(trim((string) ($metadata['file_hash'] ?? '')));
    $minioUrl = trim((string) ($metadata['minio_url'] ?? ''));
    $objectKey = trim((string) ($metadata['object_key'] ?? ''));
    if ($source === '' || $emailId <= 0 || $fileHash === '' || $minioUrl === '' || $objectKey === '') {
        return false;
    }
    $fileType = email_minio_file_type((string) ($metadata['file_type'] ?? 'attachment'));
    $params = [
        ':source' => $source,
        ':email_log_id' => $emailId,
        ':file_name' => mb_substr(email_minio_safe_filename((string) ($metadata['file_name'] ?? 'file.bin')), 0, 255),
        ':file_type' => $fileType,
        ':mime_type' => mb_substr((string) ($metadata['mime_type'] ?? 'application/octet-stream'), 0, 120),
        ':file_size' => max(0, (int) ($metadata['file_size'] ?? 0)),
        ':file_hash' => $fileHash,
        ':storage_type' => (string) ($metadata['storage_type'] ?? 'minio'),
        ':minio_url' => $minioUrl,
        ':object_key' => $objectKey,
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO email_files_map (
            source,
            email_log_id,
            file_name,
            file_type,
            mime_type,
            file_size,
            file_hash,
            storage_type,
            minio_url,
            object_key,
            created_at,
            updated_at
        ) VALUES (
            :source,
            :email_log_id,
            :file_name,
            :file_type,
            :mime_type,
            :file_size,
            :file_hash,
            :storage_type,
            :minio_url,
            :object_key,
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            file_name = VALUES(file_name),
            mime_type = VALUES(mime_type),
            file_size = VALUES(file_size),
            storage_type = VALUES(storage_type),
            minio_url = VALUES(minio_url),
            object_key = VALUES(object_key),
            updated_at = NOW()'
    );

    try {
        $stored = $stmt->execute($params);
    } catch (PDOException $e) {
        $lost = function_exists('pdo_connection_is_lost')
            ? pdo_connection_is_lost($e)
            : (
                (int) ($e->errorInfo[1] ?? 0) === 2006
                || (int) ($e->errorInfo[1] ?? 0) === 2013
                || stripos($e->getMessage(), 'server has gone away') !== false
                || stripos($e->getMessage(), 'lost connection') !== false
            );
        if (!$lost || !function_exists('get_pdo')) {
            throw $e;
        }

        $pdo = get_pdo();
        email_minio_ensure_mapping_table($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO email_files_map (
                source,
                email_log_id,
                file_name,
                file_type,
                mime_type,
                file_size,
                file_hash,
                storage_type,
                minio_url,
                object_key,
                created_at,
                updated_at
            ) VALUES (
                :source,
                :email_log_id,
                :file_name,
                :file_type,
                :mime_type,
                :file_size,
                :file_hash,
                :storage_type,
                :minio_url,
                :object_key,
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                file_name = VALUES(file_name),
                mime_type = VALUES(mime_type),
                file_size = VALUES(file_size),
                storage_type = VALUES(storage_type),
                minio_url = VALUES(minio_url),
                object_key = VALUES(object_key),
                updated_at = NOW()'
        );
        $stored = $stmt->execute($params);
    }
    if ($stored) {
        email_minio_audit_event([
            'event_type' => 'mapping.upserted',
            'source' => $source,
            'email_log_id' => $emailId,
            'file_type' => $fileType,
            'file_hash' => $fileHash,
            'object_key' => $objectKey,
            'meta' => [
                'file_size' => max(0, (int) ($metadata['file_size'] ?? 0)),
                'mime_type' => (string) ($metadata['mime_type'] ?? 'application/octet-stream'),
            ],
        ], $pdo);
    }

    return $stored;
}

function email_minio_source(string $source): string
{
    $source = strtolower(trim($source));

    return in_array($source, ['incoming', 'outgoing'], true) ? $source : '';
}

function email_minio_canonical_uri(string $bucket, string $objectKey): string
{
    $segments = array_map('rawurlencode', array_filter(explode('/', trim($objectKey, '/')), 'strlen'));

    return '/' . rawurlencode($bucket) . '/' . implode('/', $segments);
}

function email_minio_public_url(string $objectKey): string
{
    $config = email_minio_config();
    $objectKey = implode('/', array_map('rawurlencode', array_filter(explode('/', trim($objectKey, '/')), 'strlen')));

    if ($config['public_base_url'] !== '') {
        return $config['public_base_url'] . '/' . $objectKey;
    }

    return $config['endpoint'] . '/' . rawurlencode($config['bucket']) . '/' . $objectKey;
}

function email_minio_signing_key(string $secretKey, string $dateStamp, string $region): string
{
    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);

    return hash_hmac('sha256', 'aws4_request', $kService, true);
}

function email_minio_signed_headers(string $method, string $objectKey, string $payloadHash): array
{
    $config = email_minio_config();
    if ($config['endpoint'] === '' || $config['access_key'] === '' || $config['secret_key'] === '') {
        throw new RuntimeException('MinIO is enabled but MINIO_ENDPOINT, MINIO_ACCESS_KEY, or MINIO_SECRET_KEY is missing.');
    }

    $parts = parse_url($config['endpoint']);
    $host = (string) ($parts['host'] ?? '');
    if ($host === '') {
        throw new RuntimeException('Invalid MINIO_ENDPOINT.');
    }
    if (!empty($parts['port'])) {
        $host .= ':' . (string) $parts['port'];
    }

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $credentialScope = $dateStamp . '/' . $config['region'] . '/s3/aws4_request';
    $canonicalUri = email_minio_canonical_uri($config['bucket'], $objectKey);
    $canonicalHeaders = 'host:' . $host . "\n"
        . 'x-amz-content-sha256:' . $payloadHash . "\n"
        . 'x-amz-date:' . $amzDate . "\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = strtoupper($method) . "\n"
        . $canonicalUri . "\n"
        . "\n"
        . $canonicalHeaders . "\n"
        . $signedHeaders . "\n"
        . $payloadHash;
    $stringToSign = 'AWS4-HMAC-SHA256' . "\n"
        . $amzDate . "\n"
        . $credentialScope . "\n"
        . hash('sha256', $canonicalRequest);
    $signature = hash_hmac(
        'sha256',
        $stringToSign,
        email_minio_signing_key($config['secret_key'], $dateStamp, $config['region'])
    );

    return [
        'url' => $config['endpoint'] . $canonicalUri,
        'ssl_verify' => (bool) $config['ssl_verify'],
        'headers' => [
            'Authorization: AWS4-HMAC-SHA256 Credential=' . $config['access_key'] . '/' . $credentialScope
                . ', SignedHeaders=' . $signedHeaders
                . ', Signature=' . $signature,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
            'Host: ' . $host,
        ],
    ];
}

function email_minio_http_request(string $method, string $objectKey, string $body = '', ?string $filePath = null, string $mime = 'application/octet-stream'): string
{
    $payloadHash = $filePath !== null ? (hash_file('sha256', $filePath) ?: '') : hash('sha256', $body);
    if ($payloadHash === '') {
        throw new RuntimeException('Unable to hash file for MinIO request.');
    }

    $signed = email_minio_signed_headers($method, $objectKey, $payloadHash);
    $headers = $signed['headers'];
    if (strtoupper($method) === 'PUT') {
        $headers[] = 'Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream');
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($signed['url']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $signed['ssl_verify']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $signed['ssl_verify'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        if ($filePath !== null) {
            $fh = fopen($filePath, 'rb');
            if (!$fh) {
                throw new RuntimeException('Unable to open file for MinIO upload.');
            }
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $fh);
            curl_setopt($ch, CURLOPT_INFILESIZE, (int) filesize($filePath));
        } elseif ($body !== '' || strtoupper($method) === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if (isset($fh) && is_resource($fh)) {
            fclose($fh);
        }
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('MinIO ' . strtoupper($method) . ' failed: HTTP ' . $status . ($error !== '' ? ' - ' . $error : ''));
        }

        return (string) $response;
    }

    $content = $filePath !== null ? file_get_contents($filePath) : $body;
    if ($content === false) {
        throw new RuntimeException('Unable to read file for MinIO request.');
    }
    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'timeout' => 60,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => $signed['ssl_verify'],
            'verify_peer_name' => $signed['ssl_verify'],
        ],
    ]);
    $response = file_get_contents($signed['url'], false, $context);
    $statusLine = $http_response_header[0] ?? '';
    $status = preg_match('/\s(\d{3})\s/', $statusLine, $m) ? (int) $m[1] : 0;
    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('MinIO ' . strtoupper($method) . ' failed: HTTP ' . $status);
    }

    return (string) $response;
}

function uploadToMinio(string $filePath, string $path): string
{
    if (!email_minio_enabled()) {
        return '';
    }

    if (!is_readable($filePath)) {
        throw new RuntimeException('MinIO upload file is not readable.');
    }

    $objectKey = trim(str_replace('\\', '/', $path), '/');
    if ($objectKey === '') {
        throw new RuntimeException('MinIO object path is required.');
    }

    $mime = email_minio_detect_mime($filePath);
    email_minio_http_request('PUT', $objectKey, '', $filePath, $mime);

    return email_minio_public_url($objectKey);
}

function email_minio_download_object(string $objectKey): string
{
    $objectKey = trim(str_replace('\\', '/', $objectKey), '/');
    if ($objectKey === '') {
        return '';
    }

    return email_minio_http_request('GET', $objectKey);
}

function email_minio_download_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $data = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return is_string($data) && $status >= 200 && $status < 300 ? $data : '';
    }

    $data = @file_get_contents($url);

    return is_string($data) ? $data : '';
}

function email_minio_file_mappings_for_email(PDO $pdo, string $source, int $emailId, string $fileType = ''): array
{
    $source = email_minio_source($source);
    if ($source === '' || $emailId <= 0 || !email_minio_enabled()) {
        return [];
    }

    email_minio_ensure_mapping_table($pdo);
    $params = [
        ':source' => $source,
        ':email_log_id' => $emailId,
    ];
    $where = 'source = :source AND email_log_id = :email_log_id AND storage_type = :storage_type';
    $params[':storage_type'] = 'minio';
    if (in_array($fileType, ['inline', 'attachment', 'raw'], true)) {
        $where .= ' AND file_type = :file_type';
        $params[':file_type'] = $fileType;
    }

    $stmt = $pdo->prepare(
        'SELECT id, source, email_log_id, file_name, file_type, mime_type, file_size, file_hash, minio_url, object_key
         FROM email_files_map
         WHERE ' . $where . '
         ORDER BY id ASC'
    );
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function email_minio_attachment_preview_items(PDO $pdo, string $source, int $emailId): array
{
    $items = [];
    $idx = 0;
    foreach (email_minio_file_mappings_for_email($pdo, $source, $emailId, 'attachment') as $row) {
        $items[] = [
            'index' => $idx,
            'name' => (string) ($row['file_name'] ?? 'attachment'),
            'mime' => (string) ($row['mime_type'] ?? 'application/octet-stream'),
            'size' => (int) ($row['file_size'] ?? 0),
            'disposition' => 'attachment',
            'url' => (string) ($row['minio_url'] ?? ''),
            'storage_type' => 'minio',
        ];
        $idx++;
    }

    return $items;
}

function email_minio_read_mapped_file(PDO $pdo, string $source, int $emailId, string $fileName = '', string $url = ''): string
{
    $source = email_minio_source($source);
    if ($source === '' || $emailId <= 0 || !email_minio_enabled()) {
        return '';
    }

    email_minio_ensure_mapping_table($pdo);
    $params = [
        ':source' => $source,
        ':email_log_id' => $emailId,
    ];
    $where = 'source = :source AND email_log_id = :email_log_id';
    if ($fileName !== '') {
        $where .= ' AND file_name = :file_name';
        $params[':file_name'] = $fileName;
    }
    if ($url !== '') {
        $where .= ' AND minio_url = :minio_url';
        $params[':minio_url'] = $url;
    }

    $stmt = $pdo->prepare(
        'SELECT file_type, file_hash, object_key, minio_url
         FROM email_files_map
         WHERE ' . $where . '
         ORDER BY id ASC
         LIMIT 1'
    );
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return '';
    }

    try {
        $binary = email_minio_download_object((string) ($row['object_key'] ?? ''));
        if ($binary !== '') {
            $binary = email_minio_decompress_binary($binary, (string) ($row['object_key'] ?? ''));
            email_minio_audit_event([
                'event_type' => 'object.downloaded',
                'source' => $source,
                'email_log_id' => $emailId,
                'file_type' => (string) ($row['file_type'] ?? ''),
                'file_hash' => (string) ($row['file_hash'] ?? ''),
                'object_key' => (string) ($row['object_key'] ?? ''),
                'meta' => [
                    'file_name' => $fileName,
                    'bytes' => strlen($binary),
                    'decompressed' => email_minio_is_compressed_object_key((string) ($row['object_key'] ?? '')),
                ],
            ], $pdo);

            return $binary;
        }
    } catch (Throwable $ignored) {
        // Fall back to public URL below.
    }

    $binary = email_minio_download_url((string) ($row['minio_url'] ?? ''));
    if ($binary !== '') {
        $binary = email_minio_decompress_binary($binary, (string) ($row['object_key'] ?? ''));
        email_minio_audit_event([
            'event_type' => 'object.downloaded_url_fallback',
            'source' => $source,
            'email_log_id' => $emailId,
            'file_type' => (string) ($row['file_type'] ?? ''),
            'file_hash' => (string) ($row['file_hash'] ?? ''),
            'object_key' => (string) ($row['object_key'] ?? ''),
            'meta' => [
                'file_name' => $fileName,
                'bytes' => strlen($binary),
                'decompressed' => email_minio_is_compressed_object_key((string) ($row['object_key'] ?? '')),
            ],
        ], $pdo);
    }

    return $binary;
}

function email_minio_store_file_from_path(
    PDO $pdo,
    string $source,
    int $emailId,
    string $filePath,
    string $fileName,
    string $mime,
    string $fileType
): array {
    if (!email_minio_enabled()) {
        return [];
    }

    $source = email_minio_source($source);
    $fileType = email_minio_file_type($fileType);
    if ($source === '' || $emailId <= 0 || !is_readable($filePath)) {
        return [];
    }

    email_minio_ensure_mapping_table($pdo);
    $cleanup = [];
    $detectedMime = email_minio_detect_mime($filePath, $mime !== '' ? $mime : 'application/octet-stream');
    $workingPath = $filePath;
    if (email_minio_is_image_mime($detectedMime)) {
        if (extension_loaded('gd')) {
            $originalSize = (int) (@filesize($filePath) ?: 0);
            $compressed = compressImage($filePath, 82);
            if ($compressed !== $filePath && is_readable($compressed)) {
                $workingPath = $compressed;
                $cleanup[] = $compressed;
                $detectedMime = email_minio_detect_mime($workingPath, email_minio_output_image_mime());
            }
            $workingSize = (int) (@filesize($workingPath) ?: 0);
            if ($originalSize > email_minio_image_target_max_bytes()
                && ($workingPath === $filePath || $workingSize > email_minio_image_target_max_bytes())
            ) {
                throw new RuntimeException('Image compression failed; refusing to upload original heavy image to MinIO.');
            }
        } else {
            error_log('[MinIO email] GD extension missing; uploading original image without compression.');
        }
    }

    $hash = generateFileHash($workingPath);
    if ($hash === '') {
        return [];
    }

    $detectedMime = email_minio_detect_mime($workingPath, $detectedMime);
    $storedName = email_minio_rename_for_mime($fileName, $detectedMime);
    $storagePath = $workingPath;
    $storageMime = $detectedMime;
    $objectName = $storedName;
    $compressionMeta = [];

    if ($fileType === 'attachment' && !email_minio_is_image_mime($detectedMime)) {
        $compressedFile = email_minio_compress_file_to_temp($workingPath, $detectedMime);
        if ($compressedFile !== []) {
            $storagePath = (string) $compressedFile['path'];
            $storageMime = (string) ($compressedFile['mime'] ?? 'application/gzip');
            $objectName = $storedName . '.' . (string) ($compressedFile['extension'] ?? 'gz');
            $cleanup[] = $storagePath;
            $compressionMeta = [
                'compression' => (string) ($compressedFile['algorithm'] ?? ''),
                'original_size' => (int) ($compressedFile['original_size'] ?? 0),
                'compressed_size' => (int) ($compressedFile['compressed_size'] ?? 0),
            ];
        }
    }

    $objectKey = email_minio_object_key($fileType, $emailId, $hash, $storageMime, $objectName);
    if ($objectKey === '') {
        return [];
    }

    $duplicate = isDuplicate($hash, $pdo);
    $duplicateObjectKey = $duplicate ? (string) ($duplicate['object_key'] ?? '') : '';
    $needsCompressedDuplicate = $compressionMeta !== [];
    $duplicateReusable = $duplicate
        && !empty($duplicate['minio_url'])
        && email_minio_is_flat_object_key($duplicateObjectKey, $fileType)
        && (!$needsCompressedDuplicate || email_minio_is_compressed_object_key($duplicateObjectKey));
    if ($duplicateReusable) {
        $minioUrl = (string) $duplicate['minio_url'];
        $objectKey = $duplicateObjectKey !== '' ? $duplicateObjectKey : $objectKey;
        email_minio_audit_event([
            'event_type' => 'object.duplicate_reused',
            'source' => $source,
            'email_log_id' => $emailId,
            'file_type' => $fileType,
            'file_hash' => $hash,
            'object_key' => $objectKey,
            'meta' => [
                'mime_type' => $detectedMime,
                'storage_mime_type' => $storageMime,
                'duplicate_size' => (int) ($duplicate['file_size'] ?? 0),
                'reused_compressed_object' => email_minio_is_compressed_object_key($objectKey),
            ],
        ], $pdo);
    } else {
        if ($duplicate && $duplicateObjectKey !== '') {
            email_minio_audit_event([
                'event_type' => email_minio_is_flat_object_key($duplicateObjectKey, $fileType)
                    ? 'object.uncompressed_duplicate_ignored'
                    : 'object.legacy_duplicate_ignored',
                'source' => $source,
                'email_log_id' => $emailId,
                'file_type' => $fileType,
                'file_hash' => $hash,
                'object_key' => $duplicateObjectKey,
                'meta' => [
                    'target_object_key' => $objectKey,
                ],
            ], $pdo);
        }

        try {
            $minioUrl = uploadToMinio($storagePath, $objectKey);
            email_minio_audit_event([
                'event_type' => 'object.uploaded',
                'source' => $source,
                'email_log_id' => $emailId,
                'file_type' => $fileType,
                'file_hash' => $hash,
                'object_key' => $objectKey,
                'meta' => [
                    'mime_type' => $detectedMime,
                    'storage_mime_type' => $storageMime,
                    'file_size' => (int) (@filesize($storagePath) ?: 0),
                ] + $compressionMeta,
            ], $pdo);
        } catch (Throwable $error) {
            email_minio_audit_event([
                'event_type' => 'object.upload_failed',
                'source' => $source,
                'email_log_id' => $emailId,
                'file_type' => $fileType,
                'file_hash' => $hash,
                'object_key' => $objectKey,
                'status' => 'failed',
                'error_message' => $error->getMessage(),
            ], $pdo);
            throw $error;
        }
    }

    $size = (int) (@filesize($storagePath) ?: 0);
    storeFileMapping([
        'source' => $source,
        'email_log_id' => $emailId,
        'file_name' => $storedName,
        'file_type' => $fileType,
        'mime_type' => $detectedMime,
        'file_size' => $size,
        'file_hash' => $hash,
        'storage_type' => 'minio',
        'minio_url' => $minioUrl,
        'object_key' => $objectKey,
    ], $pdo);

    return [
        'name' => $storedName,
        'mime' => $detectedMime,
        'size' => $size,
        'hash' => $hash,
        'url' => $minioUrl,
        'path' => $minioUrl,
        'object_key' => $objectKey,
        'local_path' => $workingPath,
        'cleanup_paths' => $cleanup,
        'compression' => (string) ($compressionMeta['compression'] ?? ''),
        'storage_type' => 'minio',
    ];
}

function email_minio_write_binary_to_temp(string $binary, string $mime, string $name = ''): string
{
    $ext = email_minio_mime_extension($mime, $name);
    $path = email_minio_temp_file($ext);
    if (file_put_contents($path, $binary, LOCK_EX) === false) {
        @unlink($path);
        throw new RuntimeException('Unable to write temporary email file.');
    }

    return $path;
}

function email_minio_store_binary(
    PDO $pdo,
    string $source,
    int $emailId,
    string $binary,
    string $fileName,
    string $mime,
    string $fileType
): array {
    if ($binary === '') {
        return [];
    }

    $tmp = email_minio_write_binary_to_temp($binary, $mime, $fileName);
    $cleanupPaths = [];
    try {
        $stored = email_minio_store_file_from_path($pdo, $source, $emailId, $tmp, $fileName, $mime, $fileType);
        $cleanupPaths = (array) ($stored['cleanup_paths'] ?? []);

        return $stored;
    } finally {
        @unlink($tmp);
        foreach (array_unique(array_filter($cleanupPaths)) as $cleanupPath) {
            if (is_string($cleanupPath) && $cleanupPath !== $tmp && is_file($cleanupPath)) {
                @unlink($cleanupPath);
            }
        }
    }
}

function email_minio_compress_raw_message_to_temp(string $rawMessage): array
{
    if ($rawMessage === '') {
        return [];
    }

    $algorithm = '';
    $compressed = '';
    $fileName = '';
    $mime = '';
    $extension = '';

    if (function_exists('zstd_compress')) {
        try {
            $zstd = zstd_compress($rawMessage, 10);
            if (is_string($zstd) && $zstd !== '') {
                $algorithm = 'zstd';
                $compressed = $zstd;
                $fileName = 'message.eml.zst';
                $mime = 'application/zstd';
                $extension = 'zst';
            }
        } catch (Throwable $ignored) {
            $compressed = '';
        }
    }

    if ($compressed === '') {
        if (!function_exists('gzencode')) {
            throw new RuntimeException('Raw email compression requires zstd or gzip support.');
        }

        $gzip = gzencode($rawMessage, 6, FORCE_GZIP);
        if (!is_string($gzip) || $gzip === '') {
            throw new RuntimeException('Unable to gzip-compress raw email archive.');
        }

        $algorithm = 'gzip';
        $compressed = $gzip;
        $fileName = 'message.eml.gz';
        $mime = 'application/gzip';
        $extension = 'gz';
    }

    $tmp = email_minio_temp_file($extension);
    if (file_put_contents($tmp, $compressed, LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('Unable to write compressed raw email archive.');
    }

    return [
        'path' => $tmp,
        'file_name' => $fileName,
        'mime' => $mime,
        'algorithm' => $algorithm,
        'original_size' => strlen($rawMessage),
        'compressed_size' => strlen($compressed),
    ];
}

function email_minio_compact_raw_message_for_archive(string $rawMessage): string
{
    $rawMessage = (string) $rawMessage;
    if (trim($rawMessage) === '') {
        return '';
    }

    return email_minio_compact_mime_part_for_archive($rawMessage, true);
}

function email_minio_compact_mime_part_for_archive(string $rawPart, bool $isRoot = false): string
{
    [$headerBlock, $bodyBlock] = array_pad(preg_split("/\r\n\r\n|\n\n|\r\r/", $rawPart, 2), 2, '');
    $headers = email_minio_parse_headers((string) $headerBlock);
    $contentTypeHeader = (string) ($headers['content-type'] ?? 'text/plain');
    $contentType = email_minio_header_main_value($contentTypeHeader);
    $disposition = strtolower((string) ($headers['content-disposition'] ?? ''));
    $filename = email_minio_header_param($disposition, 'filename');
    if ($filename === '') {
        $filename = email_minio_header_param($contentTypeHeader, 'name');
    }

    if (str_starts_with($contentType, 'multipart/')) {
        $boundary = email_minio_header_param($contentTypeHeader, 'boundary');
        if ($boundary === '') {
            return $headerBlock . "\r\n\r\n" . $bodyBlock;
        }

        $pieces = explode('--' . $boundary, (string) $bodyBlock);
        $out = (string) array_shift($pieces);
        foreach ($pieces as $piece) {
            $trimmedLeft = ltrim($piece, "\r\n");
            if (str_starts_with($trimmedLeft, '--')) {
                $out .= '--' . $boundary . '--' . "\r\n";
                break;
            }

            $partRaw = ltrim($piece, "\r\n");
            $out .= '--' . $boundary . "\r\n";
            $out .= email_minio_compact_mime_part_for_archive($partRaw, false);
            if (!str_ends_with($out, "\r\n")) {
                $out .= "\r\n";
            }
        }

        return $headerBlock . "\r\n\r\n" . $out;
    }

    $isTextBody = in_array($contentType, ['text/plain', 'text/html'], true)
        && strpos($disposition, 'attachment') === false;
    $looksExternalFile = !$isRoot
        && !$isTextBody
        && (
            str_starts_with($contentType, 'image/')
            || strpos($disposition, 'attachment') !== false
            || $filename !== ''
        );

    if ($looksExternalFile) {
        $placeholder = '[MinIO archive compacted: MIME payload omitted; stored separately in email_files_map.'
            . ' content_type=' . ($contentType !== '' ? $contentType : 'application/octet-stream')
            . ($filename !== '' ? ' filename=' . $filename : '')
            . ' original_encoded_bytes=' . strlen((string) $bodyBlock)
            . ']';

        return $headerBlock . "\r\n\r\n" . $placeholder . "\r\n";
    }

    if ($contentType === 'text/html' && stripos((string) $bodyBlock, 'data:image/') !== false) {
        $bodyBlock = preg_replace(
            '#data:image/[a-zA-Z0-9.+-]+;base64,[a-zA-Z0-9+/=\r\n\t ]+#',
            'data:image/omitted;base64,[MinIO archive compacted inline image]',
            (string) $bodyBlock
        ) ?? $bodyBlock;
    }

    return $headerBlock . "\r\n\r\n" . $bodyBlock;
}

function email_minio_store_raw_message(PDO $pdo, string $source, int $emailId, string $rawMessage): array
{
    if (!email_minio_enabled() || $emailId <= 0 || trim($rawMessage) === '') {
        return [];
    }

    $source = email_minio_source($source);
    if ($source === '') {
        return [];
    }

    email_minio_ensure_mapping_table($pdo);
    $archive = [];
    $tmp = '';
    try {
        $sourceRawHash = hash('sha256', $rawMessage);
        $archiveMessage = email_minio_compact_raw_message_for_archive($rawMessage);
        if (trim($archiveMessage) === '') {
            return [];
        }

        $hash = hash('sha256', $archiveMessage);
        if ($hash === '') {
            return [];
        }

        $archive = email_minio_compress_raw_message_to_temp($archiveMessage);
        $tmp = (string) ($archive['path'] ?? '');
        if ($tmp === '' || !is_readable($tmp)) {
            throw new RuntimeException('Compressed raw email archive is not readable.');
        }

        $archiveName = 'message.compact.eml.' . ((string) ($archive['algorithm'] ?? 'gzip') === 'zstd' ? 'zst' : 'gz');
        $archiveMime = (string) ($archive['mime'] ?? 'application/gzip');
        $objectKey = email_minio_object_key('raw', $emailId, $hash, $archiveMime, $archiveName);
        if ($objectKey === '') {
            return [];
        }

        $duplicate = isDuplicate($hash, $pdo);
        $duplicateObjectKey = $duplicate ? (string) ($duplicate['object_key'] ?? '') : '';
        if ($duplicate && !empty($duplicate['minio_url']) && email_minio_is_compressed_raw_object_key($duplicateObjectKey)) {
            $minioUrl = (string) $duplicate['minio_url'];
            $objectKey = $duplicateObjectKey !== '' ? $duplicateObjectKey : $objectKey;
            email_minio_audit_event([
                'event_type' => 'raw.duplicate_reused',
                'source' => $source,
                'email_log_id' => $emailId,
                'file_type' => 'raw',
                'file_hash' => $hash,
                'object_key' => $objectKey,
            ], $pdo);
        } else {
            if ($duplicate && $duplicateObjectKey !== '' && !email_minio_is_compressed_raw_object_key($duplicateObjectKey)) {
                email_minio_audit_event([
                    'event_type' => 'raw.legacy_duplicate_ignored',
                    'source' => $source,
                    'email_log_id' => $emailId,
                    'file_type' => 'raw',
                    'file_hash' => $hash,
                    'object_key' => $duplicateObjectKey,
                    'meta' => [
                        'target_object_key' => $objectKey,
                    ],
                ], $pdo);
            }

            $minioUrl = uploadToMinio($tmp, $objectKey);
            email_minio_audit_event([
                'event_type' => 'raw.uploaded',
                'source' => $source,
                'email_log_id' => $emailId,
                'file_type' => 'raw',
                'file_hash' => $hash,
                'object_key' => $objectKey,
                'meta' => [
                    'file_size' => (int) (@filesize($tmp) ?: 0),
                    'mime_type' => $archiveMime,
                    'compression' => (string) ($archive['algorithm'] ?? ''),
                    'source_raw_size' => strlen($rawMessage),
                    'source_raw_hash' => $sourceRawHash,
                    'archive_uncompressed_size' => (int) ($archive['original_size'] ?? 0),
                    'compressed_size' => (int) ($archive['compressed_size'] ?? 0),
                ],
            ], $pdo);
        }

        $size = (int) (@filesize($tmp) ?: 0);
        storeFileMapping([
            'source' => $source,
            'email_log_id' => $emailId,
            'file_name' => $archiveName,
            'file_type' => 'raw',
            'mime_type' => $archiveMime,
            'file_size' => $size,
            'file_hash' => $hash,
            'storage_type' => 'minio',
            'minio_url' => $minioUrl,
            'object_key' => $objectKey,
        ], $pdo);

        return [
            'name' => $archiveName,
            'mime' => $archiveMime,
            'size' => $size,
            'hash' => $hash,
            'url' => $minioUrl,
            'object_key' => $objectKey,
            'compression' => (string) ($archive['algorithm'] ?? ''),
            'storage_type' => 'minio',
        ];
    } catch (Throwable $error) {
        email_minio_audit_event([
            'event_type' => 'raw.upload_failed',
            'source' => $source,
            'email_log_id' => $emailId,
            'file_type' => 'raw',
            'status' => 'failed',
            'error_message' => $error->getMessage(),
        ], $pdo);
        throw $error;
    } finally {
        if ($tmp !== '') {
            @unlink($tmp);
        }
    }
}

function email_minio_decode_data_uri_at(string $html, int $index): ?array
{
    if ($index < 0 || stripos($html, 'data:image/') === false) {
        return null;
    }

    $current = 0;
    $searchFrom = 0;
    $needle = 'data:image/';

    while (($dataPos = stripos($html, $needle, $searchFrom)) !== false) {
        $quotePos = $dataPos - 1;
        while ($quotePos >= 0 && ($html[$quotePos] === ' ' || $html[$quotePos] === "\t" || $html[$quotePos] === '=')) {
            $quotePos--;
        }

        $quote = $html[$quotePos] ?? '';
        if ($quote !== '"' && $quote !== "'") {
            $searchFrom = $dataPos + strlen($needle);
            continue;
        }

        $endQuote = strpos($html, $quote, $dataPos);
        if ($endQuote === false) {
            break;
        }

        if ($current === $index) {
            $payload = substr($html, $dataPos, $endQuote - $dataPos);
            if (!preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,(.+)$#is', $payload, $m)) {
                return null;
            }

            $subtype = strtolower(preg_replace('/[^a-z0-9.+-]/', '', (string) $m[1]) ?: 'png');
            $b64 = preg_replace('/\s+/', '', (string) $m[2]) ?? '';
            $decoded = base64_decode($b64, true);
            if ($decoded === false || $decoded === '') {
                return null;
            }

            $ext = $subtype === 'jpeg' ? 'jpg' : ($subtype === 'webp' ? 'webp' : 'png');

            return [
                'mime' => 'image/' . $subtype,
                'data' => $decoded,
                'filename' => 'inline-' . $index . '.' . $ext,
                'quote_pos' => $quotePos,
                'end_quote' => $endQuote,
            ];
        }

        $current++;
        $searchFrom = $endQuote + 1;
    }

    return null;
}

function email_minio_replace_data_uris_with_urls(PDO $pdo, string $source, int $emailId, string $html): array
{
    $meta = ['version' => 1, 'storage' => 'minio', 'assets' => []];
    if (!email_minio_enabled() || $emailId <= 0 || stripos($html, 'data:image/') === false) {
        return ['html' => $html, 'meta' => $meta];
    }

    $idx = 0;
    $searchFrom = 0;
    $needle = 'data:image/';

    while (($dataPos = stripos($html, $needle, $searchFrom)) !== false) {
        $decoded = email_minio_decode_data_uri_at($html, 0);
        if ($decoded === null) {
            $searchFrom = $dataPos + strlen($needle);
            $idx++;
            continue;
        }

        $decoded['filename'] = 'inline-' . $idx . '.' . email_minio_mime_extension((string) $decoded['mime']);

        try {
            $stored = email_minio_store_binary(
                $pdo,
                $source,
                $emailId,
                (string) $decoded['data'],
                (string) $decoded['filename'],
                (string) $decoded['mime'],
                'inline'
            );
        } catch (Throwable $error) {
            error_log('[MinIO email] data URI inline upload failed: ' . $error->getMessage());
            $searchFrom = $dataPos + strlen($needle);
            $idx++;
            continue;
        }

        $url = (string) ($stored['url'] ?? '');
        if ($url === '') {
            $searchFrom = $dataPos + strlen($needle);
            $idx++;
            continue;
        }

        $quotePos = (int) $decoded['quote_pos'];
        $endQuote = (int) $decoded['end_quote'];
        $html = substr($html, 0, $quotePos + 1) . $url . substr($html, $endQuote);
        $searchFrom = $quotePos + 1 + strlen($url);
        $meta['assets'][] = [
            'index' => $idx,
            'url' => $url,
            'mime' => (string) ($stored['mime'] ?? $decoded['mime']),
            'size' => (int) ($stored['size'] ?? 0),
            'hash' => (string) ($stored['hash'] ?? ''),
        ];
        $idx++;
    }

    return ['html' => $html, 'meta' => $meta];
}

function email_minio_parse_headers(string $headerBlock): array
{
    $headers = [];
    $current = null;

    foreach (preg_split("/\r\n|\n|\r/", $headerBlock) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
            $headers[$current] .= ' ' . trim($line);
            continue;
        }
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $current = strtolower(trim($name));
        if ($current !== '') {
            $headers[$current] = trim($value);
        }
    }

    return $headers;
}

function email_minio_header_main_value(string $value): string
{
    return strtolower(trim(explode(';', $value, 2)[0]));
}

function email_minio_header_param(string $value, string $name): string
{
    if (!preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*(?:"((?:\\\\.|[^"])*)"|([^;\s]+))/i', $value, $matches)) {
        return '';
    }

    $paramValue = isset($matches[1]) && $matches[1] !== ''
        ? stripcslashes($matches[1])
        : (string) ($matches[2] ?? '');

    return trim($paramValue, "\"' \t\r\n");
}

function email_minio_decode_part_body(array $node): string
{
    $body = (string) ($node['body'] ?? '');
    $encoding = strtolower(trim((string) ($node['encoding'] ?? '')));
    if ($encoding === 'base64') {
        $compact = preg_replace('/\s+/', '', $body) ?? $body;
        $decoded = base64_decode($compact, true);

        return $decoded !== false ? $decoded : '';
    }
    if ($encoding === 'quoted-printable') {
        return quoted_printable_decode($body);
    }

    return $body;
}

function email_minio_build_mime_node(array $headers, string $bodyBlock): array
{
    $contentTypeHeader = (string) ($headers['content-type'] ?? 'text/plain');
    $contentType = email_minio_header_main_value($contentTypeHeader);
    $dispositionHeader = (string) ($headers['content-disposition'] ?? '');
    $disposition = strtolower(trim(explode(';', $dispositionHeader, 2)[0] ?? ''));
    $filename = email_minio_header_param($dispositionHeader, 'filename');
    if ($filename === '') {
        $filename = email_minio_header_param($contentTypeHeader, 'name');
    }

    $node = [
        'headers' => $headers,
        'content_type' => $contentType,
        'charset' => email_minio_header_param($contentTypeHeader, 'charset'),
        'encoding' => (string) ($headers['content-transfer-encoding'] ?? ''),
        'content_id' => trim((string) ($headers['content-id'] ?? ''), " <>\t\r\n"),
        'disposition' => $disposition,
        'filename' => $filename,
        'body' => '',
        'parts' => [],
    ];

    if (str_starts_with($contentType, 'multipart/')) {
        $boundary = email_minio_header_param($contentTypeHeader, 'boundary');
        if ($boundary === '') {
            $node['body'] = $bodyBlock;

            return $node;
        }

        $segments = explode('--' . $boundary, $bodyBlock);
        array_shift($segments);
        foreach ($segments as $segment) {
            $segment = ltrim($segment, "\r\n");
            if ($segment === '' || str_starts_with($segment, '--')) {
                continue;
            }
            [$partHeaders, $partBody] = array_pad(preg_split("/\r\n\r\n|\n\n|\r\r/", $segment, 2), 2, '');
            $node['parts'][] = email_minio_build_mime_node(email_minio_parse_headers((string) $partHeaders), (string) $partBody);
        }

        return $node;
    }

    $node['body'] = $bodyBlock;

    return $node;
}

function email_minio_parse_mime_message(string $rawMessage): array
{
    [$headerBlock, $bodyBlock] = array_pad(preg_split("/\r\n\r\n|\n\n|\r\r/", $rawMessage, 2), 2, '');

    return email_minio_build_mime_node(email_minio_parse_headers((string) $headerBlock), (string) $bodyBlock);
}

function email_minio_find_html_body(array $node): ?array
{
    $disposition = (string) ($node['disposition'] ?? '');
    if (($node['content_type'] ?? '') === 'text/html' && strpos($disposition, 'attachment') === false) {
        return $node;
    }

    foreach ($node['parts'] ?? [] as $part) {
        $found = email_minio_find_html_body($part);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

function email_minio_extract_cids_from_html(string $html): array
{
    $out = [];
    if (preg_match_all('/\bcid:([^"\'\s>)]+)/i', $html, $matches)) {
        foreach ($matches[1] as $cid) {
            $token = strtolower(trim((string) $cid, " <>\t\r\n"));
            if ($token !== '') {
                $out[$token] = true;
            }
        }
    }

    return $out;
}

function email_minio_normalize_cid_token(string $cid): string
{
    $cid = trim($cid, " <>\t\r\n\"'");
    if (stripos($cid, 'cid:') === 0) {
        $cid = substr($cid, 4);
    }

    return strtolower(trim($cid, " <>\t\r\n\"'"));
}

function email_minio_cid_url_lookup(array $cidMap, string $cid): string
{
    $token = email_minio_normalize_cid_token($cid);
    if ($token === '') {
        return '';
    }

    $candidates = [$token];
    if (str_contains($token, '@')) {
        $candidates[] = strstr($token, '@', true) ?: $token;
    }

    foreach ($cidMap as $key => $value) {
        $mapToken = email_minio_normalize_cid_token((string) $key);
        $url = '';
        if (is_array($value)) {
            $url = trim((string) ($value['url'] ?? $value['minio_url'] ?? ''));
        } else {
            $url = trim((string) $value);
        }
        if ($mapToken === '' || $url === '') {
            continue;
        }

        foreach ($candidates as $candidate) {
            if ($candidate === $mapToken || str_contains($candidate, $mapToken) || str_contains($mapToken, $candidate)) {
                return $url;
            }
        }
    }

    return '';
}

function resolveInlineImages(string $html, array $cidMap): string
{
    if ($html === '' || $cidMap === [] || stripos($html, 'cid:') === false) {
        return $html;
    }

    $html = preg_replace_callback(
        '/\bsrc\s*=\s*(["\'])cid:([^"\']+)\1/i',
        static function (array $m) use ($cidMap): string {
            $url = email_minio_cid_url_lookup($cidMap, (string) $m[2]);
            if ($url === '') {
                return $m[0];
            }

            return 'src=' . $m[1] . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . $m[1];
        },
        $html
    ) ?? $html;

    return preg_replace_callback(
        '/url\s*\(\s*(["\']?)cid:([^"\')\s]+)\1\s*\)/i',
        static function (array $m) use ($cidMap): string {
            $url = email_minio_cid_url_lookup($cidMap, (string) $m[2]);
            if ($url === '') {
                return $m[0];
            }

            $quote = $m[1] !== '' ? $m[1] : '"';

            return 'url(' . $quote . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . $quote . ')';
        },
        $html
    ) ?? $html;
}

function email_minio_part_is_attachment(array $node, array $cidsInHtml): bool
{
    $contentType = strtolower((string) ($node['content_type'] ?? ''));
    if (str_starts_with($contentType, 'multipart/')) {
        return false;
    }

    $disposition = strtolower((string) ($node['disposition'] ?? ''));
    $cid = strtolower(trim((string) ($node['content_id'] ?? ''), '<> '));
    $filename = trim((string) ($node['filename'] ?? ''));

    if ($cid !== '' && isset($cidsInHtml[$cid])) {
        return false;
    }

    if (strpos($disposition, 'inline') !== false && preg_match('#^image/#i', $contentType)) {
        return false;
    }

    if (strpos($disposition, 'attachment') !== false) {
        return true;
    }

    if ($filename !== '' && !in_array($contentType, ['text/plain', 'text/html'], true)) {
        return true;
    }

    return false;
}

function email_minio_collect_files_from_mime(array $node, array $cidsInHtml, array &$out): void
{
    $contentType = strtolower((string) ($node['content_type'] ?? ''));
    if (!str_starts_with($contentType, 'multipart/')) {
        $cid = strtolower(trim((string) ($node['content_id'] ?? ''), '<> '));
        $disposition = strtolower((string) ($node['disposition'] ?? ''));
        $filename = trim((string) ($node['filename'] ?? ''));
        $isImage = preg_match('#^image/#i', $contentType) === 1;
        $isInline = $isImage && ($cid !== '' || strpos($disposition, 'inline') !== false) && !email_minio_part_is_attachment($node, $cidsInHtml);
        $isAttachment = email_minio_part_is_attachment($node, $cidsInHtml);

        if ($isInline || $isAttachment) {
            $data = email_minio_decode_part_body($node);
            if ($data !== '') {
                $fileType = $isInline ? 'inline' : 'attachment';
                if ($filename === '') {
                    $filename = $fileType . '-' . (count($out) + 1) . '.' . email_minio_mime_extension($contentType);
                }
                $out[] = [
                    'file_type' => $fileType,
                    'cid' => $cid,
                    'file_name' => $filename,
                    'mime_type' => $contentType !== '' ? $contentType : 'application/octet-stream',
                    'data' => $data,
                ];
            }
        }
    }

    foreach ($node['parts'] ?? [] as $part) {
        email_minio_collect_files_from_mime($part, $cidsInHtml, $out);
    }
}

function email_minio_replace_cid_refs(string $html, array $cidUrls): string
{
    return resolveInlineImages($html, $cidUrls);
}

function email_minio_save_inbox_preview_html(PDO $pdo, int $inboxId, string $html, array $meta): void
{
    if ($inboxId <= 0 || trim($html) === '') {
        return;
    }

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM email_inbox_log')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $field = (string) ($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }
    if (!isset($columns['body_preview_html']) || !isset($columns['body_assets_meta'])) {
        return;
    }

    $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);
    if ($metaJson === false) {
        $metaJson = '{}';
    }
    $stmt = $pdo->prepare(
        'UPDATE email_inbox_log
         SET body_preview_html = :body_preview_html,
             body_assets_meta = :body_assets_meta
         WHERE id = :id'
    );
    $stmt->execute([
        ':body_preview_html' => $html,
        ':body_assets_meta' => $metaJson,
        ':id' => $inboxId,
    ]);
}

function email_minio_process_incoming_raw_message(PDO $pdo, int $inboxId, string $rawMessage): array
{
    $summary = ['files' => 0, 'inline' => 0, 'attachments' => 0, 'failed' => 0];
    if (!email_minio_enabled() || $inboxId <= 0 || trim($rawMessage) === '') {
        return $summary;
    }

    try {
        $tree = email_minio_parse_mime_message($rawMessage);
        $htmlNode = email_minio_find_html_body($tree);
        $html = $htmlNode ? email_minio_decode_part_body($htmlNode) : '';
        $cidsInHtml = email_minio_extract_cids_from_html($html);
        $files = [];
        email_minio_collect_files_from_mime($tree, $cidsInHtml, $files);

        $cidUrls = [];
        $inlineUrls = [];
        $meta = ['version' => 1, 'storage' => 'minio', 'assets' => []];
        foreach ($files as $file) {
            $stored = email_minio_store_binary(
                $pdo,
                'incoming',
                $inboxId,
                (string) $file['data'],
                (string) $file['file_name'],
                (string) $file['mime_type'],
                (string) $file['file_type']
            );
            $url = (string) ($stored['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $summary['files']++;
            if ($file['file_type'] === 'inline') {
                $summary['inline']++;
                $inlineUrls[] = ['url' => $url, 'name' => (string) $stored['name']];
                $cid = email_minio_normalize_cid_token((string) ($file['cid'] ?? ''));
                if ($cid !== '') {
                    $cidUrls[$cid] = $url;
                }
            } else {
                $summary['attachments']++;
            }

            $meta['assets'][] = [
                'name' => (string) ($stored['name'] ?? $file['file_name']),
                'url' => $url,
                'mime' => (string) ($stored['mime'] ?? $file['mime_type']),
                'size' => (int) ($stored['size'] ?? 0),
                'hash' => (string) ($stored['hash'] ?? ''),
                'type' => (string) $file['file_type'],
                'cid' => email_minio_normalize_cid_token((string) ($file['cid'] ?? '')),
            ];
        }

        if ($html !== '') {
            $html = resolveInlineImages($html, $cidUrls);
            $dataResult = email_minio_replace_data_uris_with_urls($pdo, 'incoming', $inboxId, $html);
            $html = (string) ($dataResult['html'] ?? $html);
            if (!empty($dataResult['meta']['assets']) && is_array($dataResult['meta']['assets'])) {
                $meta['assets'] = array_merge($meta['assets'], $dataResult['meta']['assets']);
            }

            $htmlLower = strtolower($html);
            $append = '';
            foreach ($inlineUrls as $inline) {
                $url = (string) ($inline['url'] ?? '');
                if ($url === '' || str_contains($htmlLower, strtolower($url))) {
                    continue;
                }
                $append .= '<figure class="elw-inline-asset"><img src="'
                    . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                    . '" alt="Inline image" loading="eager" decoding="sync"></figure>';
            }
            if ($append !== '') {
                $html .= $append;
            }

            email_minio_save_inbox_preview_html(
                $pdo,
                $inboxId,
                '<div class="elw-email-doc"><div class="elw-email-body">' . $html . '</div></div>',
                $meta
            );
        }
    } catch (Throwable $error) {
        error_log('[MinIO email] incoming message processing failed: ' . $error->getMessage());
        $summary['failed'] = 1;
    }

    return $summary;
}

function email_minio_raw_message_needs_external_storage(string $rawMessage): bool
{
    if (trim($rawMessage) === '') {
        return false;
    }

    return preg_match('/Content-Disposition:\s*attachment/im', $rawMessage) === 1
        || preg_match('/Content-Type:\s*image\//im', $rawMessage) === 1
        || stripos($rawMessage, 'data:image/') !== false;
}

function email_minio_strip_binary_parts_from_raw_message(string $rawMessage): string
{
    $rawMessage = trim($rawMessage);
    if ($rawMessage === '') {
        return '';
    }

    $headerBlock = (string) (preg_split("/\r\n\r\n|\n\n|\r\r/", $rawMessage, 2)[0] ?? '');
    $headerBlock = trim($headerBlock);

    return $headerBlock . "\r\n\r\n"
        . '[MinIO storage enabled: attachments and inline images are stored in email_files_map. Binary MIME parts removed from raw_message.]';
}
