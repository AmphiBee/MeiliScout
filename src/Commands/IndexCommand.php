<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Commands;

use Pollora\MeiliScout\Services\Indexer;
use WP_CLI;

/**
 * Handles Meilisearch content indexing through WP-CLI commands.
 */
class IndexCommand
{
    /**
     * Handles content indexing in Meilisearch.
     *
     * ## OPTIONS
     *
     * [--clear]
     * : Clears indices before indexing
     *
     * [--purge]
     * : Purges all indices without reindexing
     *
     * ## EXAMPLES
     *
     *     # Index all content
     *     $ wp meiliscout index
     *
     *     # Clear indices then reindex
     *     $ wp meiliscout index --clear
     *
     *     # Purge all indices without reindexing
     *     $ wp meiliscout index --purge
     *
     * @param  array  $args  Positional arguments passed to the command
     * @param  array  $assoc_args  Named arguments passed to the command
     * @return void
     */
    public function __invoke($args, $assoc_args)
    {
        try {
            $indexer = new Indexer;

            if (isset($assoc_args['purge']) && $assoc_args['purge']) {
                WP_CLI::log('Purging Meilisearch indices...');
                $indexer->purge();
                WP_CLI::success('Purge completed successfully!');

                return;
            }

            WP_CLI::log('Starting Meilisearch indexing...');

            $clearIndices = isset($assoc_args['clear']) && $assoc_args['clear'];
            $indexer->index($clearIndices);

            WP_CLI::success('Indexing completed successfully!');
        } catch (\Exception $e) {
            WP_CLI::error('Error: '.$e->getMessage());
        }
    }
}
