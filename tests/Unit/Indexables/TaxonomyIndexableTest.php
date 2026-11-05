<?php

declare(strict_types=1);

namespace {
    // get_terms() refuses the whole list when one taxonomy is unknown, as WordPress does.
    if (! class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(public string $code = '', public string $message = '') {}
        }
    }

    if (! function_exists('is_wp_error')) {
        function is_wp_error($thing) { return $thing instanceof WP_Error; }
    }

    if (! function_exists('get_terms')) {
        function get_terms($args)
        {
            $GLOBALS['terms_asked'][] = $args;

            foreach ((array) $args['taxonomy'] as $taxonomy) {
                if (! taxonomy_exists($taxonomy)) {
                    return new WP_Error('invalid_taxonomy', 'Invalid taxonomy.');
                }
            }

            return array_map(fn ($taxonomy) => (object) ['term_id' => 1, 'taxonomy' => $taxonomy], (array) $args['taxonomy']);
        }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Indexables {

    use Pollora\MeiliScout\Indexables\TaxonomyIndexable;
    use Pollora\MeiliScout\Services\Indexer;
    use ReflectionClass;

    require_once dirname(__DIR__).'/Listings/wordpress.php';

    beforeEach(function () {
        // WordPress knows category and post_tag only.
        $GLOBALS['listings_wordpress'] = true;
        $GLOBALS['wp_options'] = [
            'meiliscout/indexed_taxonomies' => ['category', 'mb-views-category', 'post_tag'],
            'meiliscout/'.TaxonomyIndexable::META_KEYS_SETTING => ['color'],
        ];
        $GLOBALS['terms_asked'] = [];
    });

    afterEach(function () {
        $GLOBALS['listings_wordpress'] = false;
    });

    test('a selected taxonomy WordPress does not know on this request is left out, and named', function () {
        expect(TaxonomyIndexable::indexableTaxonomies())->toBe(['category', 'post_tag'])
            ->and(TaxonomyIndexable::unregisteredTaxonomies())->toBe(['mb-views-category']);
    });

    test('one unregistered taxonomy no longer empties the terms a full indexation sends', function () {
        $terms = iterator_to_array((new TaxonomyIndexable)->getItems(), false);

        expect(array_column($terms, 'taxonomy'))->toBe(['category', 'post_tag'])
            ->and($GLOBALS['terms_asked'][0]['taxonomy'])->toBe(['category', 'post_tag']);
    });

    test('a taxonomy left out is not taken for a change of structure', function () {
        $GLOBALS['wp_options']['meiliscout/last_indexing_structure'] = [
            'post_types' => [],
            'taxonomies' => ['category', 'post_tag'],
            'meta_keys' => [],
            'last_indexed' => '2026-11-05 14:00:00',
        ];
        $indexer = (new ReflectionClass(Indexer::class))->newInstanceWithoutConstructor();

        expect($indexer->checkStructureChanges()['changes'])->not->toHaveKey('taxonomies');
    });

    test('nothing is asked of WordPress when no selected taxonomy is registered', function () {
        $GLOBALS['wp_options']['meiliscout/indexed_taxonomies'] = ['mb-views-category'];

        expect(iterator_to_array((new TaxonomyIndexable)->getItems(), false))->toBe([])
            ->and($GLOBALS['terms_asked'])->toBe([]);
    });
}
