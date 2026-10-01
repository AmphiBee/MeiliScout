<?php

declare(strict_types=1);

namespace {
    if (! function_exists('get_posts')) {
        function get_posts($args) { $GLOBALS['get_posts_args'][] = $args; return []; }
    }
    if (! function_exists('apply_filters')) {
        function apply_filters($hook, $value, ...$args) { return $value; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post {

    use Pollora\MeiliScout\Indexables\PostIndexable;

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        $GLOBALS['get_posts_args'] = [];
        update_option('meiliscout/indexed_post_types', ['post']);
        update_option('meiliscout/indexed_meta_keys', ['_price']);
    });

    test('a full run reads only the statuses a single save would index', function () {
        iterator_to_array((new PostIndexable)->getItems());

        expect($GLOBALS['get_posts_args'][0]['post_status'])->toBe(PostIndexable::indexableStatuses())
            ->and(PostIndexable::indexableStatuses())->toBe(['publish']);
    });
}
