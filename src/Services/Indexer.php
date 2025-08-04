<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Meilisearch\Client;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\Indexable;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Indexables\TaxonomyIndexable;
use Pollora\MeiliScout\Services\PostSingleIndexer;
use Pollora\MeiliScout\Services\TaxonomySingleIndexer;

use function apply_filters;
use function current_time;
use function get_object_taxonomies;
use function get_object_vars;
use function get_permalink;
use function get_post;
use function get_term_link;
use function get_terms;
use function update_option;
use function wp_count_posts;
use function wp_count_terms;
use function wp_get_post_terms;
use function wp_reset_postdata;
use function wp_strip_all_tags;

/**
 * Service for managing Meilisearch indexing operations.
 */
class Indexer
{
    /**
     * Meilisearch client instance.
     */
    private Client $client;

    /**
     * Log of indexing operations.
     */
    private array $indexingLog = [];

    /**
     * List of post meta keys to index.
     */
    private array $postMetaKeys = [];

    /**
     * List of term meta keys to index.
     */
    private array $termMetaKeys = [];

    private array $indexables;

    /**
     * Post single indexer instance for individual post operations.
     */
    private PostSingleIndexer $postSingleIndexer;

    /**
     * Taxonomy single indexer instance for individual taxonomy operations.
     */
    private TaxonomySingleIndexer $taxonomySingleIndexer;

    /**
     * Creates a new Indexer instance.
     */
    public function __construct()
    {
        $this->client = ClientFactory::getClient();
        $this->indexables = [
            new PostIndexable,
            new TaxonomyIndexable,
        ];
        $this->postSingleIndexer = new PostSingleIndexer();
        $this->taxonomySingleIndexer = new TaxonomySingleIndexer();
    }

    /**
     * Indexes content in Meilisearch.
     *
     * @param  bool  $clearIndices  Whether to clear existing indices before indexing
     */
    public function index(bool $clearIndices = false): void
    {
        $this->initializeLog();

        try {
            // Save the current indexing structure
            $this->saveIndexingStructure();

            foreach ($this->indexables as $indexable) {
                $indexName = $indexable->getIndexName();
                $this->log('info', sprintf('Starting indexation for %s', $indexName));

                // Index configuration
                if ($clearIndices) {
                    $this->client->deleteIndex($indexName);
                }

                // Create index with primary key
                if ($clearIndices || ! $this->indexExists($indexName)) {
                    $this->client->createIndex($indexName, [
                        'primaryKey' => $indexable->getPrimaryKey(),
                    ]);
                }

                $index = $this->client->index($indexName);
                $index->updateSettings($indexable->getIndexSettings());

                // Document indexing
                $documents = [];
                $totalIndexed = 0;
                $batchSize = 100;

                // Use single indexers for consistency and shared logic
                $items = [];
                foreach ($indexable->getItems() as $item) {
                    $items[] = $item;
                    
                    if (count($items) >= $batchSize) {
                        $batchStats = $this->indexItemsBatch($indexable, $items);
                        $totalIndexed += $batchStats['indexed'];
                        $this->log('info', sprintf('Batch of %d items processed (%d indexed, %d skipped)', 
                            count($items), $batchStats['indexed'], $batchStats['skipped']));
                        $items = [];
                    }
                }

                if (! empty($items)) {
                    $batchStats = $this->indexItemsBatch($indexable, $items);
                    $totalIndexed += $batchStats['indexed'];
                    $this->log('info', sprintf('Last batch of %d items processed (%d indexed, %d skipped)', 
                        count($items), $batchStats['indexed'], $batchStats['skipped']));
                }

                $this->log('success', sprintf('Total of %d items indexed for %s', $totalIndexed, $indexName));
            }

            $this->log('success', 'Indexing completed successfully');
        } catch (\Exception $e) {
            $this->log('error', 'Error during indexing: '.$e->getMessage());
            throw $e;
        }

        $this->saveLog();
    }

    /**
     * Saves the current indexing structure.
     */
    private function saveIndexingStructure(): void
    {
        $structure = [
            'post_types' => Settings::get('indexed_post_types', []),
            'taxonomies' => Settings::get('indexed_taxonomies', []),
            'meta_keys' => Settings::get('indexed_meta_keys', []),
            'last_indexed' => current_time('mysql'),
        ];

        update_option('meiliscout/last_indexing_structure', $structure);
    }

