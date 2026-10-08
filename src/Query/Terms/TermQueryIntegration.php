<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Terms;

use Meilisearch\Client;
use Pollora\MeiliScout\Query\QueryLog;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\SearchFallbacks;
use WP_Term_Query;

/**
 * Serves from Meilisearch the get_terms() calls that ask for it (use_meilisearch), or that the settings cover.
 *
 * Translate or fall back, as for WP_Query: a term query is answered by
 * Meilisearch only when every argument is translated faithfully; otherwise
 * MySQL runs it, and the reason is recorded. What happened is on the query,
 * in $query->meiliscout.
 */
final class TermQueryIntegration
{
    /**
     * Fallback reasons are counted with this prefix, apart from the posts'.
     */
    public const REASON_PREFIX = 'terms:';

    private ?Client $client;

    public function __construct(private readonly TermQueryBuilder $builder = new TermQueryBuilder)
    {
        $this->client = ClientFactory::getReadClient();

        // Plugins restricting the terms in SQL: their queries run on MySQL
        TermSqlFilters::watch();
        add_filter('terms_pre_query', [$this, 'interceptQuery'], PHP_INT_MAX, 2);
    }

    /**
     * Answers a term query from Meilisearch, or leaves it to MySQL.
     *
     * @param  array<int|string, mixed>|null  $terms  Null unless another plugin answered
     * @return array<int|string, mixed>|string|int|null The terms as get_terms() returns them, or null to let WordPress run the query
     */
    public function interceptQuery($terms, WP_Term_Query $query): array|string|int|null
    {
        // Closed for every query, served or not, for the frames to stay in step
        $changedSqlFilters = TermSqlFilters::close($query);

        // A WP_Term_Query object can run several queries
        unset($query->meiliscout);

        if ($terms !== null || ! TermAutoIntegration::wants($query)) {
            return $terms;
        }

        $started = microtime(true);
        $index = IndexNames::active('taxonomies');
        $query->meiliscout = ['served' => false, 'reason' => null, 'params' => null, 'index' => $index, 'time' => null, 'found' => null];

        if ($this->client === null) {
            return $this->fallBack($query, SearchFallbacks::UNREACHABLE);
        }

        try {
            $reason = TermQuerySupport::check($query, $changedSqlFilters);

            if ($reason !== null) {
                throw new UnsupportedQuery($reason);
            }

            $plan = $this->builder->build($query, $this->objectTerms($query));
        } catch (UnsupportedQuery $e) {
            return $this->fallBack($query, $e->reason);
        } catch (\Throwable $e) {
            error_log('MeiliScout: could not translate the term query, falling back to MySQL: '.$e->getMessage());

            return $this->fallBack($query, 'build_error');
        }

        $query->meiliscout['params'] = $plan->params();

        try {
            $result = (new TermResults($this->client, $index))->get($plan);
        } catch (UnsupportedQuery $e) {
            return $this->fallBack($query, $e->reason);
        } catch (\Throwable $e) {
            error_log('MeiliScout: term search failed, falling back to MySQL: '.$e->getMessage());

            return $this->fallBack($query, 'engine_error');
        }

        return $this->served($query, $result, $started);
    }

    /**
     * The terms of the posts object_ids names, from the posts index; null without object_ids.
     *
     * @return array<int, list<int>>|null
     *
     * @throws UnsupportedQuery
     */
    private function objectTerms(WP_Term_Query $query): ?array
    {
        $objectIds = $query->query_vars['object_ids'] ?? [];

        if (empty($objectIds) || $this->client === null) {
            return null;
        }

        $taxonomies = array_values(array_map('strval', (array) ($query->query_vars['taxonomy'] ?? [])));

        return (new ObjectTerms($this->client))->of(array_map('intval', (array) $objectIds), $taxonomies);
    }

    /**
     * @param  array<int|string, mixed>|string|int|null  $result
     * @return array<int|string, mixed>|string|int|null
     */
    private function served(WP_Term_Query $query, array|string|int|null $result, float $started): array|string|int|null
    {
        $query->meiliscout['served'] = true;
        $query->meiliscout['found'] = is_array($result) ? count($result) : (int) $result;
        $query->meiliscout['time'] = round((microtime(true) - $started) * 1000, 2);
        QueryLog::record($query);

        return $result;
    }

    private function fallBack(WP_Term_Query $query, string $reason): null
    {
        $query->meiliscout['reason'] = $reason;
        SearchFallbacks::record(self::REASON_PREFIX.$reason);
        QueryLog::record($query);

        return null;
    }
}
