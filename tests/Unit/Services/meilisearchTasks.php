<?php

declare(strict_types=1);

namespace {
    if (! function_exists('wp_timezone')) {
        function wp_timezone(): DateTimeZone
        {
            return new DateTimeZone('UTC');
        }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Services {

    use Meilisearch\Client;
    use Meilisearch\Contracts\BatchesQuery;
    use Meilisearch\Contracts\BatchesResults;
    use Meilisearch\Contracts\TasksQuery;
    use Meilisearch\Contracts\TasksResults;

    /**
     * A Meilisearch task, as GET /tasks lists it.
     *
     * @return array<string, mixed>
     */
    function task(int $uid, string $status, ?string $indexUid, string $enqueuedAt, string $type = 'documentAdditionOrUpdate', array $details = []): array
    {
        return ['uid' => $uid, 'status' => $status, 'indexUid' => $indexUid, 'type' => $type, 'enqueuedAt' => $enqueuedAt, 'details' => $details];
    }

    /**
     * A client whose getTasks() and getBatches() answer as Meilisearch does: filtered, most recent first, limited, with the total.
     *
     * @param  list<array<string, mixed>>  $tasks
     * @param  list<array<string, mixed>>  $batches
     */
    function tasksClient($test, array $tasks, array $batches = []): Client
    {
        $client = (fn () => $this->createMock(Client::class))->call($test);
        $client->method('getTasks')->willReturnCallback(static function (TasksQuery $query) use ($tasks): TasksResults {
            $options = $query->toArray();
            $matching = array_values(array_filter($tasks, static fn (array $task): bool => taskMatches($task, $options)));
            usort($matching, static fn (array $a, array $b): int => $b['uid'] <=> $a['uid']);

            return new TasksResults(['results' => array_slice($matching, 0, (int) ($options['limit'] ?? 20)), 'total' => count($matching), 'limit' => $options['limit'] ?? 20]);
        });
        $client->method('getBatches')->willReturnCallback(static function (BatchesQuery $query) use ($batches): BatchesResults {
            $statuses = explode(',', (string) ($query->toArray()['statuses'] ?? ''));
            $matching = array_values(array_filter($batches, static fn (array $batch): bool => in_array($batch['status'], $statuses, true)));

            return new BatchesResults(['results' => $matching, 'total' => count($matching), 'limit' => 20]);
        });

        return $client;
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $options  TasksQuery::toArray()
     */
    function taskMatches(array $task, array $options): bool
    {
        $listed = static fn (string $key, mixed $value): bool => ! isset($options[$key]) || in_array($value, explode(',', (string) $options[$key]), true);
        $after = isset($options['afterEnqueuedAt']) ? new \DateTimeImmutable((string) $options['afterEnqueuedAt']) : null;

        return $listed('statuses', $task['status'])
            && $listed('indexUids', $task['indexUid'])
            && ($after === null || new \DateTimeImmutable($task['enqueuedAt']) > $after);
    }
}
