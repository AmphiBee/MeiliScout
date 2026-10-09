<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Pollora\MeiliScout\Config\SearchableAttributes;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\Indexable;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Indexables\TaxonomyIndexable;
use Pollora\MeiliScout\Services\PostSingleIndexer;
use Pollora\MeiliScout\Services\TaxonomySingleIndexer;

use function apply_filters;
use function current_time;
use function do_action;
use function get_posts;
use function update_option;

/**
 * Service for managing Meilisearch indexing operations.
 */
class Indexer
{
    /**
     * Meilisearch client instance.
     */
    private ?Client $client;

    private array $indexables;

    /**
     * Default batch size for bulk indexing operations.
     * Set in the admin, and overridden by the 'meiliscout/bulk_batch_size' filter.
     */
    public const DEFAULT_BATCH_SIZE = 500;

    /**
     * Where the current run is, as IndexingLogger::progress() records it.
     *
     * @var array{mode?: string, indexes?: array<string, array<string, mixed>>}
     */
    private array $progress = [];

    /**
     * Cache for index existence checks.
     */
    private array $indexExistsCache = [];

    /**
     * Post single indexer instance for individual post operations.
     */
    private PostSingleIndexer $postSingleIndexer;

    /**
     * Taxonomy single indexer instance for individual taxonomy operations.
     */
    private TaxonomySingleIndexer $taxonomySingleIndexer;

    /**
     * Logger instance for secure file-based logging.
     */
    private IndexingLogger $logger;

    /**
     * Creates a new Indexer instance.
     */
    public function __construct()
    {
        $this->client = ClientFactory::getClient();
        $this->indexables = apply_filters('meiliscout/indexables', [
            new PostIndexable,
            new TaxonomyIndexable,
        ]);
        $this->postSingleIndexer = apply_filters('meiliscout/post_single_indexer', new PostSingleIndexer());
        $this->taxonomySingleIndexer = new TaxonomySingleIndexer();
        $this->logger = IndexingLogger::getInstance();
    }

    /**
     * Indexes content in Meilisearch.
     *
     * @param  bool  $clearIndices  Whether to clear existing indices before indexing
     */
    public function index(bool $clearIndices = false): void
    {
        // Keep the bulk read off the persistent object cache (see ObjectCacheIsolation).
        ObjectCacheIsolation::run(fn () => $this->runIndex($clearIndices));
    }

