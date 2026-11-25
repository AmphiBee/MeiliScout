<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Services {

    use Pollora\MeiliScout\Services\MeilisearchBacklog;

    require_once __DIR__.'/meilisearchTasks.php';

    const NOW = '2026-11-25T10:30:00Z';

    /**
     * A batch Meilisearch is processing, as GET /batches lists it.
     *
     * @param  list<string>  $steps
     * @return array<string, mixed>
     */
    function processingBatch(array $indexUids, float $percentage, array $steps, array $embedderRequests = []): array
    {
        return [
            'status' => 'processing',
            'details' => ['receivedDocuments' => 1666, 'indexedDocuments' => null],
            'stats' => ['indexUids' => array_fill_keys($indexUids, 1), ...($embedderRequests === [] ? [] : ['embedderRequests' => $embedderRequests])],
            'progress' => ['percentage' => $percentage, 'steps' => array_map(static fn (string $step) => ['currentStep' => $step, 'finished' => 0, 'total' => 1], $steps)],
        ];
    }

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        putenv('MEILI_INDEX_PREFIX');
    });

    test('nothing pending, nothing to wait for', function () {
        $client = tasksClient($this, [task(1, 'succeeded', 'example_test_posts', NOW)]);

        expect((new MeilisearchBacklog($client))->read())->toBe(['tasks' => 0, 'percentage' => null, 'embedding' => null]);
    });

    test('a rebuild still processed: its tasks, the swap, and the calls to the embedder, Meilisearch\'s percentage standing still meanwhile', function () {
        $client = tasksClient($this, [
            task(253, 'processing', 'example_test_posts__rebuild', NOW),
            task(254, 'processing', 'example_test_posts__rebuild', NOW),
            task(272, 'enqueued', null, NOW, 'indexSwap', ['swaps' => [['indexes' => ['example_test_posts', 'example_test_posts__rebuild']]]]),
            task(273, 'enqueued', 'example_test_posts__rebuild', NOW, 'indexDeletion'),
        ], [processingBatch(['example_test_posts__rebuild'], 41.145836, ['processing tasks', 'indexing', 'extracting embeddings', 'document'], ['total' => 118, 'failed' => 2])]);

        expect((new MeilisearchBacklog($client))->read())->toBe(['tasks' => 4, 'percentage' => null, 'embedding' => ['documents' => 1666, 'requests' => 118, 'failed' => 2]]);
    });

    test('the tasks of other sites sharing Meilisearch are not the site\'s', function () {
        $client = tasksClient($this, [
            task(1, 'processing', 'other_site_posts', NOW),
            task(2, 'enqueued', null, NOW, 'taskDeletion'),
            task(3, 'enqueued', 'example_test_taxonomies', NOW),
        ], [processingBatch(['other_site_posts'], 80.0, ['extracting embeddings'])]);

        expect((new MeilisearchBacklog($client))->read())->toBe(['tasks' => 1, 'percentage' => null, 'embedding' => null]);
    });

    test('a batch that only indexes words gives its percentage', function () {
        $client = tasksClient($this, [task(1, 'processing', 'example_test_posts', NOW)], [processingBatch(['example_test_posts'], 12.0, ['processing tasks', 'indexing', 'extracting words'])]);

        expect((new MeilisearchBacklog($client))->read())->toBe(['tasks' => 1, 'percentage' => 12.0, 'embedding' => null]);
    });
}
