<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Meilisearch\Client;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Domain\Search\Enums\TaxonomyFields;
use Pollora\MeiliScout\Services\ClientFactory;
use WP_Query;

class QueryIntegration
{
    private ?Client $client;
    private MeiliQueryBuilder $builder;

    public function __construct(MeiliQueryBuilder $builder)
    {
        $this->client = ClientFactory::getClient();

        if (!$this->client) {
            return;
        }

        $this->builder = $builder;
        add_filter('posts_pre_query', [$this, 'interceptQuery'], 10, 2);
    }

    public function interceptQuery($posts, WP_Query $query): ?array
    {
        if (! isset($query->query_vars['use_meilisearch']) || ! $query->query_vars['use_meilisearch']) {
            return $posts;
        }

        $searchParams = $this->buildSearchParams($query);

        // Si des clés méta non indexables sont trouvées, on repasse en mode WP_Query classique
        if ($this->builder->hasNonIndexableMetaKeys()) {
            $query->query_vars['use_meilisearch'] = false;
            return $posts;
        }

        // Récupérer les taxonomies indexées
        $postTypes = Settings::get('indexed_post_types', []);
        $facetAttributes = [];

        // Pour chaque type de post indexé
        foreach ($postTypes as $postType) {
            $taxonomies = get_object_taxonomies($postType);
            foreach ($taxonomies as $taxonomy) {
                foreach (TaxonomyFields::values() as $field) {
                    $facetAttributes[] = "taxonomies.{$taxonomy}.{$field}";
                }
            }
        }

        // Ajouter les paramètres pour les facettes
        $searchParams['facets'] = array_unique($facetAttributes);

        $results = $this->client->index('posts')->search('', $searchParams);

        $query->found_posts = $query->post_count = $results->getHitsCount();
        $query->max_num_pages = ceil($results->getHitsCount() / $results->getLimit());
        $query->posts = $this->convertToWpPosts($results->getHits());

        // Récupérer et formater la distribution des facettes
        $facetDistribution = $results->getFacetDistribution() ?? [];
        $formattedFacets = [];

        // Parcourir les facettes et les reformater pour correspondre à la structure attendue
        foreach ($facetDistribution as $facetKey => $values) {
            // Extraire la taxonomie et le champ depuis la clé (ex: "taxonomies.category.term_id")
            $parts = explode('.', $facetKey);
            if (count($parts) === 3 && $parts[0] === 'taxonomies') {
                $taxonomy = $parts[1];
                $field = $parts[2];

                if (!isset($formattedFacets[$taxonomy])) {
                    $formattedFacets[$taxonomy] = [];
                }

                $formattedFacets[$taxonomy][$field] = $values;
            }
        }

        $query->facet_distribution = $formattedFacets;
        $query->facet_raw = $results->getRaw();

        return $query->posts;
    }

    public function buildSearchParams(WP_Query $query): array
    {
        return $this->builder->build(new WPQueryAdapter($query));
    }

    private function convertToWpPosts(array $hits): array
    {
        return array_map(function ($hit) {
            return new \WP_Post((object) $hit);
        }, $hits);
    }
}
