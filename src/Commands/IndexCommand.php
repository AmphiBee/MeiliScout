<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Commands;

use Pollora\MeiliScout\Services\IndexNames;
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
     * [--chunk-size=<size>]
     * : Enables chunked indexing with specified chunk size.
     *   Each chunk runs in a separate process to prevent memory leaks.
     *
     * ## EXAMPLES
     *
     *     # Index all content
     *     $ wp meiliscout index
     *
     *     # Clear indices then reindex
     *     $ wp meiliscout index --clear
     *
     *     # Index in chunks of 50k documents (for large sites)
     *     $ wp meiliscout index --chunk-size=50000 --clear
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

            $clearIndices = isset($assoc_args['clear']) && $assoc_args['clear'];

            // Check if chunked mode is requested
            if (isset($assoc_args['chunk-size'])) {
                $this->indexChunked($indexer, (int) $assoc_args['chunk-size'], $clearIndices);
                return;
            }

            WP_CLI::log('Starting Meilisearch indexing...');
            $indexer->index($clearIndices);

            WP_CLI::success('Indexing completed successfully!');
            $this->reportLegacyIndexes();
        } catch (\Exception $e) {
            WP_CLI::error('Error: '.$e->getMessage());
        }
    }

    /**
     * Indexes content in chunks using separate processes.
     *
     * @param Indexer $indexer The indexer instance
     * @param int $chunkSize Number of documents per chunk
     * @param bool $clearIndices Whether to clear indices before indexing
     */
    private function indexChunked(Indexer $indexer, int $chunkSize, bool $clearIndices): void
    {
        $startTime = microtime(true);

        WP_CLI::log("Starting chunked indexing with chunk size: {$chunkSize}");

        $totalCount = $indexer->getTotalCount();
        $numChunks = (int) ceil($totalCount / $chunkSize);

        WP_CLI::log("Total documents: {$totalCount}");
        WP_CLI::log("Number of chunks: {$numChunks}");

        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }
        gc_collect_cycles();

        for ($i = 0; $i < $numChunks; $i++) {
            $chunkStartTime = microtime(true);
            $offset = $i * $chunkSize;
            $currentChunk = $i + 1;

            WP_CLI::log("");
            WP_CLI::log("========================================");
            WP_CLI::log("Processing chunk {$currentChunk}/{$numChunks}");
            WP_CLI::log("Offset: {$offset}, Limit: {$chunkSize}");
            WP_CLI::log("========================================");

            $shouldClear = $clearIndices && $i === 0;

            $clearFlag = $shouldClear ? ' --clear' : '';
            $command = sprintf(
                'meiliscout index-chunk --offset=%d --limit=%d%s',
                $offset,
                $chunkSize,
                $clearFlag
            );

            WP_CLI::log("Launching: wp {$command}");
            // A process of its own (memory), given this run's global parameters:
            // --path, --url... (a bare `wp` launched outside the WordPress
            // directory finds no installation)
            $result = WP_CLI::runcommand($command, ['launch' => true, 'exit_error' => false, 'return' => 'all']);

            if ($result->return_code !== 0) {
                WP_CLI::error("Chunk {$currentChunk} failed: " . $result->stderr);
            }

            $chunkElapsed = microtime(true) - $chunkStartTime;
            $chunkMinutes = (int) floor($chunkElapsed / 60);
            $chunkSeconds = (int) round(fmod($chunkElapsed, 60.0));

            WP_CLI::success("Chunk {$currentChunk}/{$numChunks} completed!");
            WP_CLI::log("Chunk execution time: {$chunkMinutes}m {$chunkSeconds}s");

            $totalElapsed = microtime(true) - $startTime;
            $avgTimePerChunk = $totalElapsed / ($i + 1);
            $remainingChunks = $numChunks - ($i + 1);
            $estimatedRemaining = $avgTimePerChunk * $remainingChunks;
            $estMinutes = (int) floor($estimatedRemaining / 60);
            $estSeconds = (int) round(fmod($estimatedRemaining, 60.0));

            if ($remainingChunks > 0) {
                WP_CLI::log("Estimated remaining time: {$estMinutes}m {$estSeconds}s");
            }
        }

        $totalElapsed = microtime(true) - $startTime;
        $totalMinutes = (int) floor($totalElapsed / 60);
        $totalSeconds = (int) round(fmod($totalElapsed, 60.0));

        // Every chunk is in: the documents of content deleted meanwhile can go
        if (! $clearIndices) {
            $indexer->deleteAllOrphans();
        }

        // Every chunk is in: searches can move to these indexes
        $indexer->activate();

        WP_CLI::success("All chunks completed successfully!");
        WP_CLI::log("Total execution time: {$totalMinutes}m {$totalSeconds}s");
        $this->reportLegacyIndexes();
    }

    /**
     * Shows the indexes searches read and write, and whether a migration is pending.
     *
     * ## EXAMPLES
     *
     *     wp meiliscout status
     */
    public function status($args, $assoc_args): void
    {
        $rows = array_map(fn (string $base) => [
            'index' => $base,
            'searches read' => IndexNames::active($base),
            'writes go to' => IndexNames::name($base),
            'format' => sprintf('%d (current: %d)', IndexNames::activeSchema($base), IndexNames::schemaVersion($base)),
        ], IndexNames::BASES);

        \WP_CLI\Utils\format_items('table', $rows, ['index', 'searches read', 'writes go to', 'format']);

        if (IndexNames::migrationPending()) {
            WP_CLI::warning('Migration pending: run `wp meiliscout index` to build the new indexes and move searches to them.');
        }

        $this->reportLegacyIndexes();
    }

    /**
     * Deletes the indexes searches no longer read since the last migration.
     *
     * ## EXAMPLES
     *
     *     wp meiliscout delete-legacy-indexes
     */
    public function delete_legacy_indexes($args, $assoc_args): void
    {
        try {
            $deleted = (new Indexer)->deleteLegacyIndexes();

            $deleted === []
                ? WP_CLI::success('No legacy index to delete.')
                : WP_CLI::success('Deleted: '.implode(', ', $deleted));
        } catch (\Exception $e) {
            WP_CLI::error('Error: '.$e->getMessage());
        }
    }

    /**
     * Points at the legacy indexes left after a migration.
     */
    private function reportLegacyIndexes(): void
    {
        $legacy = IndexNames::legacyIndexes();

        if ($legacy !== []) {
            WP_CLI::log(sprintf(
                'Searches no longer read %s: delete them with `wp meiliscout delete-legacy-indexes`.',
                implode(', ', $legacy)
            ));
        }
    }

    /**
     * Indexes a specific chunk of content (internal command).
     *
     * ## OPTIONS
     *
     * --offset=<offset>
     * : Starting offset
     *
     * --limit=<limit>
     * : Number of documents to index
     *
     * [--clear]
     * : Clears indices before indexing
     *
     * @param  array  $args  Positional arguments passed to the command
     * @param  array  $assoc_args  Named arguments passed to the command
     * @return void
     * @subcommand index-chunk
     */
    public function index_chunk($args, $assoc_args)
    {
        try {
            if (!isset($assoc_args['offset']) || !isset($assoc_args['limit'])) {
                WP_CLI::error('Both --offset and --limit are required');
            }

            $offset = (int) $assoc_args['offset'];
            $limit = (int) $assoc_args['limit'];
            $clearIndices = isset($assoc_args['clear']) && $assoc_args['clear'];

            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
            gc_collect_cycles();

            $indexer = new Indexer;
            $indexer->indexChunk($offset, $limit, $clearIndices);

            WP_CLI::success("Chunk completed!");

        } catch (\Exception $e) {
            WP_CLI::error('Error: '.$e->getMessage());
        }
    }
}
