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

        // Récupérer et ajouter le reste des propriétés
        $document = get_object_vars($item);

        // Ajouter les champs supplémentaires
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
                $meta[$key] = $value;
            }
        }

        return $meta;
    }

    private function gatherMetaKeys(array $taxonomies): void
    {
        global $wpdb;

        if (! empty($taxonomies)) {
            $taxonomiesStr = "'".implode("','", array_map('esc_sql', $taxonomies))."'";
            $query = "
                SELECT DISTINCT meta_key
                FROM {$wpdb->termmeta} tm
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                WHERE tt.taxonomy IN ({$taxonomiesStr})
                AND meta_key NOT LIKE '\_%'
            ";
            $this->metaKeys = $wpdb->get_col($query);
        }
    }
}