    /**
     * Checks if the indexing structure has changed.
     *
     * @return array{has_changed: bool, changes: array}
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

        return [
            'has_changed' => $hasChanged,
            'changes' => $changes,
            'last_indexed' => $lastStructure['last_indexed'] ?? null,
        ];
    }

    public function purge(): void
    {
        $this->initializeLog();

        try {
            $this->log('info', 'Starting indices purge');

            // Purge posts index
            $postsIndex = $this->client->index('posts');
            $postsIndex->delete();
            $this->log('success', 'Index "posts" deleted');

            // Purge taxonomies index
            $taxonomiesIndex = $this->client->index('taxonomies');
            $taxonomiesIndex->delete();
            $this->log('success', 'Index "taxonomies" deleted');

            $this->log('success', 'Purge completed successfully');
        } catch (\Exception $e) {
            $this->log('error', 'Error during purge: '.$e->getMessage());
            throw $e;
        }

        $this->saveLog();
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
            return $this->postSingleIndexer->indexPosts($items);
        }
        
        if ($indexable instanceof TaxonomyIndexable) {
            return $this->taxonomySingleIndexer->indexTerms($items);
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

    private function gatherMetaKeys(array $postTypes, array $taxonomies): void
    {
        global $wpdb;

        // Retrieve metadata keys for posts
        if (! empty($postTypes)) {
            $escapedTypes = array_map(fn($type) => esc_sql((string) $type), $postTypes);
            $postTypesStr = "'".implode("','", $escapedTypes)."'";
            $query = "
                SELECT DISTINCT meta_key
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type IN ({$postTypesStr})
            ";
            $this->postMetaKeys = $wpdb->get_col($query);
            $this->log('info', sprintf('Retrieved %d metadata keys for posts', count($this->postMetaKeys)));
        }

        // Retrieve metadata keys for terms
        if (! empty($taxonomies)) {
            $escapedTaxonomies = array_map(fn($taxonomy) => esc_sql((string) $taxonomy), $taxonomies);
            $taxonomiesStr = "'".implode("','", $escapedTaxonomies)."'";
            $query = "
                SELECT DISTINCT meta_key
                FROM {$wpdb->termmeta} tm
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                WHERE tt.taxonomy IN ({$taxonomiesStr})
                AND meta_key NOT LIKE '\_%'
            ";
            $this->termMetaKeys = $wpdb->get_col($query);
            $this->log('info', sprintf('Retrieved %d metadata keys for terms', count($this->termMetaKeys)));
        }
    }

    private function configureIndices(bool $clearIndices): void
    {
        $this->log('info', 'Configuring indices');

        // Posts index configuration
        if ($clearIndices) {
            $this->client->deleteIndex('posts');
            $this->client->createIndex('posts');
        }

        $postsIndex = $this->client->index('posts');

        // Prepare attributes for posts
        $searchableAttributes = [
            'post_title',
            'post_content',
            'post_excerpt',
            'taxonomies.name',
            'taxonomies.slug',
        ];

        $filterableAttributes = [
            'post_type',
            'taxonomies.taxonomy',
            'taxonomies.term_id',
            'taxonomies.name',
            'taxonomies.slug',
        ];

        // Add metadata to attributes
        foreach ($this->postMetaKeys as $metaKey) {
            $searchableAttributes[] = 'meta.'.$metaKey;
        }

        // Ajout des métadonnées filtrables via le filtre WordPress
        $filterableMetaKeys = apply_filters('meiliscout/post/metas/filterables', [], $this->postMetaKeys);
        foreach ($filterableMetaKeys as $metaKey) {
            if (in_array($metaKey, $this->postMetaKeys)) {
                $filterableAttributes[] = 'meta.'.$metaKey;
            }
        }

        // Configure search parameters for posts
        $postsIndex->updateSettings([
            'searchableAttributes' => $searchableAttributes,
            'filterableAttributes' => $filterableAttributes,
            'sortableAttributes' => [
                'post_date',
                ...array_map(fn ($key) => 'meta.'.$key, $filterableMetaKeys),
            ],
        ]);

        // Similar configuration for taxonomies
        if ($clearIndices) {
            $this->client->deleteIndex('taxonomies');
            $this->client->createIndex('taxonomies');
        }

        $taxonomiesIndex = $this->client->index('taxonomies');

        // Prepare attributes for taxonomies
        $searchableAttributes = [
            'name',
            'description',
        ];

        $filterableAttributes = [
            'taxonomy',
        ];

        // Add metadata to attributes
        foreach ($this->termMetaKeys as $metaKey) {
            $searchableAttributes[] = 'meta.'.$metaKey;
        }

        // Ajout des métadonnées filtrables via le filtre WordPress
        $filterableMetaKeys = apply_filters('meiliscout/term/metas/filterables', [], $this->termMetaKeys);
        foreach ($filterableMetaKeys as $metaKey) {
            if (in_array($metaKey, $this->termMetaKeys)) {
                $filterableAttributes[] = 'meta.'.$metaKey;
            }
        }

        $taxonomiesIndex->updateSettings([
            'searchableAttributes' => $searchableAttributes,
            'filterableAttributes' => $filterableAttributes,
        ]);
    }

    private function indexPosts(array $postTypes): void
    {
        $this->log('info', 'Starting posts indexing');

        $postsIndex = $this->client->index('posts');
        $documents = [];
        $totalIndexed = 0;
        $batchSize = 100;

        // First count total number of posts to index
        $totalPosts = 0;
        foreach ($postTypes as $postType) {
            $count = wp_count_posts($postType);
            $totalPosts += $count->publish;
        }

        // Decide whether to use batch mode
        $useBatch = $totalPosts > $batchSize;

        if ($useBatch) {
            $this->log('info', sprintf('Batch mode enabled for %d posts', $totalPosts));
        }

        foreach ($postTypes as $postType) {
            $query = new \WP_Query([
                'post_type' => $postType,
                'posts_per_page' => -1,
                'post_status' => 'publish',
            ]);

            while ($query->have_posts()) {
                $query->the_post();
                $post = get_post();
                $documents[] = $this->formatPostForIndexing($post);

                // Si le mode batch est activé et qu'on atteint la taille du batch
                if (! $useBatch || ($useBatch && count($documents) >= $batchSize)) {
                    $postsIndex->addDocuments($documents);
                    $totalIndexed += count($documents);
                    $this->log('info', sprintf('Batch of %d posts indexed', count($documents)));
                    $documents = [];
                }
            }

            wp_reset_postdata();
        }

        // Indexer les documents restants
        if (! empty($documents)) {
            $postsIndex->addDocuments($documents);
            $totalIndexed += count($documents);
            $this->log('info', sprintf('%s%d posts indexed',
                $useBatch ? 'Last batch of ' : '',
                count($documents)
            ));
        }

        $this->log('success', sprintf('Total of %d posts indexed', $totalIndexed));
    }

    private function formatPostForIndexing(\WP_Post $post): array
    {
        // Dynamically retrieve all WP_Post object properties
        $document = get_object_vars($post);

        // Add additional fields
        $document['url'] = get_permalink($post);
        $document['taxonomies'] = [];
        $document['meta'] = [];

        // Retrieve taxonomy terms
        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post->ID, $taxonomy);
            foreach ($terms as $term) {
                $document['taxonomies'][] = [
                    'taxonomy' => $taxonomy,
                    'identifier' => (int) $term->term_id, // Replace term_id
                    'name' => wp_strip_all_tags($term->name),
                    'slug' => $term->slug,
                ];
            }
        }

        return $document;
    }

    private function indexTaxonomies(array $taxonomies): void
    {
        $this->log('info', 'Starting taxonomies indexing');

        $taxonomiesIndex = $this->client->index('taxonomies');
        $documents = [];
        $totalIndexed = 0;
        $batchSize = 100;

        // First count total number of terms to index
        $totalTerms = 0;
        foreach ($taxonomies as $taxonomy) {
            $totalTerms += wp_count_terms(['taxonomy' => $taxonomy]);
        }

        // Decide whether to use batch mode
        $useBatch = $totalTerms > $batchSize;

        if ($useBatch) {
            $this->log('info', sprintf('Batch mode enabled for %d terms', $totalTerms));
        }

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
            ]);

            foreach ($terms as $term) {
                $documents[] = $this->formatTaxonomyForIndexing($term);

                // Si le mode batch est activé et qu'on atteint la taille du batch
                if (! $useBatch || ($useBatch && count($documents) >= $batchSize)) {
                    $taxonomiesIndex->addDocuments($documents);
                    $totalIndexed += count($documents);
                    $this->log('info', sprintf('Batch of %d terms indexed', count($documents)));
                    $documents = [];
                }
            }
        }

        // Indexer les documents restants
        if (! empty($documents)) {
            $taxonomiesIndex->addDocuments($documents);
            $totalIndexed += count($documents);
            $this->log('info', sprintf('%s%d terms indexed',
                $useBatch ? 'Last batch of ' : '',
                count($documents)
            ));
        }

        $this->log('success', sprintf('Total of %d terms indexed', $totalIndexed));
    }

    private function formatTaxonomyForIndexing(\WP_Term $term): array
    {
        // Dynamically retrieve all WP_Term object properties
        $document = get_object_vars($term);

        // Add additional fields
        $document['url'] = get_term_link($term);
        $document['meta'] = [];

        return $document;
    }

    /**
     * Normalizes keys containing 'id' in an array.
     */
    private function normalizeIdKeys(array $data): array
    {
        $result = [];
        $idMapping = [
            'ID' => 'id', // Keep only main id for Meilisearch
            'post_id' => 'post_identifier',
            'term_id' => 'term_identifier',
            'guid' => 'rawUrl',
        ];

        foreach ($data as $key => $value) {
            $newKey = $key;
            foreach ($idMapping as $oldKey => $newValue) {
                if (strcasecmp($key, $oldKey) === 0) {
                    $newKey = $newValue;
                    break;
                }
            }
            $result[$newKey] = $value;
        }

        return $result;
    }

