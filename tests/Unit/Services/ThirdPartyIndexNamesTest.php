<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Services {

    use Pollora\MeiliScout\Services\IndexNames;

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        putenv('MEILI_INDEX_PREFIX');
    });

    test('another plugin\'s index is read where it is written, on a site indexed before 2.0 too', function () {
        update_option('meiliscout/last_indexing_structure', ['post_types' => ['post']]);

        expect(IndexNames::active('manual_chunks'))->toBe('example_test_manual_chunks')
            ->and(IndexNames::target('manual_chunks'))->toBe('example_test_manual_chunks')
            // The plugin's own indexes keep their legacy names until migrated
            ->and(IndexNames::active('posts'))->toBe('posts');
    });

    test('another plugin\'s index has the same name before, during and after a rebuild', function () {
        $name = IndexNames::name('manual_chunks');
        $before = [IndexNames::target('manual_chunks'), IndexNames::active('manual_chunks')];

        IndexNames::redirect($name, $name.IndexNames::REBUILD_SUFFIX);
        $during = [IndexNames::target('manual_chunks'), IndexNames::active('manual_chunks')];
        IndexNames::lift($name);
        IndexNames::activate(IndexNames::BASES);

        expect($before)->toBe([$name, $name])
            ->and($during)->toBe([$name.IndexNames::REBUILD_SUFFIX, $name])
            ->and([IndexNames::target('manual_chunks'), IndexNames::active('manual_chunks')])->toBe([$name, $name]);
    });

    test('the base name of an index is read back from any of its names', function () {
        expect(IndexNames::baseOf('example_test_posts'))->toBe('posts')
            ->and(IndexNames::baseOf('example_test_manual_chunks__rebuild'))->toBe('manual_chunks')
            ->and(IndexNames::baseOf('posts'))->toBe('posts');
    });
}
