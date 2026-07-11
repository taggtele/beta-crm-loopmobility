<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';

// Load environment variables for MinIO configuration if not already loaded
defined('APP_ROOT') || define('APP_ROOT', realpath(dirname(__DIR__)));
defined('STORAGE_PATH') || define('STORAGE_PATH', APP_ROOT . '/storage');
if (!function_exists('app_load_env')) {
    require_once APP_ROOT . '/core/env.php';
}
app_load_env(APP_ROOT . '/.env');

// Try to load Composer autoloader for MinIO SDK
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        ini_set('session.cookie_secure', $isHttps ? '1' : '0');

        session_start();
    }
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): void
    {
        header('Location: ' . url($path));
        exit;
    }
}

function user_roles(): array
{
    return ['Admin', 'Agent', 'Finance', 'Sales'];
}

// users role enum is managed by migration.
function users_ensure_role_enum(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $ready = true;
}

function user_role_badge_class(string $role): string
{
    return match ($role) {
        'Admin' => 'badge-admin',
        'Finance' => 'badge-finance',
        'Sales' => 'badge-sales',
        default => 'badge-agent',
    };
}

function user_statuses(): array
{
    return ['Active', 'Suspended'];
}

function ticket_statuses(): array
{
    return ['Open', 'In-Progress', 'Closed'];
}

function ticket_priorities(): array
{
    return ['Low', 'Medium', 'High'];
}

function active_users(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, user_id, role
         FROM users
         WHERE deleted = 0
         AND status = :status
         ORDER BY name ASC'
    );
    $stmt->execute([':status' => 'Active']);

    return $stmt->fetchAll();
}

function normalize_assignee(?string $assignTo): ?string
{
    $assignTo = trim((string) $assignTo);

    return $assignTo === '' ? null : $assignTo;
}

function ticket_is_assigned(?string $assignTo): bool
{
    return normalize_assignee($assignTo) !== null;
}

function ticket_user_can_edit(array $currentUser, array $ticket): bool
{
    if (($currentUser['role'] ?? '') === 'Admin') {
        return true;
    }

    if (($currentUser['role'] ?? '') !== 'Agent') {
        return false;
    }

    $assignTo = normalize_assignee($ticket['assign_to'] ?? null);

    return $assignTo === null || $assignTo === ($currentUser['user_id'] ?? '');
}

function ticket_user_can_change_status(array $currentUser, array $ticket): bool
{
    return rbac_can_change_ticket_status($currentUser);
}

function ticket_user_can_assign_others(array $currentUser): bool
{
    return ($currentUser['role'] ?? '') === 'Admin';
}

function ticket_user_can_self_assign(array $currentUser, array $ticket): bool
{
    return ($currentUser['role'] ?? '') === 'Agent' && !ticket_is_assigned($ticket['assign_to'] ?? null);
}

function ticket_user_can_soft_delete(array $currentUser): bool
{
    return ($currentUser['role'] ?? '') === 'Admin';
}

function ticket_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function valid_user_id(string $userId): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]{3,50}$/', $userId);
}

function set_flash(string $type, string $message): void
{
    app_session_start();
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    app_session_start();

    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function csrf_token(): string
{
    app_session_start();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    app_session_start();

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken = $_POST['csrf_token'] ?? '';

    if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
        http_response_code(400);
        exit('Invalid request. Please refresh the page and try again.');
    }
}

function current_user(PDO $pdo): ?array
{
    app_session_start();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, user_id, role, status, deleted, created_at, profile_image, phone, department
         FROM users
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['deleted'] === 1 || $user['status'] !== 'Active') {
        $_SESSION = [];
        session_destroy();
        return null;
    }

    $_SESSION['user_pk'] = (int) $user['id'];
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['role'] = $user['role'];

    return $user;
}

function user_initials(?string $name): string
{
    $name = trim((string) $name);

    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }

    return $initials !== '' ? $initials : 'U';
}

function profile_image_storage_dir(): string
{
    return __DIR__ . '/../storage/profile-images';
}

function ensure_profile_image_storage_dir(): string
{
    $directory = profile_image_storage_dir();

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    return $directory;
}

