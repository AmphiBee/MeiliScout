<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Query;

use Pollora\MeiliScout\Listings\Definition\FacetDefinition;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Render\Hits;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * What the client needs to count a listing's facets again and write its URL:
 * the facets with the filter of each value they may offer, the bounds'
 * templates, the sorts as Meilisearch takes them. No base filter: the tenant
 * token adds it, and the client could not widen it.
 *
 * The client refuses a template of another version and leaves the page as the
 * server rendered it.
 */
final class PlanTemplate
{
    public const VERSION = 3;

    /**
     * @param  array<string, list<array{value: string, id: string, label: string, depth: int, parent: string}>>  $values  Each facet's values
     * @param  array<string, mixed>  $universe  The universe search's answer
     * @return array<string, mixed>|null Null when a value's filter cannot be written
     */
    public static function build(ListingDefinition $definition, array $values, array $universe): ?array
    {
        $facets = [];

        try {
            foreach ($definition->facets as $facet) {
                $entry = [
                    'key' => $facet->key,
                    'param' => $facet->param,
                    // Its prefix in the URL's path, null for a parameter
                    'path' => $facet->path,
                    'label' => $facet->label,
                    'type' => $facet->type,
                    'logic' => $facet->logic,
                    'field' => $facet->countField(),
                    'taxonomy' => $facet->isTaxonomy(),
                    'decimals' => $facet->decimals,
                    'limit' => $facet->limit,
                    'values' => [],
                ];

                if ($facet->type === FacetDefinition::RANGE) {
                    $entry['bounds'] = FacetClauses::bounds($facet);
                    $stats = $universe['facetStats'][$facet->countField()] ?? null;
                    $entry['limits'] = $stats === null ? null : ['min' => (float) $stats['min'], 'max' => (float) $stats['max']];
                }

                foreach ($values[$facet->key] ?? [] as $value) {
                    $entry['values'][$value['value']] = [
                        'id' => $value['id'],
                        'label' => $value['label'],
                        'depth' => $value['depth'],
                        'parent' => $value['parent'],
                        'clause' => FacetClauses::value($facet, $value['value']),
                    ];
                }

                // An empty list would be a JSON array: the client reads an object
                $entry['values'] = (object) $entry['values'];
                $facets[] = $entry;
            }
        } catch (UnsupportedQuery) {
            return null;
        }

        return [
            'version' => self::VERSION,
            'listing' => $definition->id,
            'index' => IndexNames::active('posts'),
            'transport' => $definition->transport,
            'apply' => $definition->apply,
            'perPage' => $definition->perPage,
            'defaultSort' => $definition->defaultSort,
            'sortParam' => $definition->sortParam,
            'searchParam' => $definition->searchParam,
            // Which views may be indexed: their values are links (design §8.5)
            'seo' => $definition->seo ? ['maxDepth' => $definition->seoMaxDepth, 'minResults' => $definition->seoMinResults] : null,
            'sorts' => FacetPlan::sorts($definition),
            // The client transport's results (FacetPlan::results())
            'fields' => Hits::fields($definition),
            'excerptLength' => Hits::excerptLength(),
            'publicMetas' => $definition->publicMetas,
            'facets' => $facets,
        ];
    }
}
