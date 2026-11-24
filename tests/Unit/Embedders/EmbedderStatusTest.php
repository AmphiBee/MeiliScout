<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Embedders {

    use Meilisearch\Client;
    use Meilisearch\Contracts\TasksResults;
    use Meilisearch\Endpoints\Indexes;
    use Pollora\MeiliScout\Embedders\Embedders;
    use Pollora\MeiliScout\Embedders\EmbedderStatus;

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        $this->errorLog = ini_set('error_log', '/dev/null');
    });

    afterEach(function () {
        ini_set('error_log', (string) $this->errorLog);
    });

    test('each embedder of an index gets its documents without a vector and the index\'s failed tasks', function () {
        Embedders::declare('mistral', ['source' => 'rest', 'request' => ['model' => 'mistral-embed']]);
        Embedders::declare('local', ['source' => 'ollama', 'model' => 'nomic-embed-text']);

        $index = $this->createMock(Indexes::class);
        $index->method('stats')->willReturn(['numberOfDocuments' => 120, 'numberOfEmbeddedDocuments' => 100]);
        $client = $this->createMock(Client::class);
        $client->method('index')->with('example_test_posts')->willReturn($index);
        $client->method('getTasks')->willReturn(new TasksResults(['results' => [], 'total' => 3, 'limit' => 1, 'from' => null, 'next' => null]));

        expect((new EmbedderStatus($client))->rows())->toBe([
            ['index' => 'example_test_posts', 'embedder' => 'mistral', 'source' => 'rest', 'model' => 'mistral-embed', 'documents' => 120, 'without vector' => 20, 'failed tasks' => 3],
            ['index' => 'example_test_posts', 'embedder' => 'local', 'source' => 'ollama', 'model' => 'nomic-embed-text', 'documents' => 120, 'without vector' => 20, 'failed tasks' => 3],
        ]);
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
        ]);
    });

    test('without embedders, nothing is asked of Meilisearch', function () {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('index');

        expect((new EmbedderStatus($client))->rows())->toBe([]);
    });
}
