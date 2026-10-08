<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Terms;

use Pollora\MeiliScout\Contracts\QueryInterface;
use WP_Meta_Query;
use WP_Term_Query;

/**
 * A WP_Term_Query, as the query builders read queries.
 *
 * The query vars are the ones WordPress parsed by terms_pre_query (slugs
 * sanitized, get_terms_args applied). The meta query is the one WordPress
 * parsed, meta_key and meta_value included: the builders get it whole, as
 * meta_query, and no shortcut beside it.
 */
final class TermQueryAdapter implements QueryInterface
{
    private const META_SHORTCUTS = ['meta_key', 'meta_value', 'meta_compare', 'meta_type', 'meta_compare_key', 'meta_type_key'];

    public function __construct(private readonly WP_Term_Query $query) {}

    public function get(string $key, $default = null)
    {
        if ($key === 'meta_query') {
            // @phpstan-ignore instanceof.alwaysTrue
            return $this->query->meta_query instanceof WP_Meta_Query ? $this->query->meta_query->queries : [];
        }

        if (in_array($key, self::META_SHORTCUTS, true)) {
            return $default;
        }

        return $this->query->query_vars[$key] ?? $default;
    }

    public function set($key, $value)
    {
        $this->query->query_vars[$key] = $value;

        return $value;
    }

    public function termQuery(): WP_Term_Query
    {
        return $this->query;
    }
}
