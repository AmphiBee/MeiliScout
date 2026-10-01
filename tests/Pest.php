<?php

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
        ) {}
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
