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
}

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post {

    use Pollora\MeiliScout\Config\SearchableAttributes;
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

    test('a document never carries a password, nor the text it protects', function () {
        $open = (new PostIndexable)->formatForIndexing(new \WP_Post(7, 'post', 'Open', 'publish', 'Body', 'Summary'));
        $protected = (new PostIndexable)->formatForIndexing(new \WP_Post(8, 'post', 'Locked', 'publish', 'Body', 'Summary', 'secret'));

        expect($open)->not->toHaveKey('post_password')
            ->and($open['post_content'])->toBe('Body')
            ->and($protected)->not->toHaveKey('post_password')
            ->and($protected['post_title'])->toBe('Locked')
            ->and($protected['post_content'])->toBe('')
            ->and($protected['post_excerpt'])->toBe('')
            ->and($protected['content_text'])->toBe('');
    });

    test('a document carries its content as plain text, without block markup', function () {
        $content = "<!-- wp:heading -->\n<h2>Boutique &amp; thé</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Une <strong>refonte</strong> [gallery ids=\"1,2\"] réussie.</p>\n<!-- /wp:paragraph -->";

        $document = (new PostIndexable)->formatForIndexing(new \WP_Post(9, 'post', 'Title', 'publish', $content));

        expect($document['content_text'])->toBe('Boutique & thé Une refonte réussie.')
            ->and($document['post_content'])->toBe($content);
    });

    test('every field is searched until the admin orders them', function () {
        expect((new PostIndexable)->getIndexSettings()['searchableAttributes'])->toBe(['*']);

        SearchableAttributes::save(['post_title', 'content_text', 'guid', 'metas._price', 'post_title']);

        expect((new PostIndexable)->getIndexSettings()['searchableAttributes'])->toBe(['post_title', 'content_text', 'metas._price']);
    });
}
