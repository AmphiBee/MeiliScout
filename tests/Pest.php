<?php

// The integration suite runs against a real WordPress, which must load before
// the stand-ins below are declared. Pest loads this file before PHPUnit reads
// its configuration: the configuration file is looked for in the arguments.
if (getenv('MEILISCOUT_INTEGRATION') || preg_grep('/phpunit\.integration\.xml$/', $_SERVER['argv'] ?? [])) {
    require __DIR__.'/Integration/bootstrap.php';

    return;
}

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

// pest()->extend(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// Declared once for the whole suite: two test files declaring their own with
// different properties is a collision that only shows when both are run.
if (! class_exists('WP_Post', false)) {
    class WP_Post
    {
        public function __construct(
            public int $ID,
            public string $post_type = 'post',
            public string $post_title = '',
            public string $post_status = 'publish',
            public string $post_content = '',
            public string $post_excerpt = '',
            public string $post_password = '',
            public int $post_parent = 0,
            public string $post_mime_type = '',
        ) {}
    }
}

// Declared once for the whole suite: a file declaring its own that ignores `$GLOBALS['filters']`
// silently disarmed the filters every later file set.
if (! function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args)
    {
        // The integration suite checks that Meilisearch parses every filter the builders produce
        if ($hook === 'meiliscout/search_params' && getenv('MEILISCOUT_RECORD_FILTERS') && ! empty($value['filter'])) {
            file_put_contents(getenv('MEILISCOUT_RECORD_FILTERS'), $value['filter'].PHP_EOL, FILE_APPEND);
        }

        return $GLOBALS['filters'][$hook] ?? $value;
    }
}

uses()->beforeEach(function () {
    $GLOBALS['filters'] = [];
    // Meilisearch's answers are kept for the request: each test is one
    \Pollora\MeiliScout\Query\SearchMemo::reset();
})->in('Unit');

if (! class_exists('WP_Term', false)) {
    class WP_Term
    {
        public int $term_id = 0;
        public string $name = '';
        public string $slug = '';
        public int $parent = 0;
        public string $description = '';
        public string $taxonomy = '';
        public int $term_taxonomy_id = 0;
    }
}

// Mock WordPress options storage for tests
$GLOBALS['wp_options'] = [];

if (! function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        if (isset($GLOBALS['wp_options'][$option])) {
            return $GLOBALS['wp_options'][$option];
        }
        
        return match ($option) {
            'posts_per_page' => 10,
            default => $default
        };
    }
}

if (! function_exists('update_option')) {
    function update_option($option, $value, $autoload = null)
    {
        $GLOBALS['wp_options'][$option] = $value;
        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option($option)
    {
        unset($GLOBALS['wp_options'][$option]);
        return true;
    }
}

// Index names derive from the site's address
if (! function_exists('home_url')) {
    function home_url($path = '') { return 'https://example.test'.$path; }
}

if (! function_exists('is_multisite')) {
    function is_multisite() { return false; }
}

if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) { return trim(strip_tags((string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text))); }
}

if (! function_exists('strip_shortcodes')) {
    function strip_shortcodes($content) { return (string) preg_replace('/\[[^\]]+\]/', '', (string) $content); }
}

// Same as the files that declared it first: hooks are recorded, never run
if (! function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['actions'][$hook][] = ['callback' => $callback, 'priority' => $priority]; return true; }
}

if (! function_exists('current_time')) {
    function current_time($type, $gmt = 0) { return $type === 'mysql' ? gmdate('Y-m-d H:i:s') : time(); }
}