    /**
     * Runs a full indexation.
     *
     * @param  bool  $clearIndices  Whether to clear existing indices before indexing
     */
    private function runIndex(bool $clearIndices): void
    {
        $this->ensureClient();

        $this->initializeLog();

        $mode = $clearIndices ? 'rebuild' : 'update';
        $start = microtime(true);
        $totalIndexed = 0;
        $this->startProgress($mode);

        try {
            // Save the current indexing structure
            $this->saveIndexingStructure();
            $startedAt = current_time('mysql', true);
            MetaValueFlags::startRun();

            foreach ($this->indexables as $indexable) {
                $finalName = $indexable->getIndexName();
                // Rebuilt under a temporary name, then swapped in: searches never see an empty index
                $rebuildName = $clearIndices ? $this->startRebuild($indexable, $finalName) : null;
                $indexName = $rebuildName ?? $finalName;
                $this->log('info', sprintf('Starting indexation for %s', $finalName));
                $this->updateProgress($finalName, 'running');

                // An indexable naming its own index cannot be rebuilt aside: empty it first
                if ($clearIndices && $rebuildName === null) {
                    $this->client->deleteIndex($indexName);
                    unset($this->indexExistsCache[$indexName]);
                }

                // Create index with primary key
                if ($clearIndices || ! $this->indexExists($indexName)) {
                    $this->client->createIndex($indexName, [
                        'primaryKey' => $indexable->getPrimaryKey(),
                    ]);
                    // Update cache; the single indexers writing the batches must not create it again
                    $this->indexExistsCache[$indexName] = true;
                    AbstractSingleIndexer::indexCreated($indexName);
                }

                $index = $this->client->index($indexName);
                IndexSettings::push($index, $indexName, $indexable->getIndexSettings());

                if (! $clearIndices) {
                    $this->deleteNonIndexableStatuses($indexable);
                }

                // Document indexing with optimized batch size
                $indexed = 0;
                $processed = 0;
                $batchSize = $this->getBulkBatchSize();

                // Use single indexers for consistency and shared logic
                $items = [];
                foreach ($indexable->getItems() as $item) {
                    $items[] = $item;

                    if (count($items) >= $batchSize) {
                        $batchStats = $this->indexItemsBatch($indexable, $items);
                        $indexed += $batchStats['indexed'];
                        $processed += count($items);
                        $this->log('info', sprintf(
                            'Batch of %d items processed (%d indexed, %d skipped)',
                            count($items),
                            $batchStats['indexed'],
                            $batchStats['skipped']
                        ));
                        $this->updateProgress($finalName, 'running', $processed);
                        $items = [];
                    }
                }

                if (! empty($items)) {
                    $batchStats = $this->indexItemsBatch($indexable, $items);
                    $indexed += $batchStats['indexed'];
                    $processed += count($items);
                    $this->log('info', sprintf(
                        'Last batch of %d items processed (%d indexed, %d skipped)',
                        count($items),
                        $batchStats['indexed'],
                        $batchStats['skipped']
                    ));
                }

                if ($rebuildName !== null) {
                    $this->updateProgress($finalName, 'swapping', $processed);
                    $this->finishRebuild($indexable, $finalName, $rebuildName, $startedAt);
                }

                $totalIndexed += $indexed;
                $this->updateProgress($finalName, 'done', $processed);
                $this->log('success', sprintf('Total of %d items indexed for %s', $indexed, $finalName));
            }

            $this->activate();

            $this->log('success', 'Indexing completed successfully', true);
            $this->logger->complete('completed');
            ActivityLog::recordFullIndexation($mode, true, $totalIndexed, (microtime(true) - $start) * 1000, $this->indexNames());
        } catch (\Exception $e) {
            $this->log('error', 'Error during indexing: ' . $e->getMessage(), true);
            $this->logger->complete('error');
            ActivityLog::recordFullIndexation($mode, false, $totalIndexed, (microtime(true) - $start) * 1000, $this->indexNames(), $e->getMessage());
            throw $e;
        }
    }

    /**
     * The names of the indexes a full indexation writes.
     *
     * @return list<string>
     */
    private function indexNames(): array
    {
        return array_values(array_map(fn (Indexable $indexable) => $indexable->getIndexName(), $this->indexables));
    }

    /**
     * Records the indexes about to be written, with the number of items each one will get.
     */
    private function startProgress(string $mode): void
    {
        $indexes = [];

        foreach ($this->indexables as $indexable) {
            $indexes[$indexable->getIndexName()] = [
                'kind' => $this->kindOf($indexable),
                'state' => 'pending',
                'done' => 0,
                'total' => $this->countItems($indexable),
            ];
        }

        $this->progress = ['mode' => $mode, 'indexes' => $indexes];
        $this->logger->progress($this->progress);
    }

    private function updateProgress(string $indexName, string $state, int $done = 0): void
    {
        if (! isset($this->progress['indexes'][$indexName])) {
            return;
        }

        $this->progress['indexes'][$indexName]['state'] = $state;
        $this->progress['indexes'][$indexName]['done'] = $done;
        $this->logger->progress($this->progress);
    }

    /**
     * What an index holds, for the admin: posts, terms, or another indexable's documents.
     */
    private function kindOf(Indexable $indexable): string
    {
        return match (true) {
            $indexable instanceof PostIndexable => 'posts',
            $indexable instanceof TaxonomyIndexable => 'terms',
            default => 'other',
        };
    }

    /**
     * The number of items an indexable will send, or null when it cannot be told in advance.
     */
    private function countItems(Indexable $indexable): ?int
    {
        return match (true) {
            $indexable instanceof PostIndexable => $this->countPosts(),
            $indexable instanceof TaxonomyIndexable => $this->countTerms(),
            default => null,
        };
    }

