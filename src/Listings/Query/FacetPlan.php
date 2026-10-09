<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Query;

use Pollora\MeiliScout\Listings\Definition\FacetDefinition;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Query\Builders\OrderBuilder;
use Pollora\MeiliScout\Query\MeiliQueryBuilder;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * The searches that count a listing's facets: one multi-search, disjunctive.
 *
 * - The first counts every facet whose counts follow the whole selection: the
 *   facets without a selection, the AND and boolean ones, and the bounds of
 *   the ranges without one.
 * - Then one search per OR facet and per range with a selection: every filter
 *   but its own, so that its other values keep their counts and its bounds
 *   do not shrink to the selection.
 *
 * The server sends it with the listing's base filter; the client sends the
 * same searches without it, the tenant token adding it (tests compare both).
 */
final class FacetPlan
{
    /**
     * The WP_Query arguments every post of the listing matches.
     *
     * @return array<string, mixed>
     */
    public static function baseArgs(ListingDefinition $definition): array
    {
        return [
            'post_type' => $definition->postTypes,
            'post_status' => 'publish',
            'has_password' => false,
            'tax_query' => $definition->base['tax_query'] ?? [],
            'meta_query' => $definition->base['meta_query'] ?? [],
            'posts_per_page' => 1,
        ];
    }

    /**
     * The base filter, as the builders write it (what the tenant token enforces).
     */
    public static function baseFilter(ListingDefinition $definition): string
    {
        return (string) ((new MeiliQueryBuilder)->build(new ArrayQuery(self::baseArgs($definition)))['filter'] ?? '');
    }

    /**
     * Each sort's Meilisearch sort, by key.
     *
     * @return array<string, list<string>>
     */
    public static function sorts(ListingDefinition $definition): array
    {
        $sorts = [];

        foreach ($definition->sorts as $key => $sort) {
            $params = [];
            (new OrderBuilder)->build(new ArrayQuery(self::baseArgs($definition) + array_diff_key($sort, ['label' => true])), $params);
            $sorts[$key] = array_values((array) ($params['sort'] ?? []));
        }

        return $sorts;
    }

    /**
     * The searches counting the facets in a state.
     *
     * @param  string|null  $base  The base filter, null when a tenant token adds it
     * @param  array<string, array<string, string>>  $known  Clauses already written, by facet and value
     * @return list<array<string, mixed>>
     */
    public static function counts(ListingDefinition $definition, ListingState $state, ?string $base, array $known = []): array
    {
        $index = IndexNames::active('posts');
        $clauses = self::clauses($definition, $state, $known);
        $first = ['indexUid' => $index, 'facets' => [], 'limit' => 0];
        $searches = [];

        foreach ($definition->facets as $facet) {
            $active = isset($clauses[$facet->key]);

            if (! $active || ! $facet->isDisjunctive()) {
                $first['facets'][] = $facet->countField();

                continue;
            }

            $searches[] = self::search($index, $base, $clauses, $facet->key, $state) + ['facets' => [$facet->countField()], 'limit' => 0];
        }

        $first = self::search($index, $base, $clauses, null, $state) + $first;

        return [$first, ...$searches];
    }

    /**
     * The search of a page of results, for a client that renders them (transport C).
     *
     * @param  list<string>  $attributes
     * @return array<string, mixed>
     */
    public static function results(ListingDefinition $definition, ListingState $state, ?string $base, array $attributes, array $known = []): array
    {
        $sort = $state->sort !== '' ? $state->sort : $definition->defaultSort;

        return self::search(IndexNames::active('posts'), $base, self::clauses($definition, $state, $known), null, $state) + [
            'sort' => self::sorts($definition)[$sort] ?? [],
            'limit' => $definition->perPage,
            'offset' => ($state->page - 1) * $definition->perPage,
            'attributesToRetrieve' => $attributes,
        ];
    }

    /**
     * The search every value a facet may offer comes from: the base alone.
     *
     * @return array<string, mixed>
     */
    public static function universe(ListingDefinition $definition, string $base): array
    {
        return [
            'indexUid' => IndexNames::active('posts'),
            'filter' => $base,
            'facets' => array_map(fn (FacetDefinition $facet) => $facet->countField(), $definition->facets),
            'limit' => 0,
        ];
    }

    /**
     * @param  array<string, array<string, string>>  $known
     * @return array<string, string> Each active facet's filter, in the definition's order
     */
    private static function clauses(ListingDefinition $definition, ListingState $state, array $known): array
    {
        $clauses = [];

        foreach ($definition->facets as $facet) {
            $clause = FacetClauses::clause($facet, $state, $known[$facet->key] ?? null);

            if ($clause !== null) {
                $clauses[$facet->key] = $clause;
            }
        }

        return $clauses;
    }

    /**
     * @param  array<string, string>  $clauses
     * @return array<string, mixed>
     */
    private static function search(string $index, ?string $base, array $clauses, ?string $without, ListingState $state): array
    {
        $filters = $base !== null && $base !== '' ? ['('.$base.')'] : [];

        foreach ($clauses as $key => $clause) {
            if ($key !== $without) {
                $filters[] = $clause;
            }
        }

        $search = ['indexUid' => $index];

        if ($state->search !== '') {
            $search['q'] = $state->search;
        }

        if ($filters !== []) {
            $search['filter'] = implode(' AND ', $filters);
        }

        return $search;
    }
}