function user_profile_image_url(?string $profileImage): ?string
{
    $profileImage = trim((string) $profileImage);

    if ($profileImage === '') {
        return null;
    }

    if (str_starts_with($profileImage, 'storage/profile-images/')) {
        $filePath = __DIR__ . '/../' . $profileImage;

        if (!is_file($filePath)) {
            return null;
        }

        return url($profileImage);
    }

    return null;
}

function user_avatar_html(?array $user, string $class = 'profile-avatar', bool $decorative = false): string
{
    $user = $user ?? [];
    $imageUrl = user_profile_image_url($user['profile_image'] ?? null);
    $initials = user_initials($user['name'] ?? null);
    $ariaHidden = $decorative ? ' aria-hidden="true"' : '';
    $ariaLabel = $decorative ? '' : ' aria-label="' . e(($user['name'] ?? 'User') . ' avatar') . '"';

    if ($imageUrl !== null) {
        return sprintf(
            '<span class="%s"%s%s><img src="%s" alt="%s" class="avatar-image"></span>',
            e($class),
            $ariaHidden,
            $ariaLabel,
            e($imageUrl),
            e(($user['name'] ?? 'User') . ' profile image')
        );
    }

    return sprintf(
        '<span class="%s avatar-fallback"%s%s>%s</span>',
        e($class),
        $ariaHidden,
        $ariaLabel,
        e($initials)
    );
}

function delete_profile_image_file(?string $profileImage): void
{
    $profileImage = trim((string) $profileImage);

    if ($profileImage === '') {
        return;
    }

    if (!str_starts_with($profileImage, 'storage/profile-images/')) {
        return;
    }

    $baseDirectory = realpath(__DIR__ . '/../storage');
    $resolvedPath = realpath(__DIR__ . '/../' . $profileImage);

    if ($baseDirectory === false || $resolvedPath === false) {
        return;
    }

    if (strpos($resolvedPath, $baseDirectory . DIRECTORY_SEPARATOR . 'profile-images') !== 0) {
        return;
    }

    if (is_file($resolvedPath)) {
        unlink($resolvedPath);
    }
}

function compress_profile_image(string $filePath, int $maxWidth = 1024, int $maxHeight = 1024, int $quality = 85): string
{
    if (!is_readable($filePath) || !extension_loaded('gd')) {
        return $filePath;
    }

    $info = @getimagesize($filePath);
    if (!is_array($info) || !$info[0] || !$info[1]) {
        return $filePath;
    }

    $width = $info[0];
    $height = $info[1];
    $mime = strtolower($info['mime'] ?? '');

    // Allow only JPEG, PNG, and WebP
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return $filePath;
    }

    // Scale down if larger than max dimensions
    $scale = 1.0;
    if ($width > $maxWidth || $height > $maxHeight) {
        $scaleX = $maxWidth / $width;
        $scaleY = $maxHeight / $height;
        $scale = min($scaleX, $scaleY);
    }

    $newWidth = (int)($width * $scale);
    $newHeight = (int)($height * $scale);

    // Create image resource
    $source = null;
    if ($mime === 'image/jpeg') {
        $source = @imagecreatefromjpeg($filePath);
    } elseif ($mime === 'image/png') {
        $source = @imagecreatefrompng($filePath);
    } elseif ($mime === 'image/webp') {
        $source = @imagecreatefromwebp($filePath);
    }

    if (!$source) {
        return $filePath;
    }

    // Create destination image
    $dest = imagecreatetruecolor($newWidth, $newHeight);
    if (!$dest) {
        imagedestroy($source);
        return $filePath;
    }

    // Preserve transparency for PNG and WebP
    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 255, 255, 255, 127);
        imagefilledrectangle($dest, 0, 0, $newWidth, $newHeight, $transparent);
    }

    // Copy and resize
    imagecopyresampled($dest, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Save to temporary file
    $tempFile = tempnam(sys_get_temp_dir(), 'profile_img_');
    if ($tempFile === false) {
        imagedestroy($source);
        imagedestroy($dest);
        return $filePath;
    }

    $result = false;
    if ($mime === 'image/jpeg') {
        $result = @imagejpeg($dest, $tempFile, $quality);
    } elseif ($mime === 'image/png') {
        $result = @imagepng($dest, $tempFile, floor($quality * 9 / 100)); // PNG quality 0-9
    } elseif ($mime === 'image/webp') {
        $result = @imagewebp($dest, $tempFile, $quality);
    }

    imagedestroy($source);
    imagedestroy($dest);

    if (!$result || !is_readable($tempFile)) {
        @unlink($tempFile);
        return $filePath;
    }

    // Check if compression actually reduced file size
    $originalSize = @filesize($filePath);
    $tempSize = @filesize($tempFile);
    if ($originalSize > 0 && $tempSize >= $originalSize) {
        @unlink($tempFile);
        return $filePath;
    }

    return $tempFile;
}

