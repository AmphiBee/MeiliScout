<?php

declare(strict_types=1);

namespace {
    if (! function_exists('get_posts')) {
        function get_posts($args)
        {
            $GLOBALS['get_posts_args'][] = $args;

            // A site's posts, by id, of the types asked for, as get_posts() pages them
            $posts = array_values(array_filter($GLOBALS['site_posts'] ?? [], fn ($post) => in_array($post->post_type, (array) $args['post_type'], true)));

            return array_slice($posts, $args['offset'] ?? 0, $args['posts_per_page']);
        }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post {

    use Pollora\MeiliScout\Indexables\PostIndexable;

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        $GLOBALS['get_posts_args'] = [];
        $GLOBALS['site_posts'] = [];
        update_option('meiliscout/indexed_post_types', ['post']);
        update_option('meiliscout/indexed_meta_keys', ['_price']);
    });

    test('a full run reads only the statuses a single save would index', function () {
        iterator_to_array((new PostIndexable)->getItems());

        expect($GLOBALS['get_posts_args'][0]['post_status'])->toBe(PostIndexable::indexableStatuses())
            ->and(PostIndexable::indexableStatuses())->toBe(['publish']);
    });

    test('a chunk is a position in every post type at once, not in each type', function () {
        update_option('meiliscout/indexed_post_types', ['post', 'page']);
        update_option('meiliscout/indexing.posts_per_page', 2);
        // Ids 1-5 are posts, 6-8 pages
        foreach (range(1, 8) as $id) {
            $GLOBALS['site_posts'][] = new \WP_Post($id, $id <= 5 ? 'post' : 'page');
        }

        $ids = fn (?int $offset, ?int $limit) => array_map(fn ($post) => $post->ID, iterator_to_array((new PostIndexable)->getItems($offset, $limit), false));

        expect($ids(null, null))->toBe(range(1, 8))
            ->and(array_column($GLOBALS['get_posts_args'], 'offset'))->toBe([0, 2, 4, 6, 8])
            ->and($ids(0, 3))->toBe([1, 2, 3])
            ->and($ids(3, 3))->toBe([4, 5, 6])
            ->and($ids(6, 3))->toBe([7, 8])
            ->and($ids(9, 3))->toBe([]);
    });
}
