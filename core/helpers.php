<?php
/**
 * Core Helpers - Utility functions
 * Reuses existing auth.php for session/auth logic
 */

require_once APP_ROOT . '/includes/auth.php';

// ================= SAFE OUTPUT =================
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// ================= URL BUILDER =================
if (!function_exists('url')) {
    function url(string $path = ''): string {
        $path = ltrim($path, '/');
        $basePath = app_base_path();
        return $basePath === '' ? '/' . $path : $basePath . '/' . $path;
    }
}

// ================= JSON RESPONSE =================
if (!function_exists('json_response')) {
    function json_response(array $data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// ================= REDIRECT =================
if (!function_exists('redirect')) {
    function redirect(string $path): void {
        header('Location: ' . url($path));
        exit;
    }
}

// ================= FILE UTILS =================
if (!function_exists('save_upload')) {
    function save_upload(array $file, string $dir): ?string {
        if ($file['error'] !== UPLOAD_ERR_OK) return null;
        
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . strtolower($ext);
        $target = $dir . '/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $target)) {
            return $filename;
        }
        return null;
    }
}

// ================= ENCRYPTION =================
if (!function_exists('encrypt_str')) {
    function encrypt_str(string $data, string $key): string {
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
}

if (!function_exists('decrypt_str')) {
    function decrypt_str(string $data, string $key): string {
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }
}

// ================= ARRAY UTILS =================
if (!function_exists('array_get')) {
    function array_get(array $arr, $key, $default = null) {
        return $arr[$key] ?? $default;
    }
}

if (!function_exists('array_only')) {
    function array_only(array $arr, array $keys): array {
        return array_intersect_key($arr, array_flip($keys));
    }
}

if (!function_exists('array_except')) {
    function array_except(array $arr, array $keys): array {
        return array_diff_key($arr, array_flip($keys));
    }
}

// ================= PAGINATION =================
if (!function_exists('paginate')) {
    function paginate(int $total, int $page = 1, int $perPage = 10): array {
        $totalPages = (int) ceil($total / $perPage);
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'offset' => $offset,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
        ];
    }
}

// ================= DATE UTILS =================
if (!function_exists('now')) {
    function now(): string {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('today')) {
    function today(): string {
        return date('Y-m-d');
    }
}

// ================= EMAIL FORMATTING =================
/**
 * Format email body for clean display in HTML.
 * Always returns safe HTML-ready string.
 *  - HTML emails: sanitize tags, remove junk, normalize whitespace
 *  - Plain text: escape HTML chars, collapse spaces, preserve paragraphs with <br>
 */
if (!function_exists('format_email_body')) {
    function format_email_body(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        // Detect HTML (presence of a tag)
        $hasHtml = preg_match('/<[a-z]+[^>]*>/i', $body);

        if ($hasHtml) {
            // Remove dangerous elements and their contents
            $body = preg_replace('#<style[^>]*>.*?</style>#is', '', $body);
            $body = preg_replace('#<script[^>]*>.*?</script>#is', '', $body);
            $body = preg_replace('#<!--.*?-->#s', '', $body);

            // Remove hidden elements (display:none, visibility:hidden) entirely
            $body = preg_replace('#<[^>]+style=["\'][^"\']*(?:display\s*:\s*none|visibility\s*:\s*hidden)[^"\']*["\'][^>]*>.*?</[^>]+>#is', '', $body);
            $body = preg_replace('#<[^>]+style=["\'][^"\']*(?:display\s*:\s*none|visibility\s*:\s*hidden)[^"\']*["\'][^>]*/*>#is', '', $body);

            // Allow only basic safe tags per requirements
            $allowedTags = '<p><br><b><i><table><tr><td><th><tbody><thead><tfoot><ul><ol><li>';
            $body = strip_tags($body, $allowedTags);

            // Normalize whitespace inside text nodes
            $body = preg_replace_callback(
                '/>([^<]+)</',
                function ($m) {
                    $clean = preg_replace('/\s+/', ' ', $m[1]);
                    return '>' . trim($clean) . '<';
                },
                $body
            );

            // Remove empty block elements
            $body = preg_replace('#<(p|div|span|li|ul|ol|td|th|tbody|thead|tfoot)>\s*</\1>#i', '', $body);
            $body = preg_replace('#<br>\s*</[^>]+>#i', '', $body); // remove stray br inside empty tags

            return trim($body);
        }

        // Plain text: escape HTML, normalize spaces, use <br> for line breaks
        $lines = explode("\n", $body);
        $cleanLines = [];
        $emptyStreak = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                $emptyStreak++;
                if ($emptyStreak <= 1) {
                    $cleanLines[] = '';
                }
            } else {
                $emptyStreak = 0;
                $line = preg_replace('/ {2,}/', ' ', $line);
                $cleanLines[] = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
            }
        }
        // Remove trailing empties
        while (end($cleanLines) === '') {
            array_pop($cleanLines);
        }

        return implode("<br>\n", $cleanLines);
    }
}



/**
 * Format ticket for display: LM-YYYYMMDD-XX
 * Uses precomputed daily_sequence from query to avoid N+1 queries.
 */
if (!function_exists('format_ticket_serial')) {
    function format_ticket_serial(PDO $pdo, array $ticket): string {
        $storedInternalId = trim((string) ($ticket['internal_ticket_id'] ?? ''));
        if ($storedInternalId !== '') {
            return $storedInternalId;
        }

        $datePart = date('Ymd', strtotime($ticket['created_at']));
        $seq = (int) ($ticket['daily_sequence'] ?? 0);
        if ($seq <= 0) {
            // Fallback: compute by counting tickets on same day with ticket_id <= current (expensive)
            $date = date('Y-m-d', strtotime($ticket['created_at']));
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) as cnt FROM tickets 
                 WHERE DATE(created_at) = :date 
                 AND ticket_id <= :ticket_id"
            );
            $stmt->execute([
                ':date' => $date,
                ':ticket_id' => (int) $ticket['ticket_id'],
            ]);
            $seq = (int) $stmt->fetch()['cnt'];
        }
        return 'LM-' . $datePart . '-' . str_pad($seq, 2, '0', STR_PAD_LEFT);
    }
}
