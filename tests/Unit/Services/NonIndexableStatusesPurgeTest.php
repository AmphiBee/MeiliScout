<?php

declare(strict_types=1);

namespace {
    if (! function_exists('apply_filters')) {
        function apply_filters($hook, $value, ...$args) { return $value; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Services {

    use Meilisearch\Client;
    use Meilisearch\Endpoints\Indexes;
    use Pollora\MeiliScout\Indexables\PostIndexable;
    use Pollora\MeiliScout\Indexables\TaxonomyIndexable;
    use Pollora\MeiliScout\Services\Indexer;
    use Pollora\MeiliScout\Services\IndexingLogger;

    /**
     * Builds an Indexer around the given client, without touching the
     * configuration nor the log directory.
     */
    function indexerWith(Client $client, IndexingLogger $logger): Indexer
    {
        $reflection = new \ReflectionClass(Indexer::class);
        $indexer = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('client')->setValue($indexer, $client);
        $reflection->getProperty('logger')->setValue($indexer, $logger);

        return $indexer;
    }

    function purge(Indexer $indexer, object $indexable): void
    {
        (new \ReflectionMethod(Indexer::class, 'deleteNonIndexableStatuses'))->invoke($indexer, $indexable);
    }

    test('a full run deletes the posts whose status is not indexable', function () {
        $index = $this->createMock(Indexes::class);
        $index->expects($this->once())
            ->method('deleteDocuments')
            ->with(['filter' => "post_status NOT IN ['publish']"]);

        $client = $this->createMock(Client::class);
        $client->method('index')->with('posts')->willReturn($index);

        purge(indexerWith($client, $this->createMock(IndexingLogger::class)), new PostIndexable);
    });

    test('terms are left untouched', function () {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('index');

        purge(indexerWith($client, $this->createMock(IndexingLogger::class)), new TaxonomyIndexable);
    });

    test('an engine that cannot delete by filter does not stop the run', function () {
        $index = $this->createMock(Indexes::class);
        $index->method('deleteDocuments')->willThrowException(new \RuntimeException('unsupported'));

        $client = $this->createMock(Client::class);
        $client->method('index')->willReturn($index);

        $logger = $this->createMock(IndexingLogger::class);
        $logger->expects($this->once())->method('log')->with('error', $this->stringContains('unsupported'));

        purge(indexerWith($client, $logger), new PostIndexable);
    });
}
