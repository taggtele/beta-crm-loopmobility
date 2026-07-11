<?php
/**
 * Lucide-compatible stroke icons (viewBox 0 0 24 24, MIT lucide-icons) for PHP templates.
 * Icons are inlined — no npm/React bundle. Paths live in lucide_icon_paths.php for edits.
 */

/**
 * Inline SVG markup (safe HTML). Use icon keys only — never user-controlled input as $name.
 *
 * @param array{size?: int, class?: string} $opts
 */
function lucide_icon_svg(string $name, array $opts = []): string
{
    $size = isset($opts['size']) ? (int) $opts['size'] : 18;
    $extraClass = isset($opts['class']) ? ' ' . htmlspecialchars((string) $opts['class'], ENT_QUOTES, 'UTF-8') : '';

    $inner = lucide_icon_inner_for($name);
    if ($inner === null) {
        return '';
    }

    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide-icon%s" aria-hidden="true" focusable="false">%s</svg>',
        max(12, min(96, $size)),
        max(12, min(96, $size)),
        $extraClass,
        $inner
    );
}

function lucide_icon_inner_for(string $name): ?string
{
    static $paths = null;
    if ($paths === null) {
        /** @var array<string, string> $loaded */
        $loaded = require __DIR__ . '/lucide_icon_paths.php';
        $paths = $loaded;
    }

    return $paths[$name] ?? null;
}
