<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

/**
 * Runs one indexing task: index or remove a post or a term, or re-index the posts of a term.
 *
 * A task is the array the queues keep: ['type', 'action', 'id', 'extra']. The
 * async queue runs them from WP-Cron, the deferred queue at the end of the request.
 */
final class IndexingTask
{
    public function __construct(
        private readonly PostSingleIndexer $postIndexer,
        private readonly TaxonomySingleIndexer $taxonomyIndexer,
    ) {}

    /**
     * Builds a task.
     *
     * @param  array<string, mixed>  $extra
     * @return array{type: string, action: string, id: int, extra: array<string, mixed>}
     */
    public static function make(string $type, string $action, int $id, array $extra = []): array
    {
        return ['type' => $type, 'action' => $action, 'id' => $id, 'extra' => $extra];
    }

    /**
     * The key under which a queue keeps a task: a later task on the same item replaces it.
     */
    public static function key(string $type, int $id): string
    {
        return "{$type}:{$id}";
    }

    /**
     * Runs a task, and records it in the activity log.
     *
     * Exceptions are recorded, then thrown again for the queue to log them.
     *
     * @param  array{type: string, action: string, id: int, extra: array<string, mixed>}  $task
     */
    public function run(array $task): void
    {
        $this->postIndexer->clearLastError();
        $this->taxonomyIndexer->clearLastError();
        $label = $this->describe($task);
        $start = microtime(true);

        try {
            $result = $this->dispatch($task);
        } catch (\Throwable $e) {
            ActivityLog::recordTask($task, $label, false, 0, $this->since($start), $e->getMessage());

            throw $e;
        }

        $error = $this->postIndexer->lastError() ?? $this->taxonomyIndexer->lastError();
        $succeeded = $result !== false && $error === null;
        // Re-indexing a term's posts counts them; the other tasks are about one item
        $items = is_int($result) ? $result : 1;

        ActivityLog::recordTask($task, $label, $succeeded, $items, $this->since($start), $succeeded ? null : ($error ?? 'Meilisearch did not accept the change.'));
    }

    /**
     * @param  array{type: string, action: string, id: int, extra: array<string, mixed>}  $task
     */
    private function dispatch(array $task): bool|int|null
    {
        ['type' => $type, 'action' => $action, 'id' => $id, 'extra' => $extra] = $task;

        return match ($type) {
            'post' => match ($action) {
                'index' => $this->postIndexer->indexPost($id),
                'remove' => $this->postIndexer->removePost($id),
                default => null,
            },
            'term' => match ($action) {
                'index' => $this->taxonomyIndexer->indexTerm($id),
                'remove' => $this->taxonomyIndexer->removeTerm($id),
                default => null,
            },
            'posts_for_term' => match ($action) {
                'reindex' => $this->postIndexer->reindexPostsForTerm($id, $extra['taxonomy'] ?? ''),
                default => null,
            },
            default => null,
        };
    }

    /**
     * The item a task is about, as the activity log shows it: a post's title, a term's name.
     *
     * A deleted item is gone when its task runs: the queue gives its label in `extra`.
     *
     * @param  array{type: string, action: string, id: int, extra: array<string, mixed>}  $task
     */
    private function describe(array $task): string
    {
        if (isset($task['extra']['label']) && is_string($task['extra']['label'])) {
            return $task['extra']['label'];
        }

        $name = match ($task['type']) {
            'post' => function_exists('get_post') ? get_post($task['id'])?->post_title : null,
            'term', 'posts_for_term' => function_exists('get_term') ? $this->termName($task['id']) : null,
            default => null,
        };

        return is_string($name) && $name !== '' ? $name : '#'.$task['id'];
    }

    private function termName(int $termId): ?string
    {
        $term = get_term($termId);

        return $term instanceof \WP_Term ? $term->name : null;
    }

    private function since(float $start): float
    {
        return (microtime(true) - $start) * 1000;
    }
}
