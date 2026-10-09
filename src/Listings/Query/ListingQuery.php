<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Query;

use Meilisearch\Contracts\SearchQuery;
use Pollora\MeiliScout\Listings\Definition\FacetDefinition;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Render\Hits;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * Runs a listing in a state.
 *
 * Its posts come from a WP_Query asking for Meilisearch: translated or run on
 * MySQL like any other, posts loaded from the database, found_posts and the
 * pagination as WordPress sets them. Its facets are counted by one
 * multi-search (FacetPlan), with the search every value comes from: what the
 * client needs to count them again without the server.
 *
 * A listing in the client transport (C) takes its cards from the same
 * multi-search, as its client will (Hits): no WP_Query. When its facets
 * cannot be counted, it is served as the others.
 */
final class ListingQuery
{
    public static function run(ListingDefinition $definition, ListingState $state): ListingResult
    {
        $client = $definition->transport === 'client';

        [$facets, $template, $error, $results] = self::facets($definition, $state, $client);

        if ($client && $template !== null && $results !== null) {
            $date = Hits::dateNames();
            $hits = array_map(fn (array $hit) => Hits::fromDocument($hit, $definition->publicMetas, $date), (array) ($results['hits'] ?? []));

            return new ListingResult($definition, $state, null, $facets, $template, null, $hits, (int) ($results['estimatedTotalHits'] ?? $results['totalHits'] ?? 0));
        }

        $query = new \WP_Query(self::wpQueryArgs($definition, $state));

        return new ListingResult($definition, $state, $query, $facets, $template, $error);
    }

    /**
     * The WP_Query of a state's posts.
     *
     * @return array<string, mixed>
     */
    public static function wpQueryArgs(ListingDefinition $definition, ListingState $state): array
    {
        $args = FacetPlan::baseArgs($definition);
        $groups = ['tax_query' => [], 'meta_query' => []];

        foreach ($groups as $clause => $unused) {
            if ($args[$clause] !== []) {
                $groups[$clause][] = $args[$clause];
            }
        }

        foreach ($definition->facets as $facet) {
            foreach (FacetClauses::args($facet, $state) as $clause => $entries) {
                array_push($groups[$clause], ...$entries);
            }
        }

        $sort = $definition->sorts[$state->sort !== '' ? $state->sort : $definition->defaultSort];

        return array_filter([
            'post_type' => $definition->postTypes,
            'post_status' => 'publish',
            'has_password' => false,
            'tax_query' => $groups['tax_query'] === [] ? null : ['relation' => 'AND', ...$groups['tax_query']],
            'meta_query' => $groups['meta_query'] === [] ? null : ['relation' => 'AND', ...$groups['meta_query']],
            's' => $state->search !== '' ? $state->search : null,
            'orderby' => $sort['orderby'],
            'order' => $sort['order'],
            'meta_key' => $sort['meta_key'] ?? null,
            'posts_per_page' => $definition->perPage,
            'paged' => $state->page,
            'ignore_sticky_posts' => true,
            'use_meilisearch' => true,
        ], fn ($value) => $value !== null);
    }

