<?php

declare(strict_types=1);

namespace {
    if (! function_exists('get_taxonomies')) {
        function get_taxonomies($args = [], $output = 'names') { return ['category' => 'category']; }
    }
    if (! function_exists('wp_cache_add_non_persistent_groups')) {
        function wp_cache_add_non_persistent_groups($groups)
        {
            global $wp_object_cache;
            $wp_object_cache->ignored_groups = array_unique(array_merge($wp_object_cache->ignored_groups, (array) $groups));
        }
    }
    if (! function_exists('wp_cache_flush_runtime')) {
        function wp_cache_flush_runtime() { $GLOBALS['runtime_flushes'] = ($GLOBALS['runtime_flushes'] ?? 0) + 1; return true; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Services {

    use Pollora\MeiliScout\Services\ObjectCacheIsolation;

    /**
     * Mimics the Redis Object Cache drop-in: public ignored groups, private group types.
     */
    function redisLikeCache(): object
    {
        return new class {
            public array $ignored_groups = ['counts'];
            private array $group_type = ['counts' => 'ignored'];

            public function groupTypes(): array { return $this->group_type; }
        };
    }

    beforeEach(function () {
        $this->previousCache = $GLOBALS['wp_object_cache'] ?? null;
    });

    afterEach(function () {
        $GLOBALS['wp_object_cache'] = $this->previousCache;
    });

    test('an indexing run reads posts, meta and terms outside the persistent cache', function () {
        $GLOBALS['wp_object_cache'] = redisLikeCache();

        $groupsDuringRun = ObjectCacheIsolation::run(fn () => $GLOBALS['wp_object_cache']->ignored_groups);

        expect($groupsDuringRun)->toContain('posts', 'post_meta', 'terms', 'category_relationships');
    });

    test('the cache goes back to its exact state afterwards, even when the run fails', function () {
        $GLOBALS['wp_object_cache'] = $cache = redisLikeCache();

        try {
            ObjectCacheIsolation::run(fn () => throw new \RuntimeException('indexing failed'));
        } catch (\RuntimeException) {
        }

        expect($cache->ignored_groups)->toBe(['counts'])
            ->and($cache->groupTypes())->toBe(['counts' => 'ignored']);
    });

    test('any other object cache simply runs the callback', function () {
        $GLOBALS['wp_object_cache'] = new \stdClass;

        expect(ObjectCacheIsolation::run(fn () => 'done'))->toBe('done');
    });
}
