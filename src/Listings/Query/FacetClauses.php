<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Query;

use Pollora\MeiliScout\Listings\Definition\FacetDefinition;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Listings\State\UrlCodec;
use Pollora\MeiliScout\Query\Builders\MetaQueryBuilder;
use Pollora\MeiliScout\Query\Builders\TaxQueryBuilder;
use Pollora\MeiliScout\Query\UnsupportedQuery;

/**
 * A facet's filters, written by MeiliScout's builders from WP_Query arguments
 * (one source of truth with WP_Query), then put together the same way here and
 * in the client: the values of an OR facet joined by OR, of an AND facet by
 * AND, a range's bounds by AND.
 *
 * The client gets the clause of every value it may offer and a template of a
 * range's bounds ({n}): it combines known clauses and never writes a filter.
 */
final class FacetClauses
{
    /**
     * Stands for a number in a range's bound templates.
     */
    private const NUMBER = 987654321;

    /**
     * The WP_Query arguments of a facet's selection, empty when it has none.
     *
     * @return array{tax_query?: list<array<string, mixed>>, meta_query?: list<array<string, mixed>>}
     */
    public static function args(FacetDefinition $facet, ListingState $state): array
    {
        if ($facet->type === FacetDefinition::RANGE) {
            $range = $state->rangeOf($facet->key);
            $clauses = [];

            foreach (['min' => '>=', 'max' => '<='] as $bound => $compare) {
                if (isset($range[$bound])) {
                    $clauses[] = ['key' => $facet->name, 'value' => $range[$bound], 'compare' => $compare, 'type' => $facet->numericType()];
                }
            }

            return $clauses === [] ? [] : ['meta_query' => $clauses];
        }

        $values = $state->valuesOf($facet->key);

        if ($values === []) {
            return [];
        }

        if ($facet->type === FacetDefinition::BOOLEAN) {
            return ['meta_query' => [['key' => $facet->name, 'value' => $facet->booleanValue, 'compare' => '=']]];
        }

        if ($facet->isTaxonomy()) {
            $clause = fn (array $terms, string $operator) => ['taxonomy' => $facet->name, 'field' => 'slug', 'terms' => $terms, 'operator' => $operator, 'include_children' => $facet->hierarchical];

            return ['tax_query' => $facet->logic === 'or'
                ? [$clause($values, 'IN')]
                : array_map(fn (string $value) => $clause([$value], 'IN'), $values)];
        }

        return ['meta_query' => $facet->logic === 'or'
            ? [['key' => $facet->name, 'value' => $values, 'compare' => 'IN']]
            : array_map(fn (string $value) => ['key' => $facet->name, 'value' => $value, 'compare' => '='], $values)];
    }

    /**
     * The filter of one value of a list or boolean facet: a term slug, a meta value, '1'.
     *
     * @throws UnsupportedQuery When the builders cannot translate it
     */
    public static function value(FacetDefinition $facet, string $value): string
    {
        if ($facet->isTaxonomy()) {
            return self::build(new TaxQueryBuilder, ['tax_query' => [
                ['taxonomy' => $facet->name, 'field' => 'slug', 'terms' => [$value], 'operator' => 'IN', 'include_children' => $facet->hierarchical],
            ]]);
        }

        $value = $facet->type === FacetDefinition::BOOLEAN ? $facet->booleanValue : $value;

        return self::build(new MetaQueryBuilder, ['meta_query' => [['key' => $facet->name, 'value' => $value, 'compare' => '=']]]);
    }

    /**
     * A range's bound filters, {n} standing for the number.
     *
     * @return array{min: string, max: string}
     *
     * @throws UnsupportedQuery
     */
    public static function bounds(FacetDefinition $facet): array
    {
        $template = fn (string $compare) => str_replace((string) self::NUMBER, '{n}', self::build(new MetaQueryBuilder, ['meta_query' => [
            ['key' => $facet->name, 'value' => self::NUMBER, 'compare' => $compare, 'type' => $facet->numericType()],
        ]]));

        return ['min' => $template('>='), 'max' => $template('<=')];
    }

    /**
     * A facet's filter in a state, null when it has no selection.
     *
     * @param  array<string, string>|null  $known  Clauses already written, by value
     *
     * @throws UnsupportedQuery
     */
    public static function clause(FacetDefinition $facet, ListingState $state, ?array $known = null): ?string
    {
        if ($facet->type === FacetDefinition::RANGE) {
            $range = $state->rangeOf($facet->key);
            if ($range === []) {
                return null;
            }

            $bounds = self::bounds($facet);
            $parts = [];
            foreach (['min', 'max'] as $bound) {
                if (isset($range[$bound])) {
                    $parts[] = str_replace('{n}', UrlCodec::number($facet, $range[$bound]), $bounds[$bound]);
                }
            }

            return count($parts) === 1 ? $parts[0] : '('.implode(' AND ', $parts).')';
        }

        $values = $state->valuesOf($facet->key);
        if ($values === []) {
            return null;
        }

        $clauses = array_map(fn (string $value) => $known[$value] ?? self::value($facet, $value), $values);

        return self::compose($facet, $clauses);
    }

    /**
     * @param  non-empty-list<string>  $clauses
     */
    public static function compose(FacetDefinition $facet, array $clauses): string
    {
        if (count($clauses) === 1) {
            return $clauses[0];
        }

        return $facet->logic === 'or' && $facet->type === FacetDefinition::LIST
            ? '('.implode(' OR ', $clauses).')'
            : '('.implode(' AND ', $clauses).')';
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private static function build(TaxQueryBuilder|MetaQueryBuilder $builder, array $vars): string
    {
        $params = [];
        $builder->build(new ArrayQuery($vars), $params);

        return (string) ($params['filter'][0] ?? '');
    }
}
