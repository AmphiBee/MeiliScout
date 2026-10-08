<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Services {

    use Pollora\MeiliScout\Services\IndexNames;

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        putenv('MEILI_INDEX_PREFIX');
    });

    function indexedBefore20(): void
    {
        update_option('meiliscout/last_indexing_structure', ['post_types' => ['post']]);
    }

    test('index names are prefixed with the site domain by default', function () {
        expect(IndexNames::target('posts'))->toBe('example_test_posts');
    });

    test('the prefix can be set, and is made safe for an index name', function () {
        putenv('MEILI_INDEX_PREFIX=Shop Staging!');
        expect(IndexNames::target('posts'))->toBe('shop_staging_posts');
        putenv('MEILI_INDEX_PREFIX');

        $GLOBALS['filters']['meiliscout/index_prefix'] = '';
        expect(IndexNames::target('posts'))->toBe('posts');
    });

    test('a new site searches the target indexes, in the current format, with nothing to migrate', function () {
        expect(IndexNames::active('posts'))->toBe('example_test_posts')
            ->and(IndexNames::activeSchema())->toBe(IndexNames::schemaVersion('posts'))
            ->and(IndexNames::migrationPending())->toBeFalse()
            ->and(IndexNames::mirrorOf('example_test_posts'))->toBeNull();
    });

    test('the first indexation of a new site has no legacy index to migrate from nor to delete', function () {
        IndexNames::adoptIfNew();
        // What the indexation writes next, which a site indexed before 2.0 also has
        update_option('meiliscout/last_indexing_structure', ['post_types' => ['post']]);

        expect(IndexNames::active('posts'))->toBe('example_test_posts')
            ->and(IndexNames::migrationPending())->toBeFalse();

        IndexNames::activate();

        expect(IndexNames::legacyIndexes())->toBe([]);
    });

    test('a site indexed before 2.0 is not taken for a new one', function () {
        indexedBefore20();

        IndexNames::adoptIfNew();

        expect(IndexNames::active('posts'))->toBe('posts')
            ->and(IndexNames::migrationPending())->toBeTrue();
    });

    test('a site indexed before 2.0 keeps searching its legacy index until it migrates', function () {
        indexedBefore20();

        expect(IndexNames::active('posts'))->toBe('posts')
            ->and(IndexNames::activeSchema())->toBe(1)
            ->and(IndexNames::migrationPending())->toBeTrue()
            ->and(IndexNames::mirrorOf('example_test_posts'))->toBe('posts');
    });

    test('a full indexation moves searches to the target indexes and lists the legacy ones', function () {
        indexedBefore20();

        IndexNames::activate();

        expect(IndexNames::active('posts'))->toBe('example_test_posts')
            ->and(IndexNames::activeSchema())->toBe(IndexNames::schemaVersion('posts'))
            ->and(IndexNames::migrationPending())->toBeFalse()
            ->and(IndexNames::mirrorOf('example_test_posts'))->toBeNull()
            ->and(IndexNames::legacyIndexes())->toBe(['posts', 'taxonomies']);

        IndexNames::forgetLegacyIndexes();
        expect(IndexNames::legacyIndexes())->toBe([]);
    });

    test('a changed prefix calls for a migration', function () {
        IndexNames::activate();
        putenv('MEILI_INDEX_PREFIX=renamed');

        expect(IndexNames::migrationPending())->toBeTrue()
            ->and(IndexNames::active('posts'))->toBe('example_test_posts')
            ->and(IndexNames::mirrorOf('renamed_posts'))->toBe('example_test_posts');

        putenv('MEILI_INDEX_PREFIX');
    });

    test('each index has its own format version, read from the single version recorded up to schema 3', function () {
        update_option('meiliscout/active_indexes', ['posts' => 'example_test_posts', 'taxonomies' => 'example_test_taxonomies']);
        update_option('meiliscout/schema_version', 3);

        expect(IndexNames::activeSchema('posts'))->toBe(3)
            ->and(IndexNames::activeSchema('taxonomies'))->toBe(3);
    });

    test('a full indexation moves only the indexes it built to the current format', function () {
        update_option('meiliscout/active_indexes', ['posts' => 'example_test_posts', 'taxonomies' => 'example_test_taxonomies']);
        update_option('meiliscout/schema_version', ['posts' => 0, 'taxonomies' => 0]);

        expect(IndexNames::pendingBases())->toBe(['posts', 'taxonomies']);

        IndexNames::activate(['taxonomies']);

        expect(IndexNames::activeSchema('taxonomies'))->toBe(IndexNames::schemaVersion('taxonomies'))
            ->and(IndexNames::activeSchema('posts'))->toBe(0)
            ->and(IndexNames::pendingBases())->toBe(['posts'])
            ->and(IndexNames::migrationPending())->toBeTrue();
    });

    test('a site indexed before 2.0 that rebuilt one index keeps reading the legacy other one', function () {
        indexedBefore20();

        IndexNames::activate(['posts']);

        expect(IndexNames::active('posts'))->toBe('example_test_posts')
            ->and(IndexNames::active('taxonomies'))->toBe('taxonomies')
            ->and(IndexNames::activeSchema('taxonomies'))->toBe(1)
            ->and(IndexNames::pendingBases())->toBe(['taxonomies'])
            ->and(IndexNames::legacyIndexes())->toBe(['posts']);
    });

    test('an index being rebuilt is written under its temporary name only', function () {
        IndexNames::redirect('example_test_posts', 'example_test_posts__rebuild');

        expect(IndexNames::target('posts'))->toBe('example_test_posts__rebuild')
            ->and(IndexNames::name('posts'))->toBe('example_test_posts')
            ->and(IndexNames::active('posts'))->toBe('example_test_posts');

        IndexNames::lift('example_test_posts');
        expect(IndexNames::target('posts'))->toBe('example_test_posts');
    });
}
