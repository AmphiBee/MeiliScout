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
    if (! function_exists('maybe_unserialize')) {
        function maybe_unserialize($value) { return $value; }
    }
    if (! function_exists('get_post_meta')) {
        function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][$key] ?? ''; }
    }
    if (! function_exists('apply_filters')) {
        function apply_filters($hook, $value, ...$args) { return $value; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post {

    use Pollora\MeiliScout\Indexables\PostIndexable;

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        $GLOBALS['meta'] = ['_price' => '25.50'];
        update_option('meiliscout/indexed_meta_keys', ['_price']);
    });

    /**
     * A single save reaches `formatForIndexing()` without any batch preloading. The
     * meta keys have to be resolved there too, or the document leaves for the engine
     * with no `metas` at all and the post drops out of every meta filter.
     */
    test('a document formatted outside a batch still carries its metas', function () {
        $document = (new PostIndexable)->formatForIndexing(new \WP_Post(116, 'product', 'A product'));

        expect($document['metas'])->toBe(['_price' => 25.5]);
    });
}