    /**
     * Moves searches to the indexes just built, in the current format.
     *
     * Called once every indexable was indexed. Only the indexes of the
     * indexables that ran move: the indexables filter may leave one out. The
     * indexes searches leave are listed for deletion in the admin.
     */
    public function activate(): void
    {
        $bases = $this->builtBases();
        $migrating = array_intersect(IndexNames::pendingBases(), $bases);

        IndexNames::activate($bases);

        /**
         * Fires once searches moved to the indexes a full indexation built.
         *
         * @param  list<string>  $bases  posts, terms
         */
        do_action('meiliscout/indexes_activated', $bases);
        MetaValueFlags::finishRun(array_map(static fn (string $base) => $base === 'posts' ? 'post' : 'term', $bases));

        if ($migrating !== []) {
            $this->log('success', sprintf('Searches now use %s', implode(', ', array_map([IndexNames::class, 'name'], $migrating))));
        }
    }

    /**
     * The plugin's indexes the indexables write, by base name.
     *
     * @return list<string>
     */
    private function builtBases(): array
    {
        $bases = [];

        foreach ($this->indexables as $indexable) {
            $base = match ($this->kindOf($indexable)) {
                'posts' => 'posts',
                'terms' => 'taxonomies',
                default => null,
            };

            if ($base !== null) {
                $bases[] = $base;
            }
        }

        return array_values(array_unique($bases));
    }

    /**
     * Deletes the indexes searches no longer read since the last migration.
     *
     * @return list<string> The deleted indexes
     */
    public function deleteLegacyIndexes(): array
    {
        $this->ensureClient();

        $legacy = IndexNames::legacyIndexes();

        foreach ($legacy as $indexName) {
            $this->client->deleteIndex($indexName);
        }

        IndexNames::forgetLegacyIndexes();

        return $legacy;
    }

    /**
     * Starts rebuilding an index under a temporary name.
     *
     * @return string|null The temporary name, or null when the indexable names its index itself
     */
    private function startRebuild(Indexable $indexable, string $indexName): ?string
    {
        $rebuildName = $indexName.'__rebuild';
        IndexNames::redirect($indexName, $rebuildName);

        if ($indexable->getIndexName() !== $rebuildName) {
            IndexNames::lift($indexName);

            return null;
        }

        // Left over by an interrupted rebuild; a deletion of no index would be a failed task
        if ($this->indexExists($rebuildName)) {
            $this->client->deleteIndex($rebuildName);
        }
        unset($this->indexExistsCache[$rebuildName]);

        return $rebuildName;
    }

    /**
     * Swaps the rebuilt index in, then indexes again what changed meanwhile.
     *
     * Saves made during the rebuild went to the index being replaced: the
     * posts modified since the run started are indexed again once swapped.
     */
    private function finishRebuild(Indexable $indexable, string $indexName, string $rebuildName, string $startedAt): void
    {
        IndexNames::lift($indexName);

        // A swap needs both indexes
        if (! $this->indexExists($indexName)) {
            $this->client->createIndex($indexName, ['primaryKey' => $indexable->getPrimaryKey()]);
            AbstractSingleIndexer::indexCreated($indexName);
        }

        // Meilisearch runs tasks in order: the swap waits for the documents written to the rebuild
        $this->client->swapIndexes([[$indexName, $rebuildName]]);
        $this->client->deleteIndex($rebuildName);
        IndexSettings::remember($indexName, $indexable->getIndexSettings());
        $this->log('info', sprintf('Rebuilt index swapped in for %s', $indexName));

        if ($indexable instanceof PostIndexable) {
            $this->reindexPostsModifiedSince($startedAt);
        }
    }

    /**
     * Indexes the posts saved since a date (GMT, MySQL format).
     */
    private function reindexPostsModifiedSince(string $since): void
    {
        $postIds = get_posts([
            'post_type' => Settings::get('indexed_post_types', []),
            'post_status' => PostIndexable::indexableStatuses(),
            'date_query' => [['column' => 'post_modified_gmt', 'after' => $since, 'inclusive' => true]],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'no_found_rows' => true,
        ]);

        foreach ($postIds as $postId) {
            $this->postSingleIndexer->indexPost((int) $postId);
        }

        if ($postIds !== []) {
            $this->log('info', sprintf('%d posts saved during the rebuild indexed again', count($postIds)));
        }
    }

