<?php

declare(strict_types=1);

namespace {
    if (! function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook, $args = []) {
            foreach ($GLOBALS['cron'] ?? [] as $event) {
                if ($event['hook'] === $hook && $event['args'] === $args) {
                    return $event['timestamp'];
                }
            }

            return false;
        }
    }

    if (! function_exists('wp_schedule_single_event')) {
        function wp_schedule_single_event($timestamp, $hook, $args = []) {
            $GLOBALS['cron'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args];

            return true;
        }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Documents {

    use Meilisearch\Client;
    use Meilisearch\Endpoints\Indexes;
    use Meilisearch\Exceptions\ApiException;
    use Nyholm\Psr7\Response;
    use Pollora\MeiliScout\Contracts\Indexable;
    use Pollora\MeiliScout\Documents\DocumentWriter;
    use Pollora\MeiliScout\Documents\ItemSync;
    use Pollora\MeiliScout\Services\AsyncIndexingQueue;
    use Pollora\MeiliScout\Services\IndexNames;

    const QUEUE_OPTION = 'meiliscout/async_queue';

    /**
     * The passages of a manual, as a site would index them.
     */
    final class ManualChunks implements Indexable
    {
        public function getIndexName(): string
        {
            return IndexNames::target('manual_chunks');
        }

        public function getPrimaryKey(): string
        {
            return 'id';
        }

        public function getIndexSettings(): array
        {
            return ['filterableAttributes' => ['attachment_id', 'lang']];
        }

        public function getItems(?int $offset = null, ?int $limit = null): iterable
        {
            return [];
        }

        public function formatForIndexing(mixed $item): array
        {
            return ['id' => "m{$item['attachment']}_p{$item['page']}", 'attachment_id' => $item['attachment'], 'text' => $item['text']];
        }

        public function formatForSearch(array $hit): mixed
        {
            return $hit;
        }
    }

    /**
     * A client whose index calls are kept: [method, ...arguments].
     */
    function recordingClient($test, bool $indexExists, ?array &$calls = null): Client
    {
        $mock = fn (string $class) => (fn () => $this->createMock($class))->call($test);
        $calls = [];

        $index = $mock(Indexes::class);
        foreach (['addDocuments', 'deleteDocuments', 'updateSettings'] as $method) {
            $index->method($method)->willReturnCallback(function (...$arguments) use ($method, &$calls) {
                $calls[] = [$method, ...$arguments];

                return [];
            });
        }

        $client = $mock(Client::class);
        $client->method('index')->willReturn($index);
        $client->method('createIndex')->willReturnCallback(function (...$arguments) use (&$calls) {
            $calls[] = ['createIndex', ...$arguments];

            return [];
        });

        if ($indexExists) {
            $client->method('getIndex')->willReturn($index);
        } else {
            $client->method('getIndex')->willThrowException(new ApiException(new Response(404), ['message' => 'Index not found.', 'code' => 'index_not_found']));
        }

        return $client;
    }

    function chunks(int $attachment, int $pages): array
    {
        return array_map(fn (int $page) => ['attachment' => $attachment, 'page' => $page, 'text' => "Page {$page}"], range(1, $pages));
    }

    function registerIndexable(Indexable $indexable): void
    {
        $GLOBALS['filters']['meiliscout/indexables'] = [$indexable];
    }

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        $GLOBALS['cron'] = [];
        putenv('MEILI_INDEX_PREFIX');
    });

    test('items are formatted and sent to a new index, created with the indexable\'s settings first', function () {
        $sync = new ItemSync(new DocumentWriter(recordingClient($this, false, $calls)), null);

        $sent = $sync->sync(new ManualChunks, chunks(123, 2));

        expect($sent)->toBe(2)
            ->and(array_column($calls, 0))->toBe(['createIndex', 'updateSettings', 'addDocuments'])
            ->and($calls[0])->toBe(['createIndex', 'example_test_manual_chunks', ['primaryKey' => 'id']])
            ->and($calls[1][1])->toBe(['filterableAttributes' => ['attachment_id', 'lang']])
            ->and($calls[2][1])->toBe([
                ['id' => 'm123_p1', 'attachment_id' => 123, 'text' => 'Page 1'],
                ['id' => 'm123_p2', 'attachment_id' => 123, 'text' => 'Page 2'],
            ])
            ->and($calls[2][2])->toBe('id');
    });

    test('an existing index keeps its unchanged settings, and documents go in batches', function () {
        $GLOBALS['filters']['meiliscout/bulk_batch_size'] = 2;
        $client = recordingClient($this, true, $calls);
        $sync = new ItemSync(new DocumentWriter($client), null);

        $sync->sync(new ManualChunks, chunks(1, 5));
        $sync->sync(new ManualChunks, chunks(2, 1));

        expect(array_column($calls, 0))->toBe(['updateSettings', 'addDocuments', 'addDocuments', 'addDocuments', 'addDocuments'])
            ->and(array_map('count', array_column(array_slice($calls, 1), 1)))->toBe([2, 2, 1, 1]);
    });

    test('documents are deleted by filter; an index that does not exist has none to delete', function () {
        (new ItemSync(new DocumentWriter(recordingClient($this, true, $calls)), null))->delete(new ManualChunks, 'attachment_id = 123');
        (new ItemSync(new DocumentWriter(recordingClient($this, false, $missing)), null))->delete(new ManualChunks, 'attachment_id = 123');

        expect($calls)->toBe([['deleteDocuments', ['filter' => 'attachment_id = 123']]])
            ->and($missing)->toBe([]);
    });

    test('no items, nothing sent', function () {
        $sync = new ItemSync(new DocumentWriter(recordingClient($this, false, $calls)), null);

        expect($sync->sync(new ManualChunks, []))->toBe(0)
            ->and($calls)->toBe([]);
    });

    test('meiliscout/skip_indexing stops the writes, as for posts', function () {
        $GLOBALS['filters']['meiliscout/skip_indexing'] = true;
        $sync = new ItemSync(new DocumentWriter(recordingClient($this, true, $calls)), null);

        $sync->sync(new ManualChunks, chunks(1, 1));
        $sync->delete(new ManualChunks, 'attachment_id = 1');

        expect($calls)->toBe([]);
    });

    test('with the asynchronous queue, the documents are formatted at once and sent by WP-Cron', function () {
        registerIndexable(new ManualChunks);
        $sync = new ItemSync(new DocumentWriter(recordingClient($this, true, $direct)), new AsyncIndexingQueue);

        $sync->sync(new ManualChunks, chunks(7, 2));
        $sync->delete(new ManualChunks, 'attachment_id = 3');
        $queue = array_values($GLOBALS['wp_options'][QUEUE_OPTION]);

        expect($direct)->toBe([])
            ->and($GLOBALS['cron'][0]['hook'])->toBe('meiliscout_process_async_queue')
            ->and(array_column($queue, 'action'))->toBe([ItemSync::SYNC, ItemSync::DELETE])
            ->and($queue[0]['type'])->toBe(ItemSync::TASK_TYPE)
            ->and($queue[0]['extra']['index'])->toBe('example_test_manual_chunks')
            ->and($queue[0]['extra']['documents'][1]['id'])->toBe('m7_p2')
            ->and($queue[1]['extra']['filter'])->toBe('attachment_id = 3')
            ->and(array_column(array_column($queue, 'extra'), 'label'))->toBe(['example_test_manual_chunks (2)', 'example_test_manual_chunks: attachment_id = 3']);

        // What WP-Cron runs
        $writer = new ItemSync(new DocumentWriter(recordingClient($this, true, $sent)), null);
        foreach ($queue as $task) {
            $writer->run($task['action'], $task['extra']);
        }

        expect(array_column($sent, 0))->toBe(['updateSettings', 'addDocuments', 'deleteDocuments'])
            ->and($sent[1][1][0]['id'])->toBe('m7_p1')
            ->and($sent[2][1])->toBe(['filter' => 'attachment_id = 3']);
    });

    test('the same write queued twice runs once, in the order of its last addition', function () {
        registerIndexable(new ManualChunks);
        $sync = new ItemSync(null, new AsyncIndexingQueue);

        $sync->sync(new ManualChunks, chunks(7, 1));
        $sync->delete(new ManualChunks, 'attachment_id = 7');
        $sync->sync(new ManualChunks, chunks(7, 1));

        expect(array_column($GLOBALS['wp_options'][QUEUE_OPTION], 'action'))->toBe([ItemSync::DELETE, ItemSync::SYNC]);
    });

    test('an indexable the queue could not find again is written at once', function () {
        $sync = new ItemSync(new DocumentWriter(recordingClient($this, true, $calls)), new AsyncIndexingQueue);

        $sync->sync(new ManualChunks, chunks(1, 1));

        expect($GLOBALS['wp_options'])->not->toHaveKey(QUEUE_OPTION)
            ->and(array_column($calls, 0))->toBe(['updateSettings', 'addDocuments']);
    });

    test('a queued task whose indexable is gone fails, to be kept in the activity log', function () {
        $writer = new ItemSync(new DocumentWriter(recordingClient($this, true, $calls)), null);

        expect(fn () => $writer->run(ItemSync::SYNC, ['index' => 'example_test_manual_chunks', 'documents' => []]))
            ->toThrow(\RuntimeException::class);
    });

    test('the indexables are those of meiliscout/indexables, whatever else a plugin returned', function () {
        $GLOBALS['filters']['meiliscout/indexables'] = [new ManualChunks, 'not an indexable', null];

        expect(ItemSync::indexables())->toHaveCount(1)
            ->and(ItemSync::indexables()[0])->toBeInstanceOf(ManualChunks::class);
    });

    test('without Meilisearch, a write says so instead of doing nothing', function () {
        expect(fn () => (new ItemSync(null, null))->sync(new ManualChunks, chunks(1, 1)))
            ->toThrow(\RuntimeException::class);
    });
}
