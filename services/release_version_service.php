<?php
/**
 * Release metadata — release.json is runtime source; app_version.php holds defaults when JSON is missing.
 */

function release_version_json_path(): string
{
    return dirname(__DIR__) . '/version_management/release.json';
}

function release_version_app_version_path(): string
{
    return dirname(__DIR__) . '/version_management/app_version.php';
}

function release_version_invalidate_cache(): void
{
    $GLOBALS['__release_version_cached'] = null;
}

/**
 * @return array{version:string,build:string,release_date:string,features:list<string>,history:list<array<string,mixed>>,updated_at:string,updated_by:string}
 */
function release_version_defaults_from_file(): array
{
    static $loaded = false;

    if (!$loaded) {
        $path = release_version_app_version_path();
        if (file_exists($path)) {
            require_once $path;
        }
        $loaded = true;
    }

    return [
        'version' => defined('APP_VERSION') ? (string) APP_VERSION : '2.0.0',
        'build' => defined('APP_BUILD') ? (string) APP_BUILD : '',
        'release_date' => defined('APP_RELEASE_DATE') ? (string) APP_RELEASE_DATE : '',
        'features' => [],
        'history' => [],
        'updated_at' => '',
        'updated_by' => '',
    ];
}

/**
 * @return array{version:string,build:string,release_date:string,features:list<string>,history:list<array<string,mixed>>,updated_at:string,updated_by:string}
 */
function release_version_get(): array
{
    if (isset($GLOBALS['__release_version_cached']) && is_array($GLOBALS['__release_version_cached'])) {
        return $GLOBALS['__release_version_cached'];
    }

    $merged = release_version_defaults_from_file();
    $path = release_version_json_path();

    if (is_readable($path)) {
        $raw = file_get_contents($path);
        if ($raw !== false && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                if (isset($decoded['version']) && is_string($decoded['version'])) {
                    $merged['version'] = trim($decoded['version']);
                }
                if (isset($decoded['build']) && is_string($decoded['build'])) {
                    $merged['build'] = trim($decoded['build']);
                }
                if (isset($decoded['release_date']) && is_string($decoded['release_date'])) {
                    $merged['release_date'] = trim($decoded['release_date']);
                }
                if (isset($decoded['features']) && is_array($decoded['features'])) {
                    $merged['features'] = release_version_normalize_features($decoded['features']);
                }
                if (isset($decoded['history']) && is_array($decoded['history'])) {
                    $merged['history'] = $decoded['history'];
                }
                if (isset($decoded['updated_at']) && is_string($decoded['updated_at'])) {
                    $merged['updated_at'] = $decoded['updated_at'];
                }
                if (isset($decoded['updated_by']) && is_string($decoded['updated_by'])) {
                    $merged['updated_by'] = $decoded['updated_by'];
                }
            }
        }
    }

    $GLOBALS['__release_version_cached'] = $merged;

    return $merged;
}

/**
 * @param list<string>|list<mixed> $lines
 * @return list<string>
 */
function release_version_normalize_features(array $lines): array
{
    $out = [];
    foreach ($lines as $line) {
        $text = trim((string) $line);
        if ($text !== '') {
            $out[] = $text;
        }
    }

    return $out;
}

/**
 * @param list<string> $featureLines
 * @return array{ok:bool,error:string}
 */
function release_version_validate(string $version, string $build, string $releaseDate, array $featureLines): array
{
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        return ['ok' => false, 'error' => 'Version must be MAJOR.MINOR.PATCH (e.g. 2.0.0).'];
    }

    if ($build !== '' && !preg_match('/^\d{8}$/', $build)) {
        return ['ok' => false, 'error' => 'Build must be YYYYMMDD (e.g. 20260523).'];
    }

    if ($releaseDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $releaseDate)) {
        return ['ok' => false, 'error' => 'Release date must be YYYY-MM-DD.'];
    }

    if ($releaseDate !== '') {
        $parts = explode('-', $releaseDate);
        if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return ['ok' => false, 'error' => 'Release date is not a valid calendar date.'];
        }
    }

    if (release_version_normalize_features($featureLines) === []) {
        return ['ok' => false, 'error' => 'Add at least one feature or change note for this release.'];
    }

    return ['ok' => true, 'error' => ''];
}

function release_version_suggest_next(string $currentVersion, string $bump = 'patch'): string
{
    if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $currentVersion, $m)) {
        return '2.0.1';
    }

    $major = (int) $m[1];
    $minor = (int) $m[2];
    $patch = (int) $m[3];

    if ($bump === 'major') {
        return ($major + 1) . '.0.0';
    }
    if ($bump === 'minor') {
        return $major . '.' . ($minor + 1) . '.0';
    }

    return $major . '.' . $minor . '.' . ($patch + 1);
}

function release_version_today_build(): string
{
    return gmdate('Ymd');
}

function release_version_today_date(): string
{
    return gmdate('Y-m-d');
}

/**
 * @param array{version:string,build:string,release_date:string,features:list<string>,history:list<array<string,mixed>>} $snapshot
 * @param list<array<string,mixed>> $history
 * @return list<array<string,mixed>>
 */
