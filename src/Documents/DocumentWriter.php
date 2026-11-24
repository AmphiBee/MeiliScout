<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Documents;

use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Pollora\MeiliScout\Contracts\Indexable;
use Pollora\MeiliScout\Services\IndexSettings;
use Pollora\MeiliScout\Services\Indexer;

use function apply_filters;

/**
 * Writes and deletes some documents of an indexable's index, outside a full indexation.
 *
 * Real-time indexing only knows posts and terms: another plugin's index (the
 * passages of a manual, contacts...) is kept up to date through this writer.
 * A missing index is created with the indexable's settings first; an existing
 * one gets them again only when they changed.
 */
final class DocumentWriter
{
    private const NOT_FOUND = 404;

    public function __construct(private readonly Client $client) {}

    /**
     * The documents of some items, as the indexable formats them.
     *
     * @param  iterable<mixed>  $items
     * @return list<array<string, mixed>>
     */
    public static function format(Indexable $indexable, iterable $items): array
    {
        $documents = [];

        foreach ($items as $item) {
            $documents[] = $indexable->formatForIndexing($item);
        }

        return $documents;
    }

    /**
     * Adds or replaces documents, in batches of the size full indexations use.
     *
     * @param  list<array<string, mixed>>  $documents
     */
    public function write(Indexable $indexable, array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $indexName = $indexable->getIndexName();
        $this->ensureIndex($indexable, $indexName);
        $index = $this->client->index($indexName);

        foreach (array_chunk($documents, self::batchSize()) as $batch) {
            $index->addDocuments($batch, $indexable->getPrimaryKey());
        }
    }

    /**
     * Deletes the documents matching a filter (`attachment_id = 123`); the attributes it reads must be filterable.
     *
     * Nothing to delete from an index that does not exist.
     */
    public function delete(Indexable $indexable, string $filter): void
    {
        $indexName = $indexable->getIndexName();

        if ($this->exists($indexName)) {
            $this->client->index($indexName)->deleteDocuments(['filter' => $filter]);
        }
    }

    private function ensureIndex(Indexable $indexable, string $indexName): void
    {
        $index = $this->client->index($indexName);

        if ($this->exists($indexName)) {
            IndexSettings::pushIfChanged($index, $indexName, $indexable->getIndexSettings());

            return;
        }

        $this->client->createIndex($indexName, ['primaryKey' => $indexable->getPrimaryKey()]);
        IndexSettings::push($index, $indexName, $indexable->getIndexSettings());
    }

    private function exists(string $indexName): bool
    {
        try {
            $this->client->getIndex($indexName);

            return true;
        } catch (ApiException $e) {
            if ($e->httpStatus !== self::NOT_FOUND) {
                throw $e;
            }

            return false;
        }
    }

    private static function batchSize(): int
    {
        return max(1, (int) apply_filters('meiliscout/bulk_batch_size', Indexer::batchSize()));
    }
}
