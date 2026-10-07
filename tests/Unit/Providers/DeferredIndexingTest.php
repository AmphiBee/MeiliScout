<?php

declare(strict_types=1);

namespace {
    if (! function_exists('add_action')) {
        function add_action($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['actions'][$hook][] = ['callback' => $callback, 'priority' => $priority]; return true; }
    }
    if (! function_exists('get_post')) {
        function get_post($post) { return $GLOBALS['posts'][$post] ?? null; }
    }
    if (! function_exists('wp_is_post_revision')) {
        function wp_is_post_revision($post) { return $GLOBALS['revisions'][$post] ?? false; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Providers {

    use Pollora\MeiliScout\Providers\SingleIndexingServiceProvider;
    use Pollora\MeiliScout\Services\PostSingleIndexer;
    use ReflectionProperty;

    /** Records what it is asked to do, in order, without reaching an engine. */
    final class OperationsRecorder extends PostSingleIndexer
    {
        /** @var list<string> */
        public array $operations = [];

        public function __construct() {}

        public function indexPost(int|\WP_Post $post): bool
        {
            $this->operations[] = 'index '.($post instanceof \WP_Post ? $post->ID : $post);

            return true;
        }

        public function removePost(int $postId): bool
        {
            $this->operations[] = "remove {$postId}";

            return true;
        }
    }

    function deferring(OperationsRecorder $indexer): SingleIndexingServiceProvider
    {
        $provider = new SingleIndexingServiceProvider;
        (new ReflectionProperty($provider, 'postIndexer'))->setValue($provider, $indexer);

        return $provider;
    }

    beforeEach(function () {
        $GLOBALS['posts'] = [7 => new \WP_Post(7, 'post'), 8 => new \WP_Post(8, 'post')];
        $GLOBALS['filters'] = [];
        $GLOBALS['revisions'] = [];
        $GLOBALS['actions'] = [];
        $GLOBALS['wp_options'] = [];
    });

    test('saving a post indexes it once, at the end of the request', function () {
        $indexer = new OperationsRecorder;
        $provider = deferring($indexer);
        update_option('meiliscout/indexed_meta_keys', ['price']);

        $provider->handlePostStatusChange('publish', 'draft', $GLOBALS['posts'][7]);
        $provider->handlePostSave(7, $GLOBALS['posts'][7], true);
        $provider->handlePostMetaUpdate(1, 7, 'price', 10);
        $provider->handlePostMetaUpdate(2, 7, 'price', 12);

        expect($indexer->operations)->toBe([])
            ->and($GLOBALS['actions']['shutdown'])->toHaveCount(1);

        $provider->runPendingTasks();

        expect($indexer->operations)->toBe(['index 7']);
    });

    test('the last change to a post wins', function () {
        $indexer = new OperationsRecorder;
        $provider = deferring($indexer);

        $provider->handlePostSave(7, $GLOBALS['posts'][7], true);
        $provider->handlePostSave(8, $GLOBALS['posts'][8], true);
        $provider->handlePostDelete(7);
        $provider->runPendingTasks();

        expect($indexer->operations)->toBe(['index 8', 'remove 7']);
    });

    test('tasks run once, however often shutdown fires', function () {
        $indexer = new OperationsRecorder;
        $provider = deferring($indexer);

        $provider->handlePostSave(7, $GLOBALS['posts'][7], true);
        $provider->runPendingTasks();
        $provider->runPendingTasks();

        expect($indexer->operations)->toBe(['index 7']);
    });

    test('a meta key that is not indexed does not re-index the post', function () {
        $indexer = new OperationsRecorder;
        $provider = deferring($indexer);
        update_option('meiliscout/indexed_meta_keys', ['price']);

        $provider->handlePostMetaUpdate(1, 7, '_edit_lock', '1700000000:1');
        $provider->handlePostMetaUpdate(2, 7, 'color', 'red');
        $provider->runPendingTasks();

        expect($indexer->operations)->toBe([]);
    });

    test('without selected meta keys, every key but WordPress internals re-indexes the post', function () {
        $indexer = new OperationsRecorder;
        $provider = deferring($indexer);

        $provider->handlePostMetaUpdate(1, 7, '_edit_lock', '1700000000:1');
        $provider->runPendingTasks();
        expect($indexer->operations)->toBe([]);

        $provider->handlePostMetaUpdate(2, 7, 'color', 'red');
        $provider->runPendingTasks();
        expect($indexer->operations)->toBe(['index 7']);
    });

    test('a filter can make any meta key re-index the post', function () {
        $indexer = new OperationsRecorder;
        $provider = deferring($indexer);
        update_option('meiliscout/indexed_meta_keys', ['price']);
        $GLOBALS['filters']['meiliscout/reindex_on_meta_change'] = true;

        $provider->handlePostMetaUpdate(1, 7, '_variation_price', 9);
        $provider->runPendingTasks();

        expect($indexer->operations)->toBe(['index 7']);
    });

    test('a task that fails does not stop the others', function () {
        $indexer = new class extends PostSingleIndexer {
            public array $operations = [];
            public function __construct() {}
            public function indexPost(int|\WP_Post $post): bool
            {
                $id = $post instanceof \WP_Post ? $post->ID : $post;
                if ($id === 7) {
                    throw new \RuntimeException('Meilisearch is down');
                }
                $this->operations[] = "index {$id}";

                return true;
            }
        };
        $provider = new SingleIndexingServiceProvider;
        (new ReflectionProperty($provider, 'postIndexer'))->setValue($provider, $indexer);
        $errorLog = ini_set('error_log', '/dev/null');

        $provider->handlePostSave(7, $GLOBALS['posts'][7], true);
        $provider->handlePostSave(8, $GLOBALS['posts'][8], true);
        $provider->runPendingTasks();
        ini_set('error_log', (string) $errorLog);

        expect($indexer->operations)->toBe(['index 8']);
    });
}
