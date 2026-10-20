<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Search;

use Meilisearch\Client;
use Meilisearch\Contracts\FederationOptions;
use Meilisearch\Contracts\MultiSearchFederation;
use Meilisearch\Contracts\SearchQuery;
use Meilisearch\Search\SearchResult;
use Pollora\MeiliScout\Services\IndexSettings;

/**
 * A search with weights: one federated query per weight tier, each limited to
 * the tier's fields and scaled by its weight, merged by Meilisearch into one
 * list (a post found by several queries appears once, with its best score).
 *
 * Only a search by relevance is weighted: with no search term, an explicit sort,
 * facets or a count, the search stays as it is.
 */
final class WeightedSearch
{
    /**
     * @param  array<string, mixed>  $params
     * @param  list<string>  $types  The post types searched
     */
    public static function applies(array $params, array $types): bool
    {
        return SearchWeights::isActive()
            && (string) ($params['q'] ?? '') !== ''
            && empty($params['sort'])
            && empty($params['facets'])
            && ($params['hitsPerPage'] ?? null) !== 0
            && count(self::tiers($types, (string) ($params['index'] ?? ''))) > 1;
    }

    /**
     * Limits an ordinary search to the fields with a weight above 0, so counts agree with the weighted hits.
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>  $types
     * @return array<string, mixed>
     */
    public static function restrict(array $params, string $index, array $types): array
    {
        if (! SearchWeights::isActive() || (string) ($params['q'] ?? '') === '' || isset($params['attributesToSearchOn'])) {
            return $params;
        }

        $fields = self::usable(SearchWeights::enabledFields($types), $index);

        return $fields === [] ? $params : [...$params, 'attributesToSearchOn' => $fields];
    }

    /**
     * The queries of the federated search.
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>  $types
     * @param  array<string, array<string, mixed>>|null  $profiles
     * @return list<array<string, mixed>>
     */
    public static function queries(string $index, array $params, array $types, ?array $profiles = null): array
    {
        $base = array_diff_key($params, array_flip(['limit', 'offset', 'hitsPerPage', 'page', 'q', 'filter', 'attributesToSearchOn', 'sort', 'facets']));
        $queries = [];

        foreach (self::tiers($types, $index, $profiles) as $tier) {
            $fields = isset($params['attributesToSearchOn']) ? array_values(array_intersect($tier['fields'], (array) $params['attributesToSearchOn'])) : $tier['fields'];
            if ($fields === []) {
                continue;
            }

            $filter = (array) ($params['filter'] ?? []);
            if (isset($params['filter']) && is_string($params['filter'])) {
                $filter = [$params['filter']];
            }
            $filter[] = 'post_type IN ['.implode(', ', array_map(static fn (string $type) => '"'.addslashes($type).'"', $tier['types'])).']';

            $queries[] = [...$base, 'indexUid' => $index, 'q' => (string) $params['q'], 'filter' => $filter, 'attributesToSearchOn' => $fields, 'federationOptions' => ['weight' => $tier['weight']]];
        }

        return $queries;
    }

    /**
     * Runs the weighted search and answers like an ordinary one.
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>  $types
     */
    public static function run(Client $client, string $index, array $params, array $types): SearchResult
    {
        $paged = isset($params['hitsPerPage']);
        $perPage = (int) ($paged ? $params['hitsPerPage'] : ($params['limit'] ?? 20));
        $offset = $paged ? ((int) ($params['page'] ?? 1) - 1) * $perPage : (int) ($params['offset'] ?? 0);

        $queries = array_map(static function (array $query): SearchQuery {
            $search = (new SearchQuery)->setIndexUid($query['indexUid'])->setQuery($query['q'])->setFilter($query['filter'])
                ->setAttributesToSearchOn($query['attributesToSearchOn'])
                ->setFederationOptions((new FederationOptions)->setWeight((float) $query['federationOptions']['weight']));
            isset($query['attributesToRetrieve']) && $search->setAttributesToRetrieve((array) $query['attributesToRetrieve']);
            isset($query['attributesToHighlight']) && $search->setAttributesToHighlight((array) $query['attributesToHighlight']);
            isset($query['matchingStrategy']) && $search->setMatchingStrategy((string) $query['matchingStrategy']);
            isset($query['showRankingScore']) && $search->setShowRankingScore((bool) $query['showRankingScore']);

            return $search;
        }, self::queries($index, $params, $types));

        $response = (array) $client->multiSearch($queries, (new MultiSearchFederation)->setLimit($perPage)->setOffset($offset));

        // Hits as an ordinary search gives them: without what federation adds
        $response['hits'] = array_map(static function (array $hit) {
            unset($hit['_federation']);

            return $hit;
        }, (array) ($response['hits'] ?? []));
        $response['query'] = (string) $params['q'];
        $response['facetDistribution'] ??= [];

        if ($paged) {
            $total = (int) ($response['estimatedTotalHits'] ?? count($response['hits']));
            $response = [...$response, 'hitsPerPage' => $perPage, 'page' => (int) ($params['page'] ?? 1), 'totalHits' => $total, 'totalPages' => (int) ceil($total / max(1, $perPage))];
            unset($response['estimatedTotalHits'], $response['offset'], $response['limit']);
        }

        return new SearchResult($response);
    }

