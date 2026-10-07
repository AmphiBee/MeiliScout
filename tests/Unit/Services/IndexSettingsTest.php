<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Services {

    use Meilisearch\Client;
    use Meilisearch\Endpoints\Indexes;
    use Meilisearch\Exceptions\ApiException;
    use Nyholm\Psr7\Response;
    use Pollora\MeiliScout\Services\AbstractSingleIndexer;
    use Pollora\MeiliScout\Services\IndexSettings;
    use Pollora\MeiliScout\Services\PostSingleIndexer;

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        (new \ReflectionProperty(AbstractSingleIndexer::class, 'indexExistsCache'))->setValue(null, []);
    });

    function postIndexerWith(Client $client): PostSingleIndexer
    {
        $indexer = new PostSingleIndexer;
        (new \ReflectionProperty(AbstractSingleIndexer::class, 'client'))->setValue($indexer, $client);

        return $indexer;
    }

    function ensureIndex(PostSingleIndexer $indexer): void
    {
        (new \ReflectionMethod(AbstractSingleIndexer::class, 'ensureIndexExists'))->invoke($indexer);
    }

    test('settings are sent the first time, then only when they change', function () {
        $index = $this->createMock(Indexes::class);
        $index->expects($this->exactly(2))->method('updateSettings');

        expect(IndexSettings::pushIfChanged($index, 'posts', ['sortableAttributes' => ['post_date']]))->toBeTrue()
            ->and(IndexSettings::pushIfChanged($index, 'posts', ['sortableAttributes' => ['post_date']]))->toBeFalse()
            ->and(IndexSettings::pushIfChanged($index, 'posts', ['sortableAttributes' => ['post_date', 'post_title']]))->toBeTrue();
    });

    test('a save on an existing index leaves its unchanged settings alone', function () {
        $index = $this->createMock(Indexes::class);
        $index->expects($this->once())->method('updateSettings');

        $client = $this->createMock(Client::class);
        $client->method('index')->willReturn($index);
        $client->expects($this->never())->method('createIndex');

        $indexer = postIndexerWith($client);
        ensureIndex($indexer);
        ensureIndex($indexer);
        ensureIndex(postIndexerWith($client));
    });

    test('a missing index is created with its settings', function () {
        $index = $this->createMock(Indexes::class);
        $index->expects($this->once())->method('updateSettings');

        $client = $this->createMock(Client::class);
        $client->method('index')->willReturn($index);
        $client->method('getIndex')->willThrowException(new ApiException(new Response(404), ['message' => 'Index `posts` not found.', 'code' => 'index_not_found']));
        $client->expects($this->once())->method('createIndex')->with('posts', ['primaryKey' => 'ID']);

        ensureIndex(postIndexerWith($client));
    });

    test('an error other than a missing index is not taken for one', function () {
        $client = $this->createMock(Client::class);
        $client->method('index')->willReturn($this->createMock(Indexes::class));
        $client->method('getIndex')->willThrowException(new ApiException(new Response(403), ['message' => 'Invalid API key', 'code' => 'invalid_api_key']));
        $client->expects($this->never())->method('createIndex');
        $errorLog = ini_set('error_log', '/dev/null');

        expect(fn () => ensureIndex(postIndexerWith($client)))->toThrow(ApiException::class);

        ini_set('error_log', (string) $errorLog);
    });
}
