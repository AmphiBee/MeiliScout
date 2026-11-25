<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Embedders {

    use Meilisearch\Client;
    use Meilisearch\Endpoints\Indexes;
    use Pollora\MeiliScout\Embedders\Embedders;
    use Pollora\MeiliScout\Embedders\EmbedderStatus;

    use function Pollora\MeiliScout\Tests\Unit\Services\task;
    use function Pollora\MeiliScout\Tests\Unit\Services\tasksClient;

    require_once __DIR__.'/../Services/meilisearchTasks.php';

    const POSTS_INDEX = 'example_test_posts';

    /**
     * A client whose posts index has 120 documents, 100 with a vector, and the given tasks.
     */
    function statusClient($test, array $tasks): Client
    {
        $index = (fn () => $this->createMock(Indexes::class))->call($test);
        $index->method('stats')->willReturn(['numberOfDocuments' => 120, 'numberOfEmbeddedDocuments' => 100]);
        $client = tasksClient($test, $tasks);
        $client->method('index')->with(POSTS_INDEX)->willReturn($index);

        return $client;
    }

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        putenv('MEILI_INDEX_PREFIX');
        $this->errorLog = ini_set('error_log', '/dev/null');
    });

    afterEach(function () {
        ini_set('error_log', (string) $this->errorLog);
    });

    test('each embedder of an index gets its documents without a vector', function () {
        Embedders::declare('mistral', ['source' => 'rest', 'request' => ['model' => 'mistral-embed']]);
        Embedders::declare('local', ['source' => 'ollama', 'model' => 'nomic-embed-text']);

        expect((new EmbedderStatus(statusClient($this, [])))->rows())->toBe([
            ['index' => POSTS_INDEX, 'embedder' => 'mistral', 'source' => 'rest', 'model' => 'mistral-embed', 'documents' => 120, 'without vector' => 20, 'failed tasks' => 0, 'last failure' => '-'],
            ['index' => POSTS_INDEX, 'embedder' => 'local', 'source' => 'ollama', 'model' => 'nomic-embed-text', 'documents' => 120, 'without vector' => 20, 'failed tasks' => 0, 'last failure' => '-'],
        ]);
    });

    test('failures an index got over since are dated, not counted', function () {
        Embedders::declare('mistral', ['source' => 'rest']);
        $client = statusClient($this, [
            task(66, 'failed', POSTS_INDEX, '2026-11-20T11:05:12Z'),
            task(71, 'failed', POSTS_INDEX, '2026-11-20T11:05:14Z'),
            task(90, 'succeeded', POSTS_INDEX, '2026-11-21T09:00:00Z'),
            task(95, 'failed', 'example_test_taxonomies', '2026-11-24T09:00:00Z'),
        ]);

        expect((new EmbedderStatus($client))->rows()[0])->toMatchArray(['failed tasks' => 0, 'last failure' => '2026-11-20 11:05']);
    });

    test('failures after the last task that succeeded are still to deal with', function () {
        Embedders::declare('mistral', ['source' => 'rest']);
        $client = statusClient($this, [
            task(10, 'succeeded', POSTS_INDEX, '2026-11-19T08:00:00Z'),
            task(11, 'failed', POSTS_INDEX, '2026-11-20T11:05:12Z'),
            task(12, 'failed', POSTS_INDEX, '2026-11-20T11:05:14Z'),
        ]);

        expect((new EmbedderStatus($client))->rows()[0])->toMatchArray(['failed tasks' => 2, 'last failure' => '2026-11-20 11:05']);
    });

    test('an index that never succeeded counts every failure', function () {
        Embedders::declare('mistral', ['source' => 'rest']);
        $client = statusClient($this, [task(1, 'failed', POSTS_INDEX, '2026-11-20T11:05:12Z', 'settingsUpdate')]);

        expect((new EmbedderStatus($client))->rows()[0]['failed tasks'])->toBe(1);
    });

    test('an index Meilisearch cannot describe is shown as unknown', function () {
        Embedders::declare('mistral', ['source' => 'rest'], ['manual_chunks']);

        $client = $this->createMock(Client::class);
        $client->method('index')->willThrowException(new \RuntimeException('Index not found.'));

        expect((new EmbedderStatus($client))->rows()[0])->toMatchArray([
            'index' => 'example_test_manual_chunks',
            'documents' => 'unknown',
            'without vector' => 'unknown',
            'failed tasks' => 'unknown',
            'last failure' => 'unknown',
        ]);
    });

    test('without embedders, nothing is asked of Meilisearch', function () {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('index');

        expect((new EmbedderStatus($client))->rows())->toBe([]);
    });
}