    /**
     * The facets' values with their counts, the client's template, and a page
     * of results when asked (the client transport).
     *
     * @return array{0: array<string, array{options: list<array{value: string, label: string, count: int, selected: bool, depth: int}>, stats: array{min: float, max: float}|null}>, 1: array<string, mixed>|null, 2: string|null, 3: array<string, mixed>|null}
     */
    private static function facets(ListingDefinition $definition, ListingState $state, bool $withResults = false): array
    {
        $empty = array_fill_keys(array_map(fn (FacetDefinition $facet) => $facet->key, $definition->facets), ['options' => [], 'stats' => null]);
        $client = ClientFactory::getReadClient();

        if ($client === null) {
            return [$empty, null, 'unreachable', null];
        }

        // Counts per term with its descendants need the tree (posts schema 5)
        if (IndexNames::activeSchema() < 5) {
            return [$empty, null, 'schema_too_old', null];
        }

        try {
            $base = FacetPlan::baseFilter($definition);
            $searches = [
                ...FacetPlan::counts($definition, $state, $base),
                ...($withResults ? [FacetPlan::results($definition, $state, $base)] : []),
                FacetPlan::universe($definition, $base),
            ];
            $results = $client->multiSearch(array_map([self::class, 'searchQuery'], $searches))['results'] ?? [];
        } catch (UnsupportedQuery $e) {
            return [$empty, null, $e->reason, null];
        } catch (\Throwable $e) {
            error_log('MeiliScout: could not count the facets of a listing: '.$e->getMessage());

            return [$empty, null, 'engine_error', null];
        }

        $universe = array_pop($results) ?? [];
        array_pop($searches);
        $page = null;

        if ($withResults) {
            $page = array_pop($results) ?? [];
            array_pop($searches);
        }

        // Each field's counts, from the search that carries it; a second one: what its values add
        $distributions = [];
        $added = [];
        $stats = [];
        foreach ($searches as $i => $search) {
            foreach ($search['facets'] as $field) {
                if (isset($distributions[$field])) {
                    $added[$field] = $results[$i]['facetDistribution'][$field] ?? [];

                    continue;
                }
                $distributions[$field] = $results[$i]['facetDistribution'][$field] ?? [];
                $stats[$field] = $results[$i]['facetStats'][$field] ?? null;
            }
        }

        $facets = [];
        $template = [];

        foreach ($definition->facets as $facet) {
            $field = $facet->countField();
            $values = self::values($facet, array_keys($universe['facetDistribution'][$field] ?? []), $state);

            if ($facet->type === FacetDefinition::RANGE) {
                $facets[$facet->key] = ['options' => [], 'stats' => isset($stats[$field]) ? ['min' => (float) $stats[$field]['min'], 'max' => (float) $stats[$field]['max']] : null];
            } else {
                $facets[$facet->key] = ['options' => self::withOverflow(array_map(fn (array $value) => self::option(
                    $value,
                    (int) ($distributions[$field][$value['id']] ?? 0),
                    in_array($value['value'], $state->valuesOf($facet->key), true),
                    isset($added[$field]) ? (int) ($added[$field][$value['id']] ?? 0) : null,
                ), $values), $facet->limit), 'stats' => null];
            }

            $template[$facet->key] = $values;
        }

        return [$facets, $definition->transport === 'page' ? null : PlanTemplate::build($definition, $template, $universe), null, $page];
    }

    /**
     * A facet's value as the store shows it. In an OR list with a selection,
     * the count of a value not selected is what it adds: +3 (the client
     * writes the same, resources/listings/view.js).
     *
     * @param  array{value: string, label: string, depth: int}  $value
     * @param  int|null  $added  What it adds to its facet's selection, null without one
     * @return array{value: string, label: string, count: int, selected: bool, depth: int, added: int|null, countLabel: string}
     */
    public static function option(array $value, int $count, bool $selected, ?int $added): array
    {
        $added = $selected ? null : $added;

        return [
            'value' => $value['value'],
            'label' => $value['label'],
            'count' => $count,
            'selected' => $selected,
            'depth' => $value['depth'],
            'added' => $added,
            'countLabel' => $added === null ? (string) $count : '+'.$added,
        ];
    }

    /**
     * Marks the values shown past a facet's limit, which the client folds
     * (resources/listings/view.js withOverflow()). A selected value never is.
     *
     * @param  list<array{value: string, label: string, count: int, selected: bool, depth: int}>  $options
     * @return list<array{value: string, label: string, count: int, selected: bool, depth: int, overflow: bool}>
     */
    public static function withOverflow(array $options, int $limit): array
    {
        $shown = 0;

        foreach ($options as $i => $option) {
            $visible = $option['count'] > 0 || $option['selected'];
            $options[$i]['overflow'] = $limit > 0 && $visible && ! $option['selected'] && $shown >= $limit;
            $shown += $visible ? 1 : 0;
        }

        return $options;
    }

