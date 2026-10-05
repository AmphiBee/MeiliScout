<?php

declare(strict_types=1);

namespace {
    if (! function_exists('current_time')) {
        function current_time($type) { return '2026-10-05 12:00:00'; }
    }

    if (! function_exists('update_option')) {
        function update_option($option, $value) { return true; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Services {

    use Meilisearch\Client;
    use Meilisearch\Endpoints\Indexes;
    use Pollora\MeiliScout\Contracts\HasDependentDocuments;
    use Pollora\MeiliScout\Contracts\Indexable;
    use Pollora\MeiliScout\Services\AbstractSingleIndexer;

    /** Records what the indexer asks of the index, in order. */
    class RecordingIndex extends Indexes
    {
        /** @var list<array{string, mixed}> */
        public array $calls = [];

        public function __construct() {}

        public function addDocuments(array $documents, ?string $primaryKey = null)
        {
            $this->calls[] = ['add', $documents];

            return [];
        }

        public function deleteDocuments(array $options): array
        {
            $this->calls[] = ['deleteWhere', $options['filter']];

            return [];
        }

        public function deleteDocument($documentId): array
        {
            $this->calls[] = ['delete', $documentId];

            return [];
        }
    }

    final class RecordingClient extends Client
    {
        public function __construct(public RecordingIndex $recorded = new RecordingIndex) {}

        public function index(string $uid): Indexes
        {
            return $this->recorded;
        }
    }

    /** An indexer over items that are arrays with an `id`, the index left as it is. */
    final class ArrayIndexer extends AbstractSingleIndexer
    {
        public function __construct(Indexable $indexable, RecordingClient $client)
        {
            $this->indexable = $indexable;
            $this->client = $client;
            $this->logOptionKey = 'test';
            $this->initializeOperationLog();
        }

        protected function createIndexable(): Indexable
        {
            return $this->indexable;
        }

        protected function getLogOptionKey(): string
        {
            return 'test';
        }

        protected function shouldIndex(mixed $item): bool
        {
            return true;
        }

        protected function getItemId(mixed $item): int|string
        {
            return $item['id'];
        }

        protected function getItemName(mixed $item): string
        {
            return (string) $item['id'];
        }

        protected function ensureIndexExists(): void {}
    }

    function indexable(): Indexable
    {
        return new class implements Indexable
        {
            public function getIndexName(): string { return 'posts'; }

            public function getPrimaryKey(): string { return 'ID'; }

            public function getIndexSettings(): array { return []; }

            public function getItems(?int $offset = null, ?int $limit = null): iterable { return []; }

            public function formatForIndexing(mixed $item): array { return ['ID' => $item['id'], 'title' => 'Lotion']; }

            public function formatForSearch(array $hit): mixed { return $hit; }
        };
    }

    function withVariants(): Indexable
    {
        return new class implements Indexable, HasDependentDocuments
        {
            public function getIndexName(): string { return 'posts'; }

            public function getPrimaryKey(): string { return 'ID'; }

            public function getIndexSettings(): array { return []; }

            public function getItems(?int $offset = null, ?int $limit = null): iterable { return []; }

            public function formatForIndexing(mixed $item): array { return ['ID' => $item['id'], 'title' => 'Lotion']; }

            public function formatForSearch(array $hit): mixed { return $hit; }

            public function dependentDocuments(array $document, mixed $item): array
            {
                return [['ID' => $document['ID'].'-0', 'product' => $document['ID']]];
            }

            public function dependentsFilter(array $itemIds): string
            {
                return 'product IN ['.implode(', ', $itemIds).']';
            }
        };
    }

    beforeEach(function () {
        $GLOBALS['filters'] = [];
    });

    test('an item writes its documents first, then drops the dependents it no longer brings along', function () {
        $client = new RecordingClient;

        new ArrayIndexer(withVariants(), $client)->indexItem(['id' => 7]);

        expect($client->recorded->calls)->toBe([
            ['add', [['ID' => 7, 'title' => 'Lotion'], ['ID' => '7-0', 'product' => 7]]],
            ['deleteWhere', '(product IN [7]) AND NOT ID IN ["7-0"]'],
        ]);
    });

    test('a request that fails before the cleanup leaves the previous dependents in place', function () {
        $client = new RecordingClient(new class extends RecordingIndex
        {
            public function addDocuments(array $documents, ?string $primaryKey = null)
            {
                throw new \RuntimeException('413 Payload Too Large');
            }
        });

        expect(fn () => new ArrayIndexer(withVariants(), $client)->indexItem(['id' => 7]))->toThrow(\RuntimeException::class);
        expect($client->recorded->calls)->toBe([]);
    });

    test('documents are sent in requests no larger than the payload limit', function () {
        $GLOBALS['filters']['meiliscout/max_payload_bytes'] = 30;
        $client = new RecordingClient;

        new ArrayIndexer(withVariants(), $client)->indexItem(['id' => 7]);

        expect(array_column($client->recorded->calls, 0))->toBe(['add', 'add', 'deleteWhere']);
    });

    test('an item of an indexable without dependents writes its document alone', function () {
        $client = new RecordingClient;

        new ArrayIndexer(indexable(), $client)->indexItem(['id' => 7]);

        expect($client->recorded->calls)->toBe([['add', [['ID' => 7, 'title' => 'Lotion']]]]);
    });

    test('a removed item takes the documents it brought along with it', function () {
        $client = new RecordingClient;

        new ArrayIndexer(withVariants(), $client)->removeItem(7);

        expect($client->recorded->calls)->toBe([['delete', 7], ['deleteWhere', 'product IN [7]']]);
    });

    test('a removed item of an indexable without dependents deletes its document alone', function () {
        $client = new RecordingClient;

        new ArrayIndexer(indexable(), $client)->removeItem(7);

        expect($client->recorded->calls)->toBe([['delete', 7]]);
    });
}