    /**
     * The top posts of a type for a search, with weights that may not be saved yet, and why each ranks where it does.
     *
     * @param  array<string, float|int|string>  $weights  field => weight
     * @return list<array{id: int, title: string, url: string, score: float, weight: float, fields: list<string>}>
     */
    public static function preview(Client $client, string $index, string $type, string $q, array $weights, int $limit = 8): array
    {
        $profiles = [$type => $weights];
        $tiers = self::tiers([$type], $index, $profiles);
        if ($tiers === [] || $q === '') {
            return [];
        }

        $params = ['q' => $q, 'filter' => 'post_status = "publish"', 'attributesToRetrieve' => ['ID', 'post_title', 'url'], 'showRankingScore' => true];
        $queries = array_map(static function (array $query): SearchQuery {
            return (new SearchQuery)->setIndexUid($query['indexUid'])->setQuery($query['q'])->setFilter($query['filter'])
                ->setAttributesToSearchOn($query['attributesToSearchOn'])->setAttributesToRetrieve($query['attributesToRetrieve'])
                ->setShowRankingScore(true)
                ->setFederationOptions((new FederationOptions)->setWeight((float) $query['federationOptions']['weight']));
        }, self::queries($index, $params, [$type], $profiles));

        $response = (array) $client->multiSearch($queries, (new MultiSearchFederation)->setLimit($limit));

        return array_map(static function (array $hit) use ($tiers): array {
            $tier = $tiers[(int) ($hit['_federation']['queriesPosition'] ?? 0)] ?? ['weight' => 0.0, 'fields' => []];

            return [
                'id' => (int) $hit['ID'],
                'title' => html_entity_decode(wp_strip_all_tags((string) ($hit['post_title'] ?? '')), ENT_QUOTES),
                'url' => (string) ($hit['url'] ?? ''),
                'score' => round((float) ($hit['_federation']['weightedRankingScore'] ?? $hit['_rankingScore'] ?? 0), 3),
                'weight' => (float) $tier['weight'],
                'fields' => $tier['fields'],
            ];
        }, array_values((array) ($response['hits'] ?? [])));
    }

    /**
     * The tiers, with the fields the index can search (a field enabled but not pushed yet waits for the next indexation).
     *
     * @param  list<string>  $types
     * @param  array<string, array<string, mixed>>|null  $profiles
     * @return list<array{fields: list<string>, weight: float, types: list<string>}>
     */
    public static function tiers(array $types, string $index = '', ?array $profiles = null): array
    {
        $tiers = [];
        foreach (SearchWeights::tiers($types, $profiles) as $tier) {
            $tier['fields'] = self::usable($tier['fields'], $index);
            if ($tier['fields'] !== []) {
                $tiers[] = $tier;
            }
        }

        return $tiers;
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private static function usable(array $fields, string $index): array
    {
        $recorded = $index === '' ? null : IndexSettings::searchable($index);

        return $recorded === null ? $fields : array_values(array_filter($fields, static fn (string $field) => IndexSettings::firstUncovered($recorded, [$field]) === null));
    }
}