    /**
     * Restores original ID keys in an array.
     * To use when retrieving results.
     */
    public function restoreIdKeys(array $data): array
    {
        $result = [];
        $idMapping = [
            'id' => 'ID',
            'post_identifier' => 'post_id',
            'term_identifier' => 'term_id',
            'parent_identifier' => 'parent_id',
            'author_identifier' => 'author_id',
        ];

        foreach ($data as $key => $value) {
            $newKey = $key;
            foreach ($idMapping as $oldKey => $newValue) {
                if ($key === $oldKey) {
                    $newKey = $newValue;
                    break;
                }
            }
            $result[$newKey] = $value;
        }

        return $result;
    }

    public function initializeLog(): void
    {
        $this->indexingLog = [
            'start_time' => current_time('mysql'),
            'status' => 'pending',
            'entries' => [],
        ];

        update_option('meiliscout/last_indexing_log', $this->indexingLog);
    }

    public function log(string $type, string $message): void
    {
        $this->indexingLog['entries'][] = [
            'type' => $type,
            'message' => $message,
            'time' => current_time('mysql'),
        ];
        $this->saveLog();
    }

    private function saveLog(): void
    {
        $this->indexingLog['end_time'] = current_time('mysql');

        // Only set status to completed at the actual end of indexing
        if ($this->indexingLog['entries'][count($this->indexingLog['entries']) - 1]['type'] === 'success'
            && strpos($this->indexingLog['entries'][count($this->indexingLog['entries']) - 1]['message'], 'completed') !== false) {
            $this->indexingLog['status'] = 'completed';
        }

        update_option('meiliscout/last_indexing_log', $this->indexingLog);
    }

    /**
     * Checks if an index exists in Meilisearch.
     *
     * @param string $indexName The index name
     * @return bool True if the index exists, false otherwise
     */
    private function indexExists(string $indexName): bool
    {
        try {
            $indexes = $this->client->getIndexes();
            foreach ($indexes['results'] as $index) {
                if ($index['uid'] === $indexName) {
                    return true;
                }
            }
            return false;
        } catch (\Exception) {
            return false;
        }
    }
}
