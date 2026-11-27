<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Meilisearch\Client;
use Meilisearch\Contracts\BatchesQuery;
use Meilisearch\Contracts\TasksQuery;

/**
 * What Meilisearch still has to do on the site's indexes, after MeiliScout sent it.
 *
 * A full indexation ends once its documents are sent: Meilisearch then
 * indexes them, and computes their vectors when the index has an embedder,
 * which can take minutes. Searches read the previous index meanwhile.
 * Meilisearch's percentage stands still while vectors are computed: the
 * calls to the embedder are what moves then.
 */
final class MeilisearchBacklog
{
    private const PENDING_STATUSES = ['enqueued', 'processing'];

    /**
     * Pending tasks read to count the site's: Meilisearch's queue is shared by every index.
     */
    private const TASKS_READ = 1000;

    /**
     * A step of Meilisearch's progress that computes vectors.
     */
    private const EMBEDDING_STEP = 'embedding';

    public function __construct(private readonly Client $client) {}

    /**
     * The site's pending tasks, and how far the batch Meilisearch is processing for them is.
     *
     * @return array{tasks: int, percentage: float|null, embedding: array{documents: int|null, requests: int, failed: int}|null}
     */
    public function read(): array
    {
        $prefix = IndexNames::prefix();
        $results = $this->client->getTasks((new TasksQuery)->setStatuses(self::PENDING_STATUSES)->setLimit(self::TASKS_READ))->getResults();
        $tasks = array_filter($results, static fn (array $task): bool => self::isSites($task, $prefix));

        if ($tasks === []) {
            return ['tasks' => 0, 'percentage' => null, 'embedding' => null];
        }

        return ['tasks' => count($tasks), ...$this->currentBatch($prefix)];
    }

    /**
     * @return array{percentage: float|null, embedding: array{documents: int|null, requests: int, failed: int}|null}
     */
    private function currentBatch(string $prefix): array
    {
        $batches = $this->client->getBatches((new BatchesQuery)->setStatuses(['processing'])->setLimit(1))->getResults();
        $batch = $batches[0] ?? null;

        if (! is_array($batch) || ! self::touchesSite(array_keys((array) ($batch['stats']['indexUids'] ?? [])), $prefix)) {
            return ['percentage' => null, 'embedding' => null];
        }

        if (self::computesVectors($batch)) {
            return ['percentage' => null, 'embedding' => self::embedding($batch)];
        }

        $percentage = $batch['progress']['percentage'] ?? null;

        return ['percentage' => is_numeric($percentage) ? round((float) $percentage, 1) : null, 'embedding' => null];
    }

    /**
     * @param  array<string, mixed>  $batch
     */
    private static function computesVectors(array $batch): bool
    {
        $steps = array_column((array) ($batch['progress']['steps'] ?? []), 'currentStep');

        return array_filter($steps, static fn ($step): bool => is_string($step) && str_contains($step, self::EMBEDDING_STEP)) !== [];
    }

    /**
     * The documents the batch embeds, and its calls to the embedder so far.
     *
     * @param  array<string, mixed>  $batch
     * @return array{documents: int|null, requests: int, failed: int}
     */
    private static function embedding(array $batch): array
    {
        $documents = $batch['details']['receivedDocuments'] ?? null;
        $requests = (array) ($batch['stats']['embedderRequests'] ?? []);

        return [
            'documents' => is_numeric($documents) ? (int) $documents : null,
            'requests' => (int) ($requests['total'] ?? 0),
            'failed' => (int) ($requests['failed'] ?? 0),
        ];
    }

    /**
     * Whether a task is about one of the site's indexes: a swap names them in its details.
     *
     * @param  array<string, mixed>  $task
     */
    private static function isSites(array $task, string $prefix): bool
    {
        if (is_string($task['indexUid'] ?? null)) {
            return self::touchesSite([$task['indexUid']], $prefix);
        }

        $swapped = array_merge(...array_map(
            static fn ($swap): array => is_array($swap) ? (array) ($swap['indexes'] ?? []) : [],
            (array) ($task['details']['swaps'] ?? [])
        ));

        return self::touchesSite($swapped, $prefix);
    }

    /**
     * @param  array<mixed>  $indexNames
     */
    private static function touchesSite(array $indexNames, string $prefix): bool
    {
        foreach ($indexNames as $indexName) {
            if (is_string($indexName) && ($prefix === '' || str_starts_with($indexName, $prefix))) {
                return true;
            }
        }

        return false;
    }
}
