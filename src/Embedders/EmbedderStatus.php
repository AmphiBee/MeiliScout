<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Embedders;

use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;
use Pollora\MeiliScout\Services\IndexNames;

use function wp_timezone;

/**
 * Where the embedders of the indexes stand: documents still without a vector, failed tasks.
 *
 * Since Meilisearch 1.56 an indexation stops when the embedder cannot keep
 * up: the failed tasks of an index say so. Meilisearch keeps them forever,
 * so only those after the index's last task that succeeded are still to
 * deal with; the date of the last one tells an old incident from a new one.
 */
final class EmbedderStatus
{
    private const UNKNOWN = 'unknown';

    private const NONE = '-';

    private const DATE_FORMAT = 'Y-m-d H:i';

    public function __construct(private readonly Client $client) {}

    /**
     * Each index searches read that has embedders, with their state; null figures when Meilisearch could not tell.
     *
     * @return list<array{base: string, index: string, embedders: list<array{name: string, source: string, model: string}>, documents: int|null, embedded: int|null, failed: int|null, last_failure: string|null}>
     */
    public function indexes(): array
    {
        $indexes = [];

        foreach (Embedders::all() as $base => $embedders) {
            $indexName = IndexNames::active((string) $base);
            $indexes[] = [
                'base' => (string) $base,
                'index' => $indexName,
                'embedders' => self::describeEmbedders($embedders),
                ...$this->state($indexName),
            ];
        }

        return $indexes;
    }

    /**
     * One row per embedder of each index searches read, as `wp meiliscout status` prints them.
     *
     * @return list<array{index: string, embedder: string, source: string, model: string, documents: int|string, 'without vector': int|string, 'failed tasks': int|string, 'last failure': string}>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->indexes() as $index) {
            foreach ($index['embedders'] as $embedder) {
                $rows[] = [
                    'index' => $index['index'],
                    'embedder' => $embedder['name'],
                    'source' => $embedder['source'],
                    'model' => $embedder['model'],
                    'documents' => $index['documents'] ?? self::UNKNOWN,
                    'without vector' => $index['documents'] === null || $index['embedded'] === null ? self::UNKNOWN : $index['documents'] - $index['embedded'],
                    'failed tasks' => $index['failed'] ?? self::UNKNOWN,
                    'last failure' => self::lastFailure($index),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $embedders
     * @return list<array{name: string, source: string, model: string}>
     */
    private static function describeEmbedders(array $embedders): array
    {
        $described = [];

        foreach ($embedders as $name => $config) {
            $described[] = ['name' => (string) $name, 'source' => (string) ($config['source'] ?? ''), 'model' => Embedders::model($config)];
        }

        return $described;
    }

    /**
     * @return array{documents: int|null, embedded: int|null, failed: int|null, last_failure: string|null}
     */
    private function state(string $indexName): array
    {
        try {
            $stats = $this->client->index($indexName)->stats();
            $lastFailure = $this->lastTask($indexName, 'failed');

            return [
                'documents' => (int) ($stats['numberOfDocuments'] ?? 0),
                'embedded' => (int) ($stats['numberOfEmbeddedDocuments'] ?? 0),
                'failed' => $lastFailure === null ? 0 : $this->failuresSinceLastSuccess($indexName),
                'last_failure' => is_string($lastFailure['enqueuedAt'] ?? null) ? $lastFailure['enqueuedAt'] : null,
            ];
        } catch (\Throwable $e) {
            error_log("MeiliScout: could not read the state of the index {$indexName}: ".$e->getMessage());

            return ['documents' => null, 'embedded' => null, 'failed' => null, 'last_failure' => null];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastTask(string $indexName, string $status): ?array
    {
        $tasks = $this->client->getTasks((new TasksQuery)->setStatuses([$status])->setIndexUids([$indexName])->setLimit(1))->getResults();

        return $tasks[0] ?? null;
    }

    private function failuresSinceLastSuccess(string $indexName): int
    {
        $query = (new TasksQuery)->setStatuses(['failed'])->setIndexUids([$indexName])->setLimit(1);
        $lastSuccess = $this->lastTask($indexName, 'succeeded');

        if ($lastSuccess !== null) {
            $query->setAfterEnqueuedAt(new \DateTimeImmutable((string) $lastSuccess['enqueuedAt']));
        }

        return $this->client->getTasks($query)->getTotal();
    }

    /**
     * The last failure in the site's time zone: none, or unknown when Meilisearch could not tell.
     *
     * @param  array{failed: int|null, last_failure: string|null}  $index
     */
    private static function lastFailure(array $index): string
    {
        if ($index['failed'] === null) {
            return self::UNKNOWN;
        }

        if ($index['last_failure'] === null) {
            return self::NONE;
        }

        try {
            return (new \DateTimeImmutable($index['last_failure']))->setTimezone(wp_timezone())->format(self::DATE_FORMAT);
        } catch (\Exception) {
            return self::UNKNOWN;
        }
    }
}
