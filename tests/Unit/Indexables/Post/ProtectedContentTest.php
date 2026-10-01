<?php

declare(strict_types=1);

namespace {
    if (! function_exists('get_permalink')) {
        function get_permalink($post) { return 'https://example.test/?p='.$post->ID; }
    }
    if (! function_exists('get_object_taxonomies')) {
        function get_object_taxonomies($type) { return []; }
    }
    if (! function_exists('wp_get_post_terms')) {
        function wp_get_post_terms($id, $taxonomy) { return []; }
    }
    if (! function_exists('get_post_meta')) {
        function get_post_meta($id, $key, $single = false) { return ''; }
    }
    if (! function_exists('apply_filters')) {
        function apply_filters($hook, $value, ...$args) { return $value; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post {

    use Pollora\MeiliScout\Indexables\PostIndexable;

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
    });

    test('a document never carries a password, nor the text it protects', function () {
        $open = (new PostIndexable)->formatForIndexing(new \WP_Post(7, 'post', 'Open', 'publish', 'Body', 'Summary'));
        $protected = (new PostIndexable)->formatForIndexing(new \WP_Post(8, 'post', 'Locked', 'publish', 'Body', 'Summary', 'secret'));

        expect($open)->not->toHaveKey('post_password')
            ->and($open['post_content'])->toBe('Body')
            ->and($protected)->not->toHaveKey('post_password')
            ->and($protected['post_title'])->toBe('Locked')
            ->and($protected['post_content'])->toBe('')
            ->and($protected['post_excerpt'])->toBe('');
    });
}