    /**
     * Fails early when Meilisearch could not be reached.
     *
     * @throws \RuntimeException
     */
    private function ensureClient(): void
    {
        if ($this->client === null) {
            throw new \RuntimeException('Meilisearch is not reachable: check the host and the API key.');
        }
    }

    /**
     * Removes the posts whose status is no longer indexable from the index.
     *
     * A full run only adds documents, so a post indexed before it went private,
     * draft or trash (or before only public statuses were indexed) would stay
     * searchable. Deleting by filter cleans the index without emptying it.
     *
     * @param Indexable $indexable The indexable being indexed
     */
    private function deleteNonIndexableStatuses(Indexable $indexable): void
    {
        if (! $indexable instanceof PostIndexable) {
            return;
        }

        $statuses = array_map(
            fn (string $status) => sprintf("'%s'", addslashes($status)),
            PostIndexable::indexableStatuses()
        );

        try {
            $this->client->index($indexable->getIndexName())->deleteDocuments([
                'filter' => sprintf('post_status NOT IN [%s]', implode(', ', $statuses)),
            ]);
            $this->log('info', 'Documents with a non-indexable status scheduled for deletion');
        } catch (\Exception $e) {
            $this->log('error', 'Failed to delete documents with a non-indexable status: ' . $e->getMessage());
        }
    }

    /**
     * Gets the bulk batch size, allowing override via filter.
     *
     * @return int Batch size for bulk indexing operations
     */
    private function getBulkBatchSize(): int
    {
        return (int) apply_filters('meiliscout/bulk_batch_size', self::batchSize());
    }

    /**
     * Items sent per request by full indexations: the admin's setting, else the default.
     */
    public static function batchSize(): int
    {
        $size = (int) Settings::get('bulk_batch_size', self::DEFAULT_BATCH_SIZE);

        return $size > 0 ? $size : self::DEFAULT_BATCH_SIZE;
    }

    /**
     * Gets the total count of items to be indexed.
     *
     * @return int Total number of items
     */
    public function getTotalCount(): int
    {
        return $this->countPosts() + $this->countTerms();
    }