function store_profile_image_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Profile image upload failed. Please try again.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > (2 * 1024 * 1024)) {
        throw new RuntimeException('Profile image must be smaller than 2 MB.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $imageInfo = @getimagesize($tmpName);
    if (!is_array($imageInfo)) {
        throw new RuntimeException('Invalid image file.');
    }

    $mime = $imageInfo['mime'] ?? '';
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedTypes[$mime])) {
        throw new RuntimeException('Only JPG, PNG, and WebP profile images are allowed.');
    }

    // Compress the image
    $compressedTmp = compress_profile_image($tmpName);
    $compressedSize = (int) (@filesize($compressedTmp) ?? 0);
    if ($compressedSize <= 0) {
        if ($compressedTmp !== $tmpName) {
            @unlink($compressedTmp);
        }
        throw new RuntimeException('Failed to process image.');
    }

    // Generate unique key for storage
    // Include timestamp, random bytes, and file extension to reduce collision chances
    $key = 'profile_' . time() . '-' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mime];

    $directory = ensure_profile_image_storage_dir();
    $destination = $directory . '/' . $key;

    if (!copy($compressedTmp, $destination)) {
        if ($compressedTmp !== $tmpName) {
            @unlink($compressedTmp);
        }
        throw new RuntimeException('Failed to save profile image.');
    }
    @chmod($destination, 0644);

    if ($compressedTmp !== $tmpName) {
        @unlink($compressedTmp);
    }

    return 'storage/profile-images/' . $key;
}

function require_login(PDO $pdo): array
{
    $user = current_user($pdo);

    if (!$user) {
        redirect('auth/login.php');
    }

    return $user;
}

function require_guest(PDO $pdo): void
{
    if (current_user($pdo)) {
        require_once __DIR__ . '/rbac.php';
        if (rbac_is_finance(current_user($pdo))) {
            redirect(rbac_finance_home_path());
        }
        redirect('dashboard/index.php');
    }
}

function require_role(array $roles): void
{
    app_session_start();

    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function ticket_scope(array $currentUser, string $alias = 't', bool $includeUnassignedForAgents = false, bool $agentsBrowseAllTickets = false): array
{
    $prefix = $alias !== '' ? $alias . '.' : '';

    if (($currentUser['role'] ?? '') === 'Admin') {
        return ['1=1', []];
    }

    if ($agentsBrowseAllTickets && (($currentUser['role'] ?? '') === 'Agent')) {
        return ['1=1', []];
    }

    if ($includeUnassignedForAgents) {
        return [
            '(' . $prefix . 'assign_to = :current_user_id OR ' . $prefix . 'assign_to IS NULL OR ' . $prefix . 'assign_to = \'\')',
            [':current_user_id' => $currentUser['user_id']],
        ];
    }

    return [
        $prefix . 'assign_to = :current_user_id',
        [':current_user_id' => $currentUser['user_id']],
    ];
}

function format_date(?string $value, string $format = 'd M Y, h:i A'): string
{
    if (!$value) {
        return '-';
    }

    try {
        $sourceTimezone = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $targetTimezone = new DateTimeZone('Asia/Kolkata');
        $date = new DateTimeImmutable($value, $sourceTimezone);
    } catch (Throwable $throwable) {
        return '-';
    }

    return $date->setTimezone($targetTimezone)->format($format);
}

function old_password_matches(string $plainPassword, string $storedPassword): bool
{
    return hash('sha256', $plainPassword) === $storedPassword;
}

function verify_user_password(string $plainPassword, string $storedPassword): bool
{
    return password_verify($plainPassword, $storedPassword) || old_password_matches($plainPassword, $storedPassword);
}

require_once __DIR__ . '/rbac.php';
