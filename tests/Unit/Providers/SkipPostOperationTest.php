<?php

declare(strict_types=1);

namespace {
    // The context every Action Scheduler job and every quick edit runs in.
    if (! defined('DOING_AJAX')) {
        define('DOING_AJAX', true);
    }

    if (! function_exists('wp_is_post_revision')) {
        function wp_is_post_revision($post) { return $GLOBALS['revisions'][$post] ?? false; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Providers {

    use Pollora\MeiliScout\Providers\SingleIndexingServiceProvider;
    use ReflectionMethod;

    function skips(\WP_Post $post): bool
    {
        $method = new ReflectionMethod(SingleIndexingServiceProvider::class, 'shouldSkipPostOperation');

        return $method->invoke(new SingleIndexingServiceProvider, $post->ID, $post);
    }

    beforeEach(function () {
        $GLOBALS['revisions'] = [];
    });

    /**
     * WooCommerce updates a product price with `update_post_meta()` from inside an
     * Action Scheduler job, which runs over admin-ajax.php. Skipping AJAX left the
     * index holding the old price with nothing to say so.
     */
    test('a post saved during an AJAX request is still indexed', function () {
        expect(skips(new \WP_Post(116, 'product')))->toBeFalse();
    });

    test('a revision is skipped', function () {
        $GLOBALS['revisions'] = [117 => 116];

        expect(skips(new \WP_Post(117, 'product', post_status: 'inherit')))->toBeTrue();
    });

    test('an auto-draft is skipped', function () {
        expect(skips(new \WP_Post(118, 'product', post_status: 'auto-draft')))->toBeTrue();
    });
}
