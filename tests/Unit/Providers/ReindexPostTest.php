<?php

declare(strict_types=1);

namespace {
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

    /** Records the posts it is asked to index, without reaching an engine. */
    final class RecordingPostIndexer extends PostSingleIndexer
    {
        /** @var list<int> */
        public array $indexed = [];

        public function __construct() {}

        public function indexPost(int|\WP_Post $post): bool
        {
            $this->indexed[] = $post instanceof \WP_Post ? $post->ID : $post;

            return true;
        }
    }

    function reindexing(RecordingPostIndexer $indexer): SingleIndexingServiceProvider
    {
        $provider = new SingleIndexingServiceProvider;
        (new ReflectionProperty($provider, 'postIndexer'))->setValue($provider, $indexer);

        return $provider;
    }

    beforeEach(function () {
        $GLOBALS['posts'] = [125 => new \WP_Post(125, 'product')];
        $GLOBALS['filters'] = [];
        $GLOBALS['revisions'] = [];
    });

    test('a post another plugin says changed is indexed', function () {
        $indexer = new RecordingPostIndexer;

        reindexing($indexer)->handlePostReindex(125);

        expect($indexer->indexed)->toBe([125]);
    });

    test('a reindex is skipped while indexing is', function () {
        $GLOBALS['filters']['meiliscout/skip_indexing'] = true;
        $indexer = new RecordingPostIndexer;

        reindexing($indexer)->handlePostReindex(125);

        expect($indexer->indexed)->toBe([]);
    });

    test('a post that does not exist is not indexed', function () {
        $indexer = new RecordingPostIndexer;

        reindexing($indexer)->handlePostReindex(404);

        expect($indexer->indexed)->toBe([]);
    });
}
