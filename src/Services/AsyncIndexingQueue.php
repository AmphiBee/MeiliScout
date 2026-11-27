<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Pollora\MeiliScout\Documents\ItemSync;

use function add_filter;
use function apply_filters;
use function delete_option;
use function get_option;
use function update_option;
use function wp_next_scheduled;
use function wp_schedule_single_event;

/**
 * Manages an asynchronous queue for Meilisearch indexing operations.
 *
 * Operations are stored in a WordPress option and processed via a WP-Cron
 * event after a configurable delay. Duplicate operations for the same item
 * are deduplicated, keeping only the last enqueued action.
 *
 * @package Pollora\MeiliScout\Services
 * @since 1.0.0
 */
class AsyncIndexingQueue
{
    private const QUEUE_OPTION = 'meiliscout/async_queue';

    private const CRON_HOOK = 'meiliscout_process_async_queue';

    private const DEFAULT_DELAY = 300;

    /**
     * Indexers are needed to process the queue only: adding to it needs none.
     */
    public function __construct(
        private ?PostSingleIndexer $postIndexer = null,
        private ?TaxonomySingleIndexer $taxonomyIndexer = null,
    ) {}

    /**
     * Adds an indexing operation to the queue.
     *
     * Deduplication is applied per (type, id) pair: if an item with the same
     * key already exists in the queue, the new action replaces the old one.
     *
     * @param string  $type   Item type: 'post', 'term', or 'posts_for_term'
     * @param string  $action Operation to perform: 'index', 'remove', or 'reindex'
     * @param int     $id     Post or term ID
     * @param mixed[] $extra  Additional data (e.g. ['taxonomy' => 'category'])
     */
    public function enqueue(string $type, string $action, int $id, array $extra = []): void
    {
        $queue = $this->loadQueue();

        $queue[IndexingTask::key($type, $id)] = IndexingTask::make($type, $action, $id, $extra);

        update_option(self::QUEUE_OPTION, $queue, false);

        $this->scheduleIfNeeded();
    }

    /**
     * Adds a write to another plugin's index (see ItemSync): documents formatted already, or a filter of those to delete.
     *
     * The same write queued again runs once, in the order of its last addition.
     *
     * @param  array<string, mixed>  $payload  documents, or filter
     */
    public function enqueueDocuments(string $action, string $indexName, array $payload, string $label): void
    {
        $queue = $this->loadQueue();
        $extra = ['index' => $indexName, ...$payload, 'label' => $label];
        $key = ItemSync::TASK_TYPE.':'.md5((string) json_encode([$action, $extra]));

        unset($queue[$key]);
        $queue[$key] = IndexingTask::make(ItemSync::TASK_TYPE, $action, 0, $extra);

        update_option(self::QUEUE_OPTION, $queue, false);

        $this->scheduleIfNeeded();
    }

    /**
     * Processes all queued indexing operations.
     *
     * The queue is cleared before dispatching to avoid double-processing if
     * this method is called concurrently.
     */
    public function process(): void
    {
        $queue = $this->loadQueue();

        if (empty($queue)) {
            return;
        }

        // Clear the queue immediately before processing
        delete_option(self::QUEUE_OPTION);

        $tasks = new IndexingTask($this->postIndexer ??= new PostSingleIndexer, $this->taxonomyIndexer ??= new TaxonomySingleIndexer);

        ObjectCacheIsolation::run(function () use ($queue, $tasks): void {
            foreach ($queue as $item) {
                try {
                    $tasks->run($item);
                } catch (\Exception $e) {
                    error_log(sprintf(
                        'MeiliScout: Async queue failed to process item [%s:%s id=%d]: %s',
                        $item['type'],
                        $item['action'],
                        $item['id'],
                        $e->getMessage()
                    ));
                }
            }
        });

        ActivityLog::flush();
    }

    /**
     * Returns the delay in seconds before the cron event fires.
     *
     * @return int Delay in seconds (default: 300)
     */
    public function getDelay(): int
    {
        /**
         * Filters the delay (in seconds) before the async indexing queue is processed.
         *
         * @since 1.0.0
         * @param int $delay Delay in seconds. Default 300 (5 minutes).
         */
        return (int) apply_filters('meiliscout/async_indexing_delay', self::DEFAULT_DELAY);
    }


    /**
     * Loads the current queue from the WordPress options table.
     *
     * @return array<string, array{type: string, action: string, id: int, extra: mixed[]}>
     */
    private function loadQueue(): array
    {
        $queue = get_option(self::QUEUE_OPTION, []);
        return is_array($queue) ? $queue : [];
    }

    /**
     * Schedules the cron event if one is not already pending.
     */
    private function scheduleIfNeeded(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + $this->getDelay(), self::CRON_HOOK);
        }
    }
}
