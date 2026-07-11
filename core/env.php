<?php

if (!function_exists('app_load_env')) {
    function app_load_env(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === '' || getenv($key) !== false) {
                continue;
            }

            $value = trim($value, "\"'");
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

if (!function_exists('env_value')) {
    function env_value(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === 'true') {
            return true;
        }
        if ($normalized === 'false') {
            return false;
        }
        if ($normalized === 'null') {
            return null;
        }

        return $value;
    }
}

if (!function_exists('app_debug_enabled')) {
    function app_debug_enabled(): bool
    {
        return filter_var(env_value('APP_DEBUG', false), FILTER_VALIDATE_BOOL);
    }
}

if (!function_exists('app_environment')) {
    function app_environment(): string
    {
        return strtolower((string) env_value('APP_ENV', 'production'));
    }
}

/**
 * Maps APP_ENV (.env) to a sidebar/footer badge label + stable CSS slug.
 * Used for deployment indicators only — does not affect RBAC or routing.
 *
 * @return array{slug: string, label: string}
 */
if (!function_exists('app_environment_display')) {
    function app_environment_display(): array
    {
        $raw = app_environment();

        switch ($raw) {
            case 'production':
            case 'prod':
                return ['slug' => 'production', 'label' => 'Production'];
            case 'staging':
            case 'stage':
                return ['slug' => 'staging', 'label' => 'Staging'];
            case 'local':
            case 'development':
            case 'dev':
                return ['slug' => 'development', 'label' => 'Development'];
            default:
                return ['slug' => 'custom', 'label' => ucfirst($raw)];
        }
    }
}
