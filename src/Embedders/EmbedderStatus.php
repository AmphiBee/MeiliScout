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
     * One row per embedder of each index searches read.
     *
     * @return list<array{index: string, embedder: string, source: string, model: string, documents: int|string, 'without vector': int|string, 'failed tasks': int|string, 'last failure': string}>
     */
    public function rows(): array
    {
        $rows = [];

        foreach (Embedders::all() as $base => $embedders) {
            $indexName = IndexNames::active((string) $base);
            $counts = $this->counts($indexName);

            foreach ($embedders as $name => $config) {
                $rows[] = [
                    'index' => $indexName,
                    'embedder' => (string) $name,
                    'source' => (string) ($config['source'] ?? ''),
                    'model' => Embedders::model($config),
                    ...$counts,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array{documents: int|string, 'without vector': int|string, 'failed tasks': int|string, 'last failure': string}
     */
    private function counts(string $indexName): array
    {
        try {
            $stats = $this->client->index($indexName)->stats();
            $documents = (int) ($stats['numberOfDocuments'] ?? 0);
            $lastFailure = $this->lastTask($indexName, 'failed');

            return [
                'documents' => $documents,
                'without vector' => $documents - (int) ($stats['numberOfEmbeddedDocuments'] ?? 0),
                'failed tasks' => $lastFailure === null ? 0 : $this->failuresSinceLastSuccess($indexName),
                'last failure' => $lastFailure === null ? self::NONE : self::date($lastFailure),
            ];
        } catch (\Throwable $e) {
            error_log("MeiliScout: could not read the state of the index {$indexName}: ".$e->getMessage());

            return ['documents' => self::UNKNOWN, 'without vector' => self::UNKNOWN, 'failed tasks' => self::UNKNOWN, 'last failure' => self::UNKNOWN];
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
     * @param  array<string, mixed>  $task
     */
    private static function date(array $task): string
    {
        $enqueuedAt = $task['enqueuedAt'] ?? null;

        try {
            return is_string($enqueuedAt) ? (new \DateTimeImmutable($enqueuedAt))->setTimezone(wp_timezone())->format(self::DATE_FORMAT) : self::UNKNOWN;
        } catch (\Exception) {
            return self::UNKNOWN;
        }
    }
}
