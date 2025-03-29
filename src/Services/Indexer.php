<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Meilisearch\Client;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Indexables\TaxonomyIndexable;

use function apply_filters;
use function current_time;
use function esc_sql;
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
     *
     * @var Client
     */
    private Client $client;

    /**
     * Log of indexing operations.
     *
     * @var array
     */
    private array $indexingLog = [];

    /**
     * List of post meta keys to index.
     *
     * @var array
     */
    private array $postMetaKeys = [];

    /**
     * List of term meta keys to index.
     *
     * @var array
     */
    private array $termMetaKeys = [];

    private array $indexables;

    /**
     * Creates a new Indexer instance.
     */
    public function __construct()
    {
        $this->client = ClientFactory::getClient();
        $this->indexables = [
            new PostIndexable(),
            new TaxonomyIndexable()
        ];
    }

    /**
     * Indexes content in Meilisearch.
     *
     * @param bool $clearIndices Whether to clear existing indices before indexing
     * @return void
     */
    public function index(bool $clearIndices = false): void
    {
        $this->initializeLog();

        try {
            // Sauvegarder la structure d'indexation actuelle
            $this->saveIndexingStructure();

            foreach ($this->indexables as $indexable) {
                $indexName = $indexable->getIndexName();
                $this->log('info', sprintf('Début de l\'indexation pour %s', $indexName));

                // Configuration de l'index
                if ($clearIndices) {
                    $this->client->deleteIndex($indexName);
                }

                // Création de l'index avec la clé primaire
                if ($clearIndices || !$this->client->getIndex($indexName)->exists()) {
                    $this->client->createIndex($indexName, [
                        'primaryKey' => $indexable->getPrimaryKey(),
                    ]);
                }

                $index = $this->client->index($indexName);
                $index->updateSettings($indexable->getIndexSettings());

                // Indexation des documents
                $documents = [];
                $totalIndexed = 0;
                $batchSize = 100;

                foreach ($indexable->getItems() as $item) {
                    $documents[] = $indexable->formatForIndexing($item);

                    if (count($documents) >= $batchSize) {
                        $index->addDocuments($documents);
                        $totalIndexed += count($documents);
                        $this->log('info', sprintf('Lot de %d éléments indexés', count($documents)));
                        $documents = [];
                    }
                }

                if (!empty($documents)) {
                    $index->addDocuments($documents);
                    $totalIndexed += count($documents);
                    $this->log('info', sprintf('Dernier lot de %d éléments indexés', count($documents)));
                }

                $this->log('success', sprintf('Total de %d éléments indexés pour %s', $totalIndexed, $indexName));
            }

            $this->log('success', 'Indexation terminée avec succès');
        } catch (\Exception $e) {
            $this->log('error', 'Erreur lors de l\'indexation : ' . $e->getMessage());
            throw $e;
        }

        $this->saveLog();
    }

    /**
     * Sauvegarde la structure d'indexation actuelle.
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
     * Vérifie si la structure d'indexation a changé.
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

        // Vérifier les changements dans les post types
        $addedPostTypes = array_diff($currentStructure['post_types'], $lastStructure['post_types']);
        $removedPostTypes = array_diff($lastStructure['post_types'], $currentStructure['post_types']);
        if (!empty($addedPostTypes) || !empty($removedPostTypes)) {
            $hasChanged = true;
            $changes['post_types'] = [
                'added' => array_values($addedPostTypes),
                'removed' => array_values($removedPostTypes),
            ];
        }

        // Vérifier les changements dans les taxonomies
        $addedTaxonomies = array_diff($currentStructure['taxonomies'], $lastStructure['taxonomies']);
        $removedTaxonomies = array_diff($lastStructure['taxonomies'], $currentStructure['taxonomies']);
        if (!empty($addedTaxonomies) || !empty($removedTaxonomies)) {
            $hasChanged = true;
            $changes['taxonomies'] = [
                'added' => array_values($addedTaxonomies),
                'removed' => array_values($removedTaxonomies),
            ];
        }

        // Vérifier les changements dans les meta keys
        $addedMetaKeys = array_diff($currentStructure['meta_keys'], $lastStructure['meta_keys']);
        $removedMetaKeys = array_diff($lastStructure['meta_keys'], $currentStructure['meta_keys']);
        if (!empty($addedMetaKeys) || !empty($removedMetaKeys)) {
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
            $this->log('info', 'Début de la purge des indices');

            // Purge de l'index des posts
            $postsIndex = $this->client->index('posts');
            $postsIndex->delete();
            $this->log('success', 'Index "posts" supprimé');

            // Purge de l'index des taxonomies
            $taxonomiesIndex = $this->client->index('taxonomies');
            $taxonomiesIndex->delete();
            $this->log('success', 'Index "taxonomies" supprimé');

            $this->log('success', 'Purge terminée avec succès');
        } catch (\Exception $e) {
            $this->log('error', 'Erreur lors de la purge : ' . $e->getMessage());
            throw $e;
        }

        $this->saveLog();
    }

    private function gatherMetaKeys(array $postTypes, array $taxonomies): void
    {
        global $wpdb;

        // Récupération des clés de métadonnées pour les posts
        if (!empty($postTypes)) {
            $postTypesStr = "'" . implode("','", array_map('esc_sql', $postTypes)) . "'";
            $query = "
                SELECT DISTINCT meta_key
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type IN ({$postTypesStr})
                AND meta_key NOT LIKE '\_%'
            ";
            $this->postMetaKeys = $wpdb->get_col($query);
            $this->log('info', sprintf('Récupération de %d clés de métadonnées pour les posts', count($this->postMetaKeys)));
        }

        // Récupération des clés de métadonnées pour les termes
        if (!empty($taxonomies)) {
            $taxonomiesStr = "'" . implode("','", array_map('esc_sql', $taxonomies)) . "'";
            $query = "
                SELECT DISTINCT meta_key
                FROM {$wpdb->termmeta} tm
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                WHERE tt.taxonomy IN ({$taxonomiesStr})
                AND meta_key NOT LIKE '\_%'
            ";
            $this->termMetaKeys = $wpdb->get_col($query);
            $this->log('info', sprintf('Récupération de %d clés de métadonnées pour les termes', count($this->termMetaKeys)));
        }
    }

    private function configureIndices(bool $clearIndices): void
    {
        $this->log('info', 'Configuration des indices');

        // Configuration de l'index des posts
        if ($clearIndices) {
            $this->client->deleteIndex('posts');
            $this->client->createIndex('posts');
        }

        $postsIndex = $this->client->index('posts');

        // Préparation des attributs pour les posts
        $searchableAttributes = [
            'post_title',
            'post_content',
            'post_excerpt',
            'taxonomies.name',
            'taxonomies.slug'
        ];

        $filterableAttributes = [
            'post_type',
            'taxonomies.taxonomy',
            'taxonomies.term_id',
            'taxonomies.name',
            'taxonomies.slug'
        ];

        // Ajout des métadonnées aux attributs
        foreach ($this->postMetaKeys as $metaKey) {
            $searchableAttributes[] = 'meta.' . $metaKey;
        }

        // Ajout des métadonnées filtrables via le filtre WordPress
        $filterableMetaKeys = apply_filters('meiliscout/post/metas/filterables', [], $this->postMetaKeys);
        foreach ($filterableMetaKeys as $metaKey) {
            if (in_array($metaKey, $this->postMetaKeys)) {
                $filterableAttributes[] = 'meta.' . $metaKey;
            }
        }

        // Configuration des paramètres de recherche pour les posts
        $postsIndex->updateSettings([
            'searchableAttributes' => $searchableAttributes,
            'filterableAttributes' => $filterableAttributes,
            'sortableAttributes' => [
                'post_date',
                ...array_map(fn($key) => 'meta.' . $key, $filterableMetaKeys)
            ]
        ]);

        // Configuration similaire pour les taxonomies
        if ($clearIndices) {
            $this->client->deleteIndex('taxonomies');
            $this->client->createIndex('taxonomies');
        }

        $taxonomiesIndex = $this->client->index('taxonomies');

        // Préparation des attributs pour les taxonomies
        $searchableAttributes = [
            'name',
            'description'
        ];

        $filterableAttributes = [
            'taxonomy'
        ];

        // Ajout des métadonnées aux attributs
        foreach ($this->termMetaKeys as $metaKey) {
            $searchableAttributes[] = 'meta.' . $metaKey;
        }

        // Ajout des métadonnées filtrables via le filtre WordPress
        $filterableMetaKeys = apply_filters('meiliscout/term/metas/filterables', [], $this->termMetaKeys);
        foreach ($filterableMetaKeys as $metaKey) {
            if (in_array($metaKey, $this->termMetaKeys)) {
                $filterableAttributes[] = 'meta.' . $metaKey;
            }
        }

        $taxonomiesIndex->updateSettings([
            'searchableAttributes' => $searchableAttributes,
            'filterableAttributes' => $filterableAttributes
        ]);
    }

    private function indexPosts(array $postTypes): void
    {
        $this->log('info', 'Début de l\'indexation des posts');

        $postsIndex = $this->client->index('posts');
        $documents = [];
        $totalIndexed = 0;
        $batchSize = 100;

        // Compter d'abord le nombre total de posts à indexer
        $totalPosts = 0;
        foreach ($postTypes as $postType) {
            $count = wp_count_posts($postType);
            $totalPosts += $count->publish;
        }

        // Décider si on utilise le batch ou non
        $useBatch = $totalPosts > $batchSize;

        if ($useBatch) {
            $this->log('info', sprintf('Mode batch activé pour %d posts', $totalPosts));
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
                if (!$useBatch || ($useBatch && count($documents) >= $batchSize)) {
                    $postsIndex->addDocuments($documents);
                    $totalIndexed += count($documents);
                    $this->log('info', sprintf('Lot de %d posts indexés', count($documents)));
                    $documents = [];
                }
            }

            wp_reset_postdata();
        }

        // Indexer les documents restants
        if (!empty($documents)) {
            $postsIndex->addDocuments($documents);
            $totalIndexed += count($documents);
            $this->log('info', sprintf('%s%d posts indexés',
                $useBatch ? 'Dernier lot de ' : '',
                count($documents)
            ));
        }

        $this->log('success', sprintf('Total de %d posts indexés', $totalIndexed));
    }

    private function formatPostForIndexing(\WP_Post $post): array
    {
        // Récupère dynamiquement toutes les propriétés de l'objet WP_Post
        $document = get_object_vars($post);

        // Ajoute les champs supplémentaires
        $document['url'] = get_permalink($post);
        $document['taxonomies'] = [];
        $document['meta'] = [];

        // Récupération des termes de taxonomies
        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post->ID, $taxonomy);
            foreach ($terms as $term) {
                $document['taxonomies'][] = [
                    'taxonomy' => $taxonomy,
                    'identifier' => (int) $term->term_id, // Remplace term_id
                    'name' => wp_strip_all_tags($term->name),
                    'slug' => $term->slug
                ];
            }
        }

        return $document;
    }

    private function indexTaxonomies(array $taxonomies): void
    {
        $this->log('info', 'Début de l\'indexation des taxonomies');

        $taxonomiesIndex = $this->client->index('taxonomies');
        $documents = [];
        $totalIndexed = 0;
        $batchSize = 100;

        // Compter d'abord le nombre total de termes à indexer
        $totalTerms = 0;
        foreach ($taxonomies as $taxonomy) {
            $totalTerms += wp_count_terms(['taxonomy' => $taxonomy]);
        }

        // Décider si on utilise le batch ou non
        $useBatch = $totalTerms > $batchSize;

        if ($useBatch) {
            $this->log('info', sprintf('Mode batch activé pour %d termes', $totalTerms));
        }

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
            ]);

            foreach ($terms as $term) {
                $documents[] = $this->formatTaxonomyForIndexing($term);

                // Si le mode batch est activé et qu'on atteint la taille du batch
                if (!$useBatch || ($useBatch && count($documents) >= $batchSize)) {
                    $taxonomiesIndex->addDocuments($documents);
                    $totalIndexed += count($documents);
                    $this->log('info', sprintf('Lot de %d termes indexés', count($documents)));
                    $documents = [];
                }
            }
        }

        // Indexer les documents restants
        if (!empty($documents)) {
            $taxonomiesIndex->addDocuments($documents);
            $totalIndexed += count($documents);
            $this->log('info', sprintf('%s%d termes indexés',
                $useBatch ? 'Dernier lot de ' : '',
                count($documents)
            ));
        }

        $this->log('success', sprintf('Total de %d termes indexés', $totalIndexed));
    }

    private function formatTaxonomyForIndexing(\WP_Term $term): array
    {
        // Récupère dynamiquement toutes les propriétés de l'objet WP_Term
        $document = get_object_vars($term);

        // Ajoute les champs supplémentaires
        $document['url'] = get_term_link($term);
        $document['meta'] = [];

        return $document;
    }

    /**
     * Normalise les clés contenant 'id' dans un tableau.
     */
    private function normalizeIdKeys(array $data): array
    {
        $result = [];
        $idMapping = [
            'ID' => 'id', // Garde uniquement l'id principal pour Meilisearch
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
     * Restaure les clés ID originales dans un tableau.
     * À utiliser lors de la récupération des résultats.
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
            'entries' => []
        ];

        update_option('meiliscout/last_indexing_log', $this->indexingLog);
    }

    public function log(string $type, string $message): void
    {
        $this->indexingLog['entries'][] = [
            'type' => $type,
            'message' => $message,
            'time' => current_time('mysql')
        ];
        $this->saveLog();
    }

    private function saveLog(): void
    {
        $this->indexingLog['end_time'] = current_time('mysql');

        // Ne mettre le statut completed que lors de la fin réelle de l'indexation
        if ($this->indexingLog['entries'][count($this->indexingLog['entries']) - 1]['type'] === 'success'
            && strpos($this->indexingLog['entries'][count($this->indexingLog['entries']) - 1]['message'], 'terminée') !== false) {
            $this->indexingLog['status'] = 'completed';
        }

        update_option('meiliscout/last_indexing_log', $this->indexingLog);
    }
}
