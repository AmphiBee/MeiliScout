<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Indexables;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\Indexable;
use Pollora\MeiliScout\Domain\Search\Enums\TaxonomyFields;
use WP_Post;

use function get_object_taxonomies;
use function get_permalink;
use function get_post_meta;
use function get_posts;
use function wp_get_post_terms;

class PostIndexable implements Indexable
{
    private array $metaKeys = [];

    public function getIndexName(): string
    {
        return 'posts';
    }

    public function getPrimaryKey(): string
    {
        return 'ID';
    }

    public function getIndexSettings(): array
    {
        $postTypes = Settings::get('indexed_post_types', []);
        $filterableMetaKeys = Settings::get('indexed_meta_keys', []);

        $filterableAttributes = [
            'post_type',
            'post_status',
        ];

        // Ajouter les meta keys filtrables
        foreach ($filterableMetaKeys as $metaKey) {
            $filterableAttributes[] = "metas.{$metaKey}";
        }

        // Récupérer toutes les taxonomies associées aux types de posts indexés
        foreach ($postTypes as $postType) {
            $taxonomies = get_object_taxonomies($postType);
            foreach ($taxonomies as $taxonomy) {
                foreach (TaxonomyFields::values() as $field) {
                    $filterableAttributes[] = "taxonomies.{$taxonomy}.{$field}";
                }
            }
        }

        return [
            'filterableAttributes' => array_unique($filterableAttributes),
            'sortableAttributes' => [
                'post_date',
                ...array_map(fn ($key) => "metas.{$key}", $filterableMetaKeys),
            ],
        ];
    }

    public function getItems(): iterable
    {
        $postTypes = Settings::get('indexed_post_types', []);
        $this->metaKeys = $this->gatherMetaKeys($postTypes);

        foreach ($postTypes as $postType) {

            $posts = get_posts([
                'post_type' => $postType,
                'posts_per_page' => -1,
                'post_status' => 'any',
            ]);

            foreach ($posts as $post) {
                yield $post;
            }
        }
    }

    private function gatherMetaKeys(array $postTypes): array
    {
        global $wpdb;

        $metaKeys = [];
        if (! empty($postTypes)) {
            $postTypesStr = "'".implode("','", array_map('esc_sql', $postTypes))."'";
            $query = "
                SELECT DISTINCT meta_key
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type IN ({$postTypesStr})
                AND meta_key NOT LIKE '\_%'
            ";
            $metaKeys = $wpdb->get_col($query);
        }

        return $metaKeys;
    }

    public function formatForIndexing(mixed $item): array
    {
        if (! $item instanceof WP_Post) {
            throw new \InvalidArgumentException('Item must be instance of WP_Post');
        }

        // Récupérer et ajouter le reste des propriétés
        $document = get_object_vars($item);

        // Ajouter les champs supplémentaires
        $document['url'] = get_permalink($item);
        $document['taxonomies'] = $this->getTermsData($item);
        $document['metas'] = $this->getMetaData($item);

        return $document;
    }

    public function formatForSearch(array $hit): mixed
    {
        return new WP_Post((object) $hit);
    }

    private function getTermsData(WP_Post $post): array
    {
        $terms = [];
        $taxonomies = get_object_taxonomies($post->post_type);

        foreach ($taxonomies as $taxonomy) {
            $terms[$taxonomy] = wp_get_post_terms($post->ID, $taxonomy);
        }

        return $terms;
    }

    private function getMetaData(WP_Post $post): array
    {
        $meta = [];
        foreach ($this->metaKeys as $key) {
            $value = get_post_meta($post->ID, $key, true);
            if ($value !== '') {
                $meta[$key] = $value;
            }
        }

        return $meta;
    }
}