function release_version_push_history(array $snapshot, array $history): array
{
    if (($snapshot['version'] ?? '') === '') {
        return $history;
    }

    array_unshift($history, [
        'version' => $snapshot['version'],
        'build' => $snapshot['build'] ?? '',
        'release_date' => $snapshot['release_date'] ?? '',
        'features' => $snapshot['features'] ?? [],
        'archived_at' => gmdate('c'),
    ]);

    return array_slice($history, 0, 20);
}

/**
 * @param array<string,mixed> $payload
 */
function release_version_persist(array $payload): void
{
    $jsonPath = release_version_json_path();
    $dir = dirname($jsonPath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create version_management directory.');
    }

    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Could not encode release data.');
    }

    $tmp = $jsonPath . '.tmp';
    if (file_put_contents($tmp, $encoded . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not write release file.');
    }
    if (file_exists($jsonPath) && !unlink($jsonPath)) {
        @unlink($tmp);
        throw new RuntimeException('Could not save release file.');
    }
    if (!rename($tmp, $jsonPath)) {
        @unlink($tmp);
        throw new RuntimeException('Could not save release file.');
    }

    // Live sidebar reads release.json only — do not rewrite app_version.php (avoids git pull conflicts on production).
    release_version_invalidate_cache();
}

/**
 * Update the live release without publishing a new version number (same version only).
 *
 * @param list<string> $featureLines
 */
function release_version_save_current(array $currentUser, string $version, string $build, string $releaseDate, array $featureLines): void
{
    if (!rbac_is_admin($currentUser)) {
        throw new RuntimeException('Admin access required.');
    }

    $version = trim($version);
    $build = trim($build);
    $releaseDate = trim($releaseDate);
    $features = release_version_normalize_features($featureLines);

    $check = release_version_validate($version, $build, $releaseDate, $features);
    if (!$check['ok']) {
        throw new InvalidArgumentException($check['error']);
    }

    release_version_invalidate_cache();
    $previous = release_version_get();
    $currentLive = (string) ($previous['version'] ?? '');

    if ($currentLive !== '' && $version !== $currentLive) {
        throw new InvalidArgumentException(
            'Version number changed. To ship v' . $version . ', use the Publish new release tab instead of Edit current.'
        );
    }

    $payload = [
        'version' => $version,
        'build' => $build,
        'release_date' => $releaseDate,
        'features' => $features,
        'history' => is_array($previous['history'] ?? null) ? $previous['history'] : [],
        'updated_at' => gmdate('c'),
        'updated_by' => (string) ($currentUser['user_id'] ?? ''),
    ];

    release_version_persist($payload);
}

/**
 * Archive the current live release and activate a new version (sidebar shows the new one).
 *
 * @param list<string> $featureLines
 */
function release_version_publish_new(array $currentUser, string $version, string $build, string $releaseDate, array $featureLines): void
{
    if (!rbac_is_admin($currentUser)) {
        throw new RuntimeException('Admin access required.');
    }

    $version = trim($version);
    $build = trim($build);
    $releaseDate = trim($releaseDate);
    $features = release_version_normalize_features($featureLines);

    $check = release_version_validate($version, $build, $releaseDate, $features);
    if (!$check['ok']) {
        throw new InvalidArgumentException($check['error']);
    }

    release_version_invalidate_cache();
    $previous = release_version_get();
    $currentLive = (string) ($previous['version'] ?? '');

    if ($currentLive !== '' && $version === $currentLive) {
        throw new InvalidArgumentException(
            'New version must be higher than current (v' . $currentLive . '). Use Edit current release to fix the same version.'
        );
    }

    $history = is_array($previous['history'] ?? null) ? $previous['history'] : [];
    if ($currentLive !== '') {
        $history = release_version_push_history($previous, $history);
    }

    $payload = [
        'version' => $version,
        'build' => $build,
        'release_date' => $releaseDate,
        'features' => $features,
        'history' => $history,
        'updated_at' => gmdate('c'),
        'updated_by' => (string) ($currentUser['user_id'] ?? ''),
    ];

    release_version_persist($payload);
}

/** @deprecated use release_version_save_current or release_version_publish_new */
function release_version_save(array $currentUser, string $version, string $build, string $releaseDate, array $featureLines): void
{
    release_version_save_current($currentUser, $version, $build, $releaseDate, $featureLines);
}

function release_version_sidebar_tooltip(array $release, array $envDisplay): string
{
    $parts = ['Version ' . ($release['version'] ?? '')];
    if (($release['build'] ?? '') !== '') {
        $parts[] = 'Build ' . $release['build'];
    }
    if (($release['release_date'] ?? '') !== '') {
        $parts[] = 'Released ' . format_date($release['release_date'], 'd M Y');
    }
    $parts[] = 'Environment: ' . ($envDisplay['label'] ?? 'Production');

    $features = $release['features'] ?? [];
    if ($features !== []) {
        $parts[] = 'Features: ' . implode('; ', array_slice($features, 0, 5));
        if (count($features) > 5) {
            $parts[] = '(+' . (count($features) - 5) . ' more)';
        }
    }

    return implode(' · ', $parts);
}

function release_version_features_textarea_value(array $features): string
{
    return implode("\n", $features);
}
