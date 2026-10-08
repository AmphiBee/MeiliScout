<?php

declare(strict_types=1);

/*
 * A term's count, and the tree counts of its ancestors, follow the posts
 * that get or lose it: hide_empty on a hierarchical taxonomy depends on them.
 */

/**
 * Runs the indexing tasks queued during the test, as the end of the request would.
 */
function runQueuedIndexing(): void
{
    global $wp_filter;

    foreach ($wp_filter['shutdown']->callbacks ?? [] as $callbacks) {
        foreach ($callbacks as $callback) {
            if (is_array($callback['function']) && $callback['function'][1] === 'runPendingTasks') {
                call_user_func($callback['function']);
            }
        }
    }
}

/**
 * Waits for Meilisearch to process every task queued so far.
 */
function waitForMeilisearch(): void
{
    $client = \Pollora\MeiliScout\Services\ClientFactory::getClient();

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $pending = $client->getTasks((new \Meilisearch\Contracts\TasksQuery)->setStatuses(['enqueued', 'processing'])->setLimit(1));

        if ($pending->getTotal() === 0) {
            return;
        }

        usleep(100_000);
    }
}

/**
 * The categories Meilisearch returns for hide_empty, once the index caught up.
 *
 * @param  list<int>  $include
 * @return list<int>
 */
function nonEmptyCategories(array $include, int $expected): array
{
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $ids = array_values(get_terms(['taxonomy' => 'category', 'include' => $include, 'fields' => 'ids', 'use_meilisearch' => true]));

        if (count($ids) === $expected) {
            return $ids;
        }

        usleep(200_000);
    }

    return $ids;
}

test('publishing a post in a child term makes its empty ancestors non-empty for hide_empty', function () {
    $parent = wp_insert_term('Fraîcheur parent '.uniqid(), 'category');
    $child = wp_insert_term('Fraîcheur enfant '.uniqid(), 'category', ['parent' => $parent['term_id']]);
    runQueuedIndexing();
    $include = [(int) $parent['term_id'], (int) $child['term_id']];

    expect(nonEmptyCategories($include, 0))->toBe([]);

    $post = wp_insert_post(['post_title' => 'Fraîcheur', 'post_status' => 'publish', 'post_category' => [(int) $child['term_id']]]);
    runQueuedIndexing();

    try {
        expect(nonEmptyCategories($include, 2))->toHaveCount(2);

        wp_delete_post($post, true);
        runQueuedIndexing();

        expect(nonEmptyCategories($include, 0))->toBe([]);
    } finally {
        wp_delete_post($post, true);
        wp_delete_term((int) $child['term_id'], 'category');
        wp_delete_term((int) $parent['term_id'], 'category');
        runQueuedIndexing();
        waitForMeilisearch();
    }
})->group('integration');

test('terms given to a post outside a save reach its document, and object_ids', function () {
    $tag = wp_insert_term('Fraîcheur étiquette '.uniqid(), 'post_tag');
    $post = (int) get_posts(['post_type' => 'post', 'posts_per_page' => 1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC'])[0];
    $termIds = static fn (): array => array_map('intval', get_terms(['taxonomy' => 'post_tag', 'object_ids' => [$post], 'fields' => 'ids', 'use_meilisearch' => true]));

    try {
        wp_set_object_terms($post, [(int) $tag['term_id']], 'post_tag', true);
        runQueuedIndexing();
        waitForMeilisearch();

        expect($termIds())->toContain((int) $tag['term_id']);

        wp_remove_object_terms($post, [(int) $tag['term_id']], 'post_tag');
        runQueuedIndexing();
        waitForMeilisearch();

        expect($termIds())->not->toContain((int) $tag['term_id']);
    } finally {
        wp_delete_term((int) $tag['term_id'], 'post_tag');
        runQueuedIndexing();
        waitForMeilisearch();
    }
})->group('integration');