    /**
     * The number of posts a full indexation sends.
     */
    public function countPosts(): int
    {
        global $wpdb;

        $postTypes = Settings::get('indexed_post_types', []);
        if (empty($postTypes)) {
            return 0;
        }

        $statuses = PostIndexable::indexableStatuses();
        $placeholders = implode(',', array_fill(0, count($postTypes), '%s'));
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type IN ($placeholders)
             AND post_status IN ($statusPlaceholders)",
            ...$postTypes,
            ...$statuses
        ));
    }

    /**
     * The number of terms a full indexation sends.
     */
    public function countTerms(): int
    {
        global $wpdb;

        $taxonomies = Settings::get('indexed_taxonomies', []);
        if (empty($taxonomies)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($taxonomies), '%s'));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT t.term_id)
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
             WHERE tt.taxonomy IN ($placeholders)",
            ...$taxonomies
        ));
    }

    /**
     * Indexes a specific chunk of content.
     *
     * @param  int  $offset  Starting offset
     * @param  int  $limit  Number of items to index
     * @param  bool  $clearIndices  Whether to clear existing indices before indexing
     */
    public function indexChunk(int $offset, int $limit, bool $clearIndices = false): void
    {
        // Keep the bulk read off the persistent object cache (see ObjectCacheIsolation).
        ObjectCacheIsolation::run(fn () => $this->runIndexChunk($offset, $limit, $clearIndices));
    }

    /**
     * Indexes a chunk of content.
     *
     * @param  int  $offset  Starting offset
     * @param  int  $limit  Number of items to index
     * @param  bool  $clearIndices  Whether to clear existing indices before indexing
     */
    private function runIndexChunk(int $offset, int $limit, bool $clearIndices): void
    {
        $this->ensureClient();

        $this->initializeLog();

        try {
            // Save the current indexing structure only on first chunk
            if ($offset === 0) {
                $this->saveIndexingStructure();
                MetaValueFlags::startRun();
            }

            foreach ($this->indexables as $indexable) {
                $indexName = $indexable->getIndexName();
                $this->log('info', sprintf('Starting chunk indexation for %s (offset: %d, limit: %d)', $indexName, $offset, $limit));

                // Index configuration
                if ($clearIndices) {
                    $this->client->deleteIndex($indexName);
                    unset($this->indexExistsCache[$indexName]);
                }

                // Create index with primary key
                if ($clearIndices || ! $this->indexExists($indexName)) {
                    $this->client->createIndex($indexName, [
                        'primaryKey' => $indexable->getPrimaryKey(),
                    ]);
                    $this->indexExistsCache[$indexName] = true;
                    AbstractSingleIndexer::indexCreated($indexName);
                }

                $index = $this->client->index($indexName);
                IndexSettings::push($index, $indexName, $indexable->getIndexSettings());

                if (! $clearIndices && $offset === 0) {
                    $this->deleteNonIndexableStatuses($indexable);
                }

                // Document indexing with offset/limit
                $totalIndexed = 0;
                $batchSize = $this->getBulkBatchSize();
                $itemsProcessed = 0;

                $items = [];
                foreach ($indexable->getItems($offset, $limit) as $item) {
                    $items[] = $item;
                    $itemsProcessed++;

                    if (count($items) >= $batchSize) {
                        $batchStats = $this->indexItemsBatch($indexable, $items);
                        $totalIndexed += $batchStats['indexed'];
                        $this->log('info', sprintf(
                            'Batch of %d items processed (%d indexed, %d skipped) - Progress: %d/%d',
                            count($items),
                            $batchStats['indexed'],
                            $batchStats['skipped'],
                            $itemsProcessed,
                            $limit
                        ));
                        $items = [];

                        // Aggressive memory cleanup after each batch
                        if (function_exists('wp_cache_flush_runtime')) {
                            wp_cache_flush_runtime();
                        }
                        gc_collect_cycles();
                    }
                }

                if (! empty($items)) {
                    $batchStats = $this->indexItemsBatch($indexable, $items);
                    $totalIndexed += $batchStats['indexed'];
                    $this->log('info', sprintf(
                        'Last batch of %d items processed (%d indexed, %d skipped)',
                        count($items),
                        $batchStats['indexed'],
                        $batchStats['skipped']
                    ));

                    // Aggressive memory cleanup after last batch
                    if (function_exists('wp_cache_flush_runtime')) {
                        wp_cache_flush_runtime();
                    }
                    gc_collect_cycles();
                }

                $this->log('success', sprintf('Chunk total: %d items indexed for %s', $totalIndexed, $indexName));
            }

            $this->log('success', 'Chunk indexing completed successfully', true);
            $this->logger->complete('completed');
        } catch (\Exception $e) {
            $this->log('error', 'Error during chunk indexing: ' . $e->getMessage(), true);
            $this->logger->complete('error');
            throw $e;
        }
    }

    /**
     * Saves the current indexing structure.
     */
    private function saveIndexingStructure(): void
    {
        // Before the option below, which marks a site as indexed
        IndexNames::adoptIfNew();

        $structure = [
            'post_types' => Settings::get('indexed_post_types', []),
            'taxonomies' => Settings::get('indexed_taxonomies', []),
            'meta_keys' => Settings::get('indexed_meta_keys', []),
            'term_meta_keys' => Settings::get(TaxonomyIndexable::META_KEYS_SETTING, []),
            'searchable' => SearchableAttributes::configured(),
            'statuses' => PostIndexable::indexableStatuses(),
            'last_indexed' => current_time('mysql'),
        ];

        update_option('meiliscout/last_indexing_structure', $structure);
    }

    /**
     * Checks if the indexing structure has changed.
     *
     * @return array{has_changed: bool, changes: array<string, array{added: list<string>, removed: list<string>}>, last_indexed: string|null}
     */
    public function checkStructureChanges(): array
    {
        $currentStructure = [
            'post_types' => Settings::get('indexed_post_types', []),
            'taxonomies' => Settings::get('indexed_taxonomies', []),
            'meta_keys' => Settings::get('indexed_meta_keys', []),
        ];

        $lastStructure = get_option('meiliscout/last_indexing_structure', [
            'post_types' => [],
            'taxonomies' => [],
            'meta_keys' => [],
            'last_indexed' => null,
        ]);

        $changes = [];
        $hasChanged = false;

        // Check for changes in post types
        $addedPostTypes = array_diff($currentStructure['post_types'], $lastStructure['post_types']);
        $removedPostTypes = array_diff($lastStructure['post_types'], $currentStructure['post_types']);
        if (! empty($addedPostTypes) || ! empty($removedPostTypes)) {
            $hasChanged = true;
            $changes['post_types'] = [
                'added' => array_values($addedPostTypes),
                'removed' => array_values($removedPostTypes),
            ];
        }

        // Check for changes in taxonomies
        $addedTaxonomies = array_diff($currentStructure['taxonomies'], $lastStructure['taxonomies']);
        $removedTaxonomies = array_diff($lastStructure['taxonomies'], $currentStructure['taxonomies']);
        if (! empty($addedTaxonomies) || ! empty($removedTaxonomies)) {
            $hasChanged = true;
            $changes['taxonomies'] = [
                'added' => array_values($addedTaxonomies),
                'removed' => array_values($removedTaxonomies),
            ];
        }

        // Check for changes in meta keys
        $addedMetaKeys = array_diff($currentStructure['meta_keys'], $lastStructure['meta_keys']);
        $removedMetaKeys = array_diff($lastStructure['meta_keys'], $currentStructure['meta_keys']);
        if (! empty($addedMetaKeys) || ! empty($removedMetaKeys)) {
            $hasChanged = true;
            $changes['meta_keys'] = [
                'added' => array_values($addedMetaKeys),
                'removed' => array_values($removedMetaKeys),
            ];
        }

        // Sites indexed before term meta keys were chosen apart had none
        $termMetaKeys = (array) Settings::get(TaxonomyIndexable::META_KEYS_SETTING, []);
        $lastTermMetaKeys = (array) ($lastStructure['term_meta_keys'] ?? []);
        if (array_diff($termMetaKeys, $lastTermMetaKeys) !== [] || array_diff($lastTermMetaKeys, $termMetaKeys) !== []) {
            $hasChanged = true;
            $changes['term_meta_keys'] = [
                'added' => array_values(array_diff($termMetaKeys, $lastTermMetaKeys)),
                'removed' => array_values(array_diff($lastTermMetaKeys, $termMetaKeys)),
            ];
        }

        // In order: it ranks the fields. Sites indexed before it existed have none, as an unset order
        if (($lastStructure['searchable'] ?? null) !== SearchableAttributes::configured()) {
            $hasChanged = true;
            $changes['searchable'] = [
                'added' => array_values(array_diff(SearchableAttributes::configured() ?? [], $lastStructure['searchable'] ?? [])),
                'removed' => array_values(array_diff($lastStructure['searchable'] ?? [], SearchableAttributes::configured() ?? [])),
            ];
        }

        // Private posts added or removed: sites indexed before it was recorded had published posts only
        $statuses = PostIndexable::indexableStatuses();
        $lastStatuses = (array) ($lastStructure['statuses'] ?? ['publish']);
        if (array_diff($statuses, $lastStatuses) !== [] || array_diff($lastStatuses, $statuses) !== []) {
            $hasChanged = true;
            $changes['statuses'] = [
                'added' => array_values(array_diff($statuses, $lastStatuses)),
                'removed' => array_values(array_diff($lastStatuses, $statuses)),
            ];
        }

        return [
            'has_changed' => $hasChanged,
            'changes' => $changes,
            'last_indexed' => $lastStructure['last_indexed'] ?? null,
        ];
    }

    public function purge(): void
    {
        $this->ensureClient();

        $this->initializeLog();

        try {
            $this->log('info', 'Starting indices purge');

            foreach ($this->indexables as $indexable) {
                $indexName = $indexable->getIndexName();
                $this->client->deleteIndex($indexName);
                $this->log('success', sprintf('Index "%s" deleted', $indexName));
            }

            $this->log('success', 'Purge completed successfully');
        } catch (\Exception $e) {
            $this->log('error', 'Error during purge: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Indexes a batch of items using the appropriate single indexer.
     *
     * This method delegates to the specific single indexer based on the indexable type,
     * ensuring consistency between bulk and single-item indexing operations.
     *
     * @param Indexable $indexable The indexable instance
     * @param array $items Array of items to index
     * @return array{indexed: int, skipped: int, errors: int} Statistics about the batch operation
     */
    private function indexItemsBatch(Indexable $indexable, array $items): array
    {
        if ($indexable instanceof PostIndexable) {
            $statistics = $this->postSingleIndexer->indexPosts($items, $indexable);
            MetaValueFlags::flush();

            return $statistics;
        }
        
        if ($indexable instanceof TaxonomyIndexable) {
            $statistics = $this->taxonomySingleIndexer->indexTerms($items, $indexable);
            MetaValueFlags::flush();

            return $statistics;
        }

        // Fallback to old method for unknown indexable types
        $statistics = ['indexed' => 0, 'skipped' => 0, 'errors' => 0];
        $documents = [];
        
        foreach ($items as $item) {
            try {
                $documents[] = $indexable->formatForIndexing($item);
                $statistics['indexed']++;
            } catch (\Exception $e) {
                $statistics['errors']++;
                $this->log('error', 'Error formatting an item: ' . $e->getMessage());
            }
        }

        if (!empty($documents)) {
            try {
                $index = $this->client->index($indexable->getIndexName());
                $index->addDocuments($documents);
            } catch (\Exception $e) {
                $this->log('error', 'Error during batch indexing: ' . $e->getMessage());
                $statistics['errors'] += $statistics['indexed'];
                $statistics['indexed'] = 0;
            }
        }

        return $statistics;
    }

    /**
     * Initializes a new logging session.
     *
     * @return string The session token for accessing logs
     */
    public function initializeLog(): string
    {
        return $this->logger->initializeSession();
    }

    /**
     * Logs an indexing operation.
     *
     * @param string $type The log entry type
     * @param string $message The log message
     * @param bool $forceFlush Force immediate write to file
     */
    public function log(string $type, string $message, bool $forceFlush = false): void
    {
        $this->logger->log($type, $message, $forceFlush);
    }

    /**
     * Gets the current session token.
     *
     * @return string|null The current token or null if no session
     */
    public function getToken(): ?string
    {
        return $this->logger->getToken();
    }

    /**
     * Gets the current log data.
     *
     * @return array<string, mixed>|null Log data or null if no active session
     */
    public function getCurrentLog(): ?array
    {
        return $this->logger->getCurrentLog();
    }

    /**
     * Gets log data by token.
     *
     * @param string $token The session token
     * @return array<string, mixed>|null Log data or null if invalid/not found
     */
    public function getLogByToken(string $token): ?array
    {
        return $this->logger->getLogByToken($token);
    }

    /**
     * Checks if an index exists in Meilisearch.
     *
     * Uses caching to avoid repeated API calls.
     *
     * @param string $indexName The index name
     * @return bool True if the index exists, false otherwise
     */
    private function indexExists(string $indexName): bool
    {
        // Check cache first
        if (isset($this->indexExistsCache[$indexName])) {
            return $this->indexExistsCache[$indexName];
        }

        try {
            // One GET for this index: the index list is paginated, 20 at a time
            $this->client->getIndex($indexName);
            $this->indexExistsCache[$indexName] = true;
        } catch (ApiException $e) {
            if ($e->httpStatus !== 404) {
                throw $e;
            }

            $this->indexExistsCache[$indexName] = false;
        }

        return $this->indexExistsCache[$indexName];
    }

    /**
     * Clears the index existence cache.
     *
     * Call this when clearing/deleting indexes.
     */
    public function clearIndexCache(): void
    {
        $this->indexExistsCache = [];
    }
}
