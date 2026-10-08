<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Pollora\MeiliScout\Config\Config;
use WP_Query;
use WP_Term_Query;

/**
 * The queries of this request that asked for Meilisearch, posts and terms, for debugging tools (Query Monitor).
 */
final class QueryLog
{
    /**
     * Queries kept at most.
     */
    private const LIMIT = 200;

    /**
     * @var list<array{info: array<string, mixed>, query: WP_Query|WP_Term_Query}>
     */
    private static array $entries = [];

    /**
     * Records what MeiliScout did with a query: called once it served it, or left it to MySQL.
     */
    public static function record(WP_Query|WP_Term_Query $query): void
    {
        if (count(self::$entries) < self::LIMIT && is_array($query->meiliscout ?? null)) {
            self::$entries[] = ['info' => $query->meiliscout, 'query' => $query];
        }
    }

    /**
     * The queries recorded, with their total once WordPress counted it.
     *
     * @return list<array{kind: string, served: bool, reason: string|null, params: array<string, mixed>|null, index: string|null, time: float|null, main: bool, found_posts: int}>
     */
    public static function entries(): array
    {
        return array_map(static fn (array $entry) => [
            'kind' => $entry['query'] instanceof WP_Term_Query ? 'terms' : 'posts',
            'served' => ! empty($entry['info']['served']),
            'reason' => $entry['info']['reason'] ?? null,
            'params' => $entry['info']['params'] ?? null,
            'index' => $entry['info']['index'] ?? null,
            'time' => $entry['info']['time'] ?? null,
            'main' => $entry['query'] instanceof WP_Query && $entry['query']->is_main_query(),
            // The posts found, or the terms returned
            'found_posts' => $entry['query'] instanceof WP_Query ? (int) $entry['query']->found_posts : (int) ($entry['info']['found'] ?? 0),
        ], self::$entries);
    }

    /**
     * The search as a curl command, the key left for the reader to fill in.
     *
     * @param  array<string, mixed>  $params
     */
    public static function curl(string $index, array $params): string
    {
        $host = rtrim((string) Config::get('meili_host', ''), '/');
        $body = (string) wp_json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return sprintf(
            "curl -X POST '%s/indexes/%s/search' -H 'Authorization: Bearer \$MEILI_SEARCH_KEY' -H 'Content-Type: application/json' --data '%s'",
            $host,
            rawurlencode($index),
            str_replace("'", "'\\''", $body)
        );
    }

    /**
     * Forgets the queries recorded. For tests.
     */
    public static function reset(): void
    {
        self::$entries = [];
    }
}
