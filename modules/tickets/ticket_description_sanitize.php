<?php
declare(strict_types=1);

//() This file refferce  to a  sanitization  of detail of a ticket generation

/** Max length for MySQL TEXT column (leave headroom). */
const TICKET_DESCRIPTION_MAX_BYTES = 65000;

/** Inline data-URI images larger than this are dropped (TEXT column limit). */
const TICKET_DESCRIPTION_MAX_DATA_IMAGE_BYTES = 48000;

function ticket_description_allowed_tags(): array
{
    return [
        'p', 'br', 'div', 'span', 'strong', 'b', 'em', 'i', 'u', 's', 'strike',
        'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'caption', 'colgroup', 'col', 'a', 'img', 'h1', 'h2', 'h3', 'h4',
        'blockquote', 'pre', 'hr',
    ];
}

function ticket_description_normalize_for_storage(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    if (!preg_match('/<\s*[a-zA-Z]/', $input)) {
        return $input;
    }
    return ticket_description_sanitize_html_fragment($input);
}

function ticket_description_sanitize_html_fragment(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $snippet = '<meta http-equiv="Content-Type" content="text/html;charset=utf-8">'
        . '<div id="__san_root">' . $html . '</div>';
    @$doc->loadHTML($snippet);
    $root = $doc->getElementById('__san_root');
    if (!$root) {
        $allowedOpen = array_map(
            static fn(string $t): string => '<' . $t . '>',
            ticket_description_allowed_tags()
        );

        return trim(strip_tags($html, implode('', $allowedOpen)));
    }

    $allowed = array_flip(ticket_description_allowed_tags());
    foreach (iterator_to_array($root->getElementsByTagName('*')) as $el) {
        if (!$el instanceof DOMElement || $el->parentNode === null) {
            continue;
        }
        $tag = strtolower($el->tagName);
        if (!isset($allowed[$tag])) {
            ticket_description_unwrap_element($el);
        }
    }

    foreach (iterator_to_array($root->getElementsByTagName('*')) as $el) {
        if (!$el instanceof DOMElement || $el->parentNode === null) {
            continue;
        }
        ticket_description_clean_attributes($el);
    }

    $out = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $out .= $doc->saveHTML($child);
    }
    $out = trim($out);

    if ($out === '' || preg_match('/^\s*(<br\s*\/?>(&nbsp;|\s)*)+$/i', $out) === 1) {
        return '';
    }

    return $out;
}

function ticket_description_unwrap_element(DOMElement $el): void
{
    $parent = $el->parentNode;
    if ($parent === null) {
        return;
    }
    while ($el->firstChild !== null) {
        $parent->insertBefore($el->firstChild, $el);
    }
    $parent->removeChild($el);
}

function ticket_description_clean_attributes(DOMElement $el): void
{
    if ($el->parentNode === null) {
        return;
    }
    $tag = strtolower($el->tagName);
    $attrs = [];
    $attrNames = [];
    if ($el->hasAttributes()) {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $attrNames[] = $attr->name;
            $attrs[strtolower($attr->name)] = $attr->value;
        }
    }
    foreach ($attrNames as $name) {
        $el->removeAttribute($name);
    }
    if ($tag === 'a') {
        $href = trim((string) ($attrs['href'] ?? ''));
        if (preg_match('#^(https?:|mailto:)#i', $href) === 1) {
            $el->setAttribute('href', $href);
            if (!empty($attrs['title'])) {
                $el->setAttribute('title', $attrs['title']);
            }
        } else {
            ticket_description_unwrap_element($el);
        }

        return;
    }

    if ($tag === 'img') {
        $src = trim((string) ($attrs['src'] ?? ''));
        $ok = false;
        if (preg_match('#^https?://#i', $src) === 1) {
            $ok = true;
        } elseif (preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,#i', $src) === 1) {
            $ok = strlen($src) <= TICKET_DESCRIPTION_MAX_DATA_IMAGE_BYTES;
        }
        if ($ok) {
            $el->setAttribute('src', $src);
            if (!empty($attrs['alt'])) {
                $el->setAttribute('alt', $attrs['alt']);
            }
            if (!empty($attrs['title'])) {
                $el->setAttribute('title', $attrs['title']);
            }
        } else {
            $el->parentNode?->removeChild($el);
        }
        return;
    }

    if (in_array($tag, ['td', 'th'], true)) {
        foreach (['colspan', 'rowspan'] as $k) {
            if (empty($attrs[$k])) {
                continue;
            }
            $v = (string) $attrs[$k];
            if (preg_match('/^[1-9][0-9]{0,3}$/', $v) === 1) {
                $el->setAttribute($k, $v);
            }
        }
        return;
    }

    if ($tag === 'col' && !empty($attrs['span']) && preg_match('/^[1-9][0-9]{0,3}$/', (string) $attrs['span']) === 1) {
        $el->setAttribute('span', (string) $attrs['span']);
    }
}

function ticket_description_has_meaningful_content(string $html): bool
{
    $html = trim($html);
    if ($html === '') {
        return false;
    }
    if (preg_match('/<\s*img\b/i', $html) === 1) {
        return true;
    }
    $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $compact = (string) preg_replace('/\xc2\xa0|\s+/u', '', $plain);

    return $compact !== '';
}

/**
 * Echo description in ticket view: plain text stays escaped; stored HTML is emitted as-is (sanitized on save).
 */
function ticket_description_render_html(string $stored): void
{
    if (trim($stored) === '') {
        echo '-';

        return;
    }
    if (!preg_match('/<\s*[a-zA-Z]/', $stored)) {
        echo nl2br(e($stored));

        return;
    }
    $safe = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $stored) ?? $stored;
    echo $safe;
}
