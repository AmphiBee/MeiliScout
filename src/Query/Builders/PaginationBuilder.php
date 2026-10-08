<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Query\QueryVars;
use Pollora\MeiliScout\Services\IndexSettings;

/**
 * Translates posts_per_page, paged, offset and nopaging.
 *
 * Meilisearch counts the results exactly only when paging with page and
 * hitsPerPage; limit and offset give an estimate. Pages are asked for by
 * number, then, and limit/offset kept for an explicit offset (the total is
 * then counted apart) and for queries that want no total (no_found_rows).
 * A query without LIMIT in WordPress (nopaging, -1, a single post) gets up to
 * the maximum number of results the index allows.
 */
class PaginationBuilder implements QueryBuilderInterface
{
    /**
     * @param  array<string, mixed>  $searchParams
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        if (QueryVars::isUnpaged($query)) {
            $searchParams['hitsPerPage'] = IndexSettings::maxTotalHits();
            $searchParams['page'] = 1;

            return;
        }

        $postsPerPage = self::postsPerPage($query);
        $offset = $query->get('offset');

        // As in WordPress, an offset replaces the page
        if (is_numeric($offset)) {
            $searchParams['limit'] = $postsPerPage;
            $searchParams['offset'] = abs((int) $offset);

            return;
        }

        $page = max(1, abs((int) $query->get('paged', 1)));

        if (! empty($query->get('no_found_rows'))) {
            $searchParams['limit'] = $postsPerPage;
            $searchParams['offset'] = ($page - 1) * $postsPerPage;

            return;
        }

        $searchParams['hitsPerPage'] = $postsPerPage;
        $searchParams['page'] = $page;
    }

    /**
     * posts_per_page, as WP_Query::get_posts() normalizes it.
     */
    public static function postsPerPage(QueryInterface $query): int
    {
        $postsPerPage = $query->get('posts_per_page');

        if (empty($postsPerPage)) {
            $postsPerPage = get_option('posts_per_page');
        }

        $postsPerPage = (int) $postsPerPage;

        return $postsPerPage < -1 ? abs($postsPerPage) : max(1, $postsPerPage);
    }
}
