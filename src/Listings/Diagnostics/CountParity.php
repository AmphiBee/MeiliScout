<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Diagnostics;

use Pollora\MeiliScout\Listings\Definition\FacetDefinition;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\Query\ListingQuery;
use Pollora\MeiliScout\Listings\State\ListingState;

/**
 * What a listing shows against MySQL (design §14, prototype P6): its total,
 * each value's count, and what a value adds to an OR facet's selection, each
 * counted again by a WP_Query on MySQL with the same arguments.
 *
 * A facet's count is the total of the state with this value: the facet's own
 * selection replaced by it (OR), or added to it (AND).
 */
final class CountParity
{
    /**
     * Values compared per facet, the first ones in display order.
     */
    public const VALUES = 30;

    /**
     * @return array{language: string, served: bool, reason: string|null, error: string|null, total: array{shown: int, mysql: int}, rows: list<array{facet: string, value: string, label: string, shown: string, mysql: string, ok: bool}>, diffs: int}
     */
    public static function compare(ListingDefinition $definition, string $language = '', ?ListingState $state = null): array
    {
        $state ??= new ListingState;

        return Languages::adapter()->in($language, fn (): array => self::compareIn($definition, $state, $language));
    }

    /**
     * @return array{language: string, served: bool, reason: string|null, error: string|null, total: array{shown: int, mysql: int}, rows: list<array{facet: string, value: string, label: string, shown: string, mysql: string, ok: bool}>, diffs: int}
     */
    private static function compareIn(ListingDefinition $definition, ListingState $state, string $language): array
    {
        $result = ListingQuery::run($definition, $state);
        $mysql = fn (array $values) => self::mysqlTotal($definition, new ListingState($values, $state->ranges, $state->sort, 1, $state->search));
        $rows = [];

        foreach ($definition->facets as $facet) {
            if ($facet->type === FacetDefinition::RANGE) {
                continue;
            }

            $options = array_slice($result->facets[$facet->key]['options'] ?? [], 0, self::VALUES);
            $selection = $state->valuesOf($facet->key);
            $before = $selection !== [] && $facet->logic === 'or' ? $mysql($state->values) : null;

            /** @var list<array{value: string, label: string, count: int, selected: bool, countLabel: string}> $options ListingQuery::option() */
            foreach ($options as $option) {
                if ($option['count'] === 0 && ! $option['selected']) {
                    continue;
                }

                $values = $state->values;

                // A value added to an OR facet's selection: what it adds
                if ($before !== null && ! $option['selected']) {
                    $values[$facet->key] = [...$selection, $option['value']];
                    $expected = '+'.($mysql($values) - $before);
                } else {
                    $values[$facet->key] = $facet->logic === 'or' ? [$option['value']] : array_values(array_unique([...$selection, $option['value']]));
                    $expected = (string) $mysql($values);
                }

                $rows[] = [
                    'facet' => $facet->key,
                    'value' => $option['value'],
                    'label' => $option['label'],
                    'shown' => $option['countLabel'],
                    'mysql' => $expected,
                    'ok' => $option['countLabel'] === $expected,
                ];
            }
        }

        $total = ['shown' => $result->total(), 'mysql' => $mysql($state->values)];
        $info = $result->query !== null ? ($result->query->meiliscout ?? null) : null;

        return [
            'language' => $language,
            // The posts' query: a fallback compares MySQL with itself
            'served' => $result->query === null || (bool) ($info['served'] ?? false),
            'reason' => is_array($info) ? ($info['reason'] ?? null) : null,
            'error' => $result->countsError,
            'total' => $total,
            'rows' => $rows,
            'diffs' => count(array_filter($rows, fn (array $row) => ! $row['ok'])) + ($total['shown'] !== $total['mysql'] ? 1 : 0),
        ];
    }

    /**
     * The state's total on MySQL.
     */
    private static function mysqlTotal(ListingDefinition $definition, ListingState $state): int
    {
        $query = new \WP_Query(array_merge(ListingQuery::wpQueryArgs($definition, $state), [
            'use_meilisearch' => false,
            'fields' => 'ids',
            'posts_per_page' => 1,
            'paged' => 1,
            'no_found_rows' => false,
        ]));

        return (int) $query->found_posts;
    }
}
