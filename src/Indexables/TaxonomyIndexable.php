<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Indexables;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\Indexable;
use WP_Term;

class TaxonomyIndexable implements Indexable
{
    private array $metaKeys = [];

    public function getIndexName(): string
    {
        return 'taxonomies';
    }

    public function getPrimaryKey(): string
    {
        return 'term_id';
    }

    public function getIndexSettings(): array
    {
        return [
            'filterableAttributes' => ['taxonomy'],
            'sortableAttributes' => ['name'],
            'searchableAttributes' => ['name', 'description'],
        ];
    }

    public function getItems(): iterable
    {
        $taxonomies = Settings::get('indexed_taxonomies', []);
        $this->gatherMetaKeys($taxonomies);

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
            ]);

            foreach ($terms as $term) {
                yield $term;
            }
        }
    }

    public function formatForIndexing(mixed $item): array
    {
        if (! $item instanceof WP_Term) {
            throw new \InvalidArgumentException('Item must be instance of WP_Term');
        }

        // Retrieve and add the rest of the properties
        $document = get_object_vars($item);

        // Add additional fields
        $document['url'] = get_term_link($item);
        $document['metas'] = $this->getMetaData($item);

        return $document;
    }

    public function formatForSearch(array $hit): mixed
    {
        return new WP_Term((object) $hit);
    }

    private function getMetaData(WP_Term $term): array
    {
        $meta = [];
        foreach ($this->metaKeys as $key) {
            $value = get_term_meta($term->term_id, $key, true);
            if ($value !== '') {
                // Transform keys that start with underscore for Meilisearch
                $indexKey = $this->normalizeMetaKey($key);
                // Automatic casting of numeric values
                if (is_numeric($value)) {
                    $meta[$key] = $value + 0; // implicit cast to int or float
                } else {
                    $meta[$key] = $value;
                }
            }
        }

        return $meta;
    }

    /**
     * Normalizes a meta key for Meilisearch indexing.
     * Transforms keys starting with underscore to avoid conflicts.
     *
     * @param string $key The original meta key
     * @return string The normalized key for Meilisearch
     */
    private function normalizeMetaKey(string $key): string
    {
        // If the key starts with underscore, transform it
        if (str_starts_with($key, '_')) {
            return 'underscore_' . substr($key, 1);
        }

        return $key;
    }

    private function gatherMetaKeys(array $taxonomies): void
    {
        global $wpdb;

        if (! empty($taxonomies)) {
            $escapedTaxonomies = array_map(fn($taxonomy) => esc_sql((string) $taxonomy), $taxonomies);
            $taxonomiesStr = "'".implode("','", $escapedTaxonomies)."'";
            $query = "
                SELECT DISTINCT meta_key
                FROM {$wpdb->termmeta} tm
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                WHERE tt.taxonomy IN ({$taxonomiesStr})
            ";
            $this->metaKeys = $wpdb->get_col($query);
        }
    }
}
