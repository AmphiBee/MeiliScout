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
        function get_post_meta($id, $key, $single = false)
        {
            $values = (array) ($GLOBALS['meta'][$key] ?? []);

            return $single ? ($values[0] ?? '') : $values;
        }
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

    test('a document carries every value of a meta key, the empty ones too, and notes what they are like', function () {
        update_option('meiliscout/indexed_meta_keys', ['color', 'note', 'size']);
        $GLOBALS['meta'] = ['color' => ['red', 'blue'], 'note' => [''], 'size' => ['12']];
        \Pollora\MeiliScout\Services\MetaValueFlags::reset();

        $document = (new PostIndexable)->formatForIndexing(new \WP_Post(7, 'post', 'Colors'));
        \Pollora\MeiliScout\Services\MetaValueFlags::flush();

        expect($document['metas'])->toBe(['color' => ['red', 'blue'], 'note' => '', 'size' => 12])
            ->and(\Pollora\MeiliScout\Services\MetaValueFlags::of('color'))->toBe(['multiple' => true, 'non_numeric' => true])
            ->and(\Pollora\MeiliScout\Services\MetaValueFlags::of('note'))->toBe(['non_numeric' => true])
            ->and(\Pollora\MeiliScout\Services\MetaValueFlags::of('size'))->toBe(['numeric' => true]);
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

    test('a document says whether the post has a password, and carries a title to sort on', function () {
        $open = (new PostIndexable)->formatForIndexing(new \WP_Post(7, 'post', 'Éclat'));
        $protected = (new PostIndexable)->formatForIndexing(new \WP_Post(8, 'post', 'Locked', 'publish', 'Body', 'Summary', 'secret'));

        expect($open['has_password'])->toBeFalse()
            ->and($protected['has_password'])->toBeTrue()
            ->and($open['ID'])->toBe(7)
            ->and($open['post_title_sort'])->toBe(PostIndexable::titleSortKey('Éclat'))
            ->and(PostIndexable::titleSortKey(' Abc '))->toBe('abc');
    });

    test('the posts index sorts strictly first, and can reach the maximum number of results', function () {
        $settings = (new PostIndexable)->getIndexSettings();

        expect($settings['rankingRules'][0])->toBe('sort')
            ->and($settings['pagination'])->toBe(['maxTotalHits' => 10000])
            ->and($settings['filterableAttributes'])->toContain('ID', 'post_author', 'date_parts', 'post_date_ts')
            ->and($settings['sortableAttributes'])->toContain('post_title_sort', 'menu_order', 'ID');
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
