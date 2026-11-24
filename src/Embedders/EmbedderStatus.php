<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Embedders;

use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * Where the embedders of the indexes stand: documents still without a vector, failed tasks.
 *
 * Since Meilisearch 1.56 an indexation stops when the embedder cannot keep
 * up: the failed tasks of an index say so.
 */
final class EmbedderStatus
{
    public function __construct(private readonly Client $client) {}

    /**
     * One row per embedder of each index searches read.
     *
     * @return list<array{index: string, embedder: string, source: string, model: string, documents: int|string, 'without vector': int|string, 'failed tasks': int|string}>
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
     * @return array{documents: int|string, 'without vector': int|string, 'failed tasks': int|string}
     */
    private function counts(string $indexName): array
    {
        try {
            $stats = $this->client->index($indexName)->stats();
            $documents = (int) ($stats['numberOfDocuments'] ?? 0);
            $failed = $this->client->getTasks((new TasksQuery)->setStatuses(['failed'])->setIndexUids([$indexName])->setLimit(1))->getTotal();

            return [
                'documents' => $documents,
                'without vector' => $documents - (int) ($stats['numberOfEmbeddedDocuments'] ?? 0),
                'failed tasks' => $failed,
            ];
        } catch (\Throwable $e) {
            error_log("MeiliScout: could not read the state of the index {$indexName}: ".$e->getMessage());

            return ['documents' => 'unknown', 'without vector' => 'unknown', 'failed tasks' => 'unknown'];
        }
    }
}
