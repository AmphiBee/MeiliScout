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
     * Runs a task.
     *
     * @param  array{type: string, action: string, id: int, extra: array<string, mixed>}  $task
     */
    public function run(array $task): void
    {
        ['type' => $type, 'action' => $action, 'id' => $id, 'extra' => $extra] = $task;

        match ($type) {
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
}
