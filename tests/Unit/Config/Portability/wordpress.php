<?php

declare(strict_types=1);

// What the sections read from WordPress; the suite declares each once
if (! function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (! function_exists('_n')) {
    function _n($single, $plural, $number, $domain = 'default') { return $number === 1 ? $single : $plural; }
}
if (! function_exists('post_type_exists')) {
    function post_type_exists($type) { return in_array($type, $GLOBALS['portability_post_types'] ?? [$type], true); }
}
if (! function_exists('taxonomy_exists')) {
    function taxonomy_exists($taxonomy) { return empty($GLOBALS['listings_wordpress']) || in_array($taxonomy, ['category', 'post_tag'], true); }
}
if (! function_exists('sanitize_hex_color')) {
    function sanitize_hex_color($color) { return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $color) ? $color : null; }
}
if (! function_exists('sanitize_key')) {
    function sanitize_key($key) { return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
}
if (! function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return (string) $url; }
}
if (! function_exists('untrailingslashit')) {
    function untrailingslashit($value) { return rtrim((string) $value, '/\\'); }
}
