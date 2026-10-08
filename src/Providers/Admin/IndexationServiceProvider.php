<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers\Admin;

use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\Indexer;

/**
 * Runs full indexations on WP-Cron: the ones the admin starts, and the ones
 * `meiliscout/schedule_indexation` asks for.
 */
class IndexationServiceProvider extends ServiceProvider
{
    /**
     * Cron hook that runs a full indexation.
     */
    public const PROCESS_INDEXATION = 'meiliscout_process_indexation';

    /**
     * Delay in seconds before a scheduled indexation runs.
     */
    private const SCHEDULE_DELAY = 10;

    /**
     * A full indexation that keeps the indexes while it runs.
     */
    private const IN_PLACE_INDEXATION = [
        'clear_indices' => false,
        'index_posts' => true,
        'index_taxonomies' => true,
    ];

    /**
     * Registers the service provider's hooks and actions.
     *
     * @return void
     */
    public function register()
    {
        if (! ClientFactory::isConfigured()) {
            return;
        }
        add_action(self::PROCESS_INDEXATION, [$this, 'processIndexation']);
        add_action('meiliscout/schedule_indexation', [$this, 'scheduleIndexation']);
    }

    /**
     * Schedules a full indexation once, without emptying the indexes: `meiliscout/schedule_indexation`.
     */
    public function scheduleIndexation(): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        if ($this->isIndexationScheduled()) {
            return;
        }

        wp_schedule_single_event(time() + self::SCHEDULE_DELAY, self::PROCESS_INDEXATION, [self::IN_PLACE_INDEXATION]);
    }

    /**
     * Processes the actual indexation.
     *
     * @param  array  $options  Indexation options
     * @return void
     */
    public function processIndexation($options)
    {
        $indexer = new Indexer;
        $indexer->index($options['clear_indices']);
    }

    private function shouldSkipIndexing(): bool
    {
        return (bool) apply_filters('meiliscout/skip_indexing', false);
    }

    private function isIndexationScheduled(): bool
    {
        return wp_next_scheduled(self::PROCESS_INDEXATION, [self::IN_PLACE_INDEXATION]) !== false;
    }
}