    /**
     * Every value a facet may offer, in display order, with what identifies it
     * in the index (a term id, a meta value) and its label.
     *
     * @param  list<int|string>  $ids  The values of the universe search
     * @return list<array{value: string, id: string, label: string, depth: int, parent: string}>
     */
    private static function values(FacetDefinition $facet, array $ids, ListingState $state): array
    {
        if ($facet->type === FacetDefinition::RANGE) {
            return [];
        }

        if ($facet->type === FacetDefinition::BOOLEAN) {
            return [['value' => '1', 'id' => $facet->booleanValue, 'label' => $facet->label, 'depth' => 0, 'parent' => '']];
        }

        if (! $facet->isTaxonomy()) {
            $values = array_map('strval', $ids);
            // A value in the URL no post has any more still shows, to be removed
            $values = array_values(array_unique([...$values, ...$state->valuesOf($facet->key)]));
            $values = array_map(fn (string $value) => ['value' => $value, 'id' => $value, 'label' => $facet->labels[$value] ?? $value, 'depth' => 0, 'parent' => ''], $values);
            usort($values, fn (array $a, array $b) => self::compare($a['label'], $b['label']));

            return $values;
        }

        $terms = $ids === [] ? [] : get_terms(['taxonomy' => $facet->name, 'include' => array_map('intval', $ids), 'hide_empty' => false, 'use_meilisearch' => false]);
        $terms = is_array($terms) ? $terms : [];
        $selected = array_diff($state->valuesOf($facet->key), array_map(fn (\WP_Term $term) => $term->slug, $terms));

        foreach ($selected as $slug) {
            $term = get_term_by('slug', $slug, $facet->name);
            if ($term instanceof \WP_Term) {
                $terms[] = $term;
            }
        }

        return self::ordered($facet, $terms);
    }

    /**
     * Terms by name; in a tree, each followed by its children.
     *
     * @param  list<\WP_Term>  $terms
     * @return list<array{value: string, id: string, label: string, depth: int, parent: string}>
     */
    private static function ordered(FacetDefinition $facet, array $terms): array
    {
        usort($terms, fn (\WP_Term $a, \WP_Term $b) => self::compare($a->name, $b->name));
        $entry = fn (\WP_Term $term, int $depth, string $parent) => [
            'value' => $term->slug,
            'id' => (string) $term->term_id,
            'label' => html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'depth' => $depth,
            'parent' => $parent,
        ];

        if (! $facet->hierarchical) {
            return array_map(fn (\WP_Term $term) => $entry($term, 0, ''), $terms);
        }

        $byParent = [];
        $ids = array_map(fn (\WP_Term $term) => $term->term_id, $terms);
        foreach ($terms as $term) {
            // A term whose parent is not offered hangs at the top
            $byParent[in_array($term->parent, $ids, true) ? $term->parent : 0][] = $term;
        }

        $ordered = [];
        $walk = function (int $parent, int $depth, string $parentSlug) use (&$walk, &$ordered, $byParent, $entry): void {
            foreach ($byParent[$parent] ?? [] as $term) {
                $ordered[] = $entry($term, $depth, $parentSlug);
                $walk($term->term_id, $depth + 1, $term->slug);
            }
        };
        $walk(0, 0, '');

        return $ordered;
    }

    /**
     * Labels in reading order: É with E, 10 after 9.
     */
    private static function compare(string $a, string $b): int
    {
        return strnatcasecmp(remove_accents($a), remove_accents($b)) ?: strcmp($a, $b);
    }

    /**
     * @param  array<string, mixed>  $search
     */
    public static function searchQuery(array $search): SearchQuery
    {
        $query = (new SearchQuery)->setIndexUid((string) $search['indexUid']);

        if (isset($search['q'])) {
            $query->setQuery((string) $search['q']);
        }
        if (isset($search['filter'])) {
            $query->setFilter([(string) $search['filter']]);
        }
        if (isset($search['facets'])) {
            $query->setFacets($search['facets']);
        }
        if (array_key_exists('limit', $search)) {
            $query->setLimit((int) $search['limit']);
        }
        if (isset($search['offset'])) {
            $query->setOffset((int) $search['offset']);
        }
        if (isset($search['sort'])) {
            $query->setSort($search['sort']);
        }
        if (isset($search['attributesToRetrieve'])) {
            $query->setAttributesToRetrieve($search['attributesToRetrieve']);
        }
        if (isset($search['attributesToCrop'])) {
            $query->setAttributesToCrop($search['attributesToCrop']);
        }
        if (isset($search['cropLength'])) {
            $query->setCropLength((int) $search['cropLength']);
        }

        return $query;
    }
}
