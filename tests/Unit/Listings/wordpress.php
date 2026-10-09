<?php

declare(strict_types=1);

// What the listings' definitions, codec and plan read from WordPress. The
// integration suite runs the same shared cases against the real functions.
//
// Declared for the whole suite once loaded: outside the listings' tests
// ($GLOBALS['listings_wordpress'] off), they act as if WordPress had none.

if (! function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}

if (! function_exists('taxonomy_exists')) {
    function taxonomy_exists($taxonomy) { return empty($GLOBALS['listings_wordpress']) || in_array($taxonomy, ['category', 'post_tag'], true); }
}

if (! function_exists('get_taxonomy')) {
    function get_taxonomy($taxonomy)
    {
        if (! taxonomy_exists($taxonomy)) {
            return false;
        }

        return (object) [
            'name' => $taxonomy,
            'hierarchical' => $taxonomy === 'category',
            'labels' => (object) ['singular_name' => $taxonomy === 'category' ? 'Category' : 'Tag'],
        ];
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}

if (! function_exists('trailingslashit')) {
    function trailingslashit($value) { return rtrim((string) $value, '/\\').'/'; }
}

if (! function_exists('untrailingslashit')) {
    function untrailingslashit($value) { return rtrim((string) $value, '/\\'); }
}

if (! function_exists('user_trailingslashit')) {
    function user_trailingslashit($url, $type = '') { return trailingslashit($url); }
}

if (! function_exists('sanitize_title')) {
    /**
     * sanitize_title_with_dashes() without remove_accents(): the shared cases
     * that need it ("accents") run in the integration suite only.
     */
    function sanitize_title($title, $fallback = '', $context = 'save')
    {
        if (empty($GLOBALS['listings_wordpress'])) {
            return $title;
        }

        $title = strip_tags((string) $title);
        $title = str_replace('%', '---', $title);
        $title = preg_replace('|---([a-fA-F0-9][a-fA-F0-9])|', '%$1', $title);
        $title = str_replace('---', '', $title);
        $title = preg_replace_callback('/[\x80-\xff]+/', fn ($m) => strtolower(rawurlencode($m[0])), $title);
        $title = strtolower($title);
        $title = str_replace('.', '-', $title);
        $title = preg_replace('/[^%a-z0-9 _-]/', '', $title);
        $title = preg_replace('/\s+/', '-', $title);
        $title = preg_replace('|-+|', '-', $title);

        return trim($title, '-');
    }
}

if (! class_exists('WP_Rewrite', false)) {
    class WP_Rewrite
    {
        public string $pagination_base = 'page';

        public function using_permalinks(): bool { return true; }
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key($key) { return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
}
