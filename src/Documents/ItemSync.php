<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Documents;

use Pollora\MeiliScout\Config\RealtimeIndexing;
use Pollora\MeiliScout\Contracts\Indexable;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Indexables\TaxonomyIndexable;
use Pollora\MeiliScout\Services\AsyncIndexingQueue;
use Pollora\MeiliScout\Services\ClientFactory;

use function apply_filters;

/**
 * Keeps another plugin's index up to date: what meiliscout_sync_items() and meiliscout_delete_items() do.
 *
 * With the asynchronous queue on, the documents are formatted at once and
 * sent by WP-Cron, as posts are; the queue finds the indexable again among
 * the meiliscout/indexables ones. An indexable not registered there is
 * written at once. meiliscout/skip_indexing stops both, as for posts.
 */
final class ItemSync
{
    /**
     * The type of the queue's tasks about documents.
     */
    public const TASK_TYPE = 'documents';

    public const SYNC = 'sync';

    public const DELETE = 'delete';

    public function __construct(
        private readonly ?DocumentWriter $writer,
        private readonly ?AsyncIndexingQueue $queue,
    ) {}

    /**
     * Writes at once, or queues when the asynchronous queue is on.
     */
    public static function make(): self
    {
        $client = ClientFactory::getClient();
        $queued = RealtimeIndexing::mode() === RealtimeIndexing::ASYNC;

        return new self($client !== null ? new DocumentWriter($client) : null, $queued ? new AsyncIndexingQueue : null);
    }

    /**
     * Adds or replaces the documents of some items.
     *
     * @param  iterable<mixed>  $items
     * @return int The number of documents sent or queued
     */
    public function sync(Indexable $indexable, iterable $items): int
    {
        if ($this->skipped()) {
            return 0;
        }

        $documents = DocumentWriter::format($indexable, $items);

        if ($documents === []) {
            return 0;
        }

        $queue = $this->queueFor($indexable);

        if ($queue !== null) {
            $queue->enqueueDocuments(self::SYNC, $indexable->getIndexName(), ['documents' => $documents], sprintf('%s: %d documents', $indexable->getIndexName(), count($documents)));

            return count($documents);
        }

        $this->writer()->write($indexable, $documents);

        return count($documents);
    }

    /**
     * Deletes the documents matching a filter.
     */
    public function delete(Indexable $indexable, string $filter): void
    {
        if ($this->skipped()) {
            return;
        }

        $queue = $this->queueFor($indexable);

        if ($queue !== null) {
            $queue->enqueueDocuments(self::DELETE, $indexable->getIndexName(), ['filter' => $filter], sprintf('%s: %s', $indexable->getIndexName(), $filter));

            return;
        }

        $this->writer()->delete($indexable, $filter);
    }

    /**
     * Runs a queued task.
     *
     * @param  array<string, mixed>  $extra  index, then documents or filter
     * @return int The number of documents written, or 1 for a deletion
     *
     * @throws \RuntimeException When no registered indexable writes to the task's index
     */
    public function run(string $action, array $extra): int
    {
        $indexName = (string) ($extra['index'] ?? '');
        $indexable = self::registered($indexName);

        if ($indexable === null) {
            throw new \RuntimeException("No indexable of meiliscout/indexables writes to the index {$indexName}.");
        }

        if ($action === self::DELETE) {
            $this->writer()->delete($indexable, (string) ($extra['filter'] ?? ''));

            return 1;
        }

        $documents = array_values(array_filter((array) ($extra['documents'] ?? []), 'is_array'));
        $this->writer()->write($indexable, $documents);

        return count($documents);
    }

    /**
     * The indexable of meiliscout/indexables that writes to an index.
     */
    public static function registered(string $indexName): ?Indexable
    {
        $indexables = apply_filters('meiliscout/indexables', [new PostIndexable, new TaxonomyIndexable]);

        foreach ((array) $indexables as $indexable) {
            if ($indexable instanceof Indexable && $indexable->getIndexName() === $indexName) {
                return $indexable;
            }
        }

        return null;
    }

    /**
     * The queue, when it is on and can find the indexable again.
     */
    private function queueFor(Indexable $indexable): ?AsyncIndexingQueue
    {
        return $this->queue !== null && self::registered($indexable->getIndexName()) !== null ? $this->queue : null;
    }

    private function skipped(): bool
    {
        return (bool) apply_filters('meiliscout/skip_indexing', false);
    }

    /**
     * @throws \RuntimeException When Meilisearch is not configured
     */
    private function writer(): DocumentWriter
    {
        return $this->writer ?? throw new \RuntimeException('Meilisearch is not configured: the documents cannot be written.');
    }
}
