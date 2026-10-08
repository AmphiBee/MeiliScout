<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Query\PhpOrder;
use Pollora\MeiliScout\Query\QueryVars;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\MetaValueFlags;

/**
 * Translates orderby and order into a Meilisearch sort.
 *
 * orderby is read as WP_Query::parse_orderby() reads it: values WordPress
 * does not know are ignored, as it ignores them, and a list left empty is the
 * date. A value WordPress sorts on and the index cannot sort on sends the
 * query to MySQL, rather than having it come back in another order.
 *
 * A search keeps its relevance order unless another order is asked for; it
 * is then followed strictly ('sort' comes first in the ranking rules), and
 * every word must match, as MySQL requires them all.
 */
class OrderBuilder implements QueryBuilderInterface
{
    /**
     * WordPress orderby values, mapped to the document attributes they sort on (from schema 3 for most).
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'date' => 'post_date',
        'post_date' => 'post_date',
        'title' => 'post_title_sort',
        'post_title' => 'post_title_sort',
        'name' => 'post_name',
        'post_name' => 'post_name',
        'author' => 'post_author',
        'post_author' => 'post_author',
        'modified' => 'post_modified',
        'post_modified' => 'post_modified',
        'parent' => 'post_parent',
        'post_parent' => 'post_parent',
        'type' => 'post_type',
        'post_type' => 'post_type',
        'ID' => 'ID',
        'menu_order' => 'menu_order',
        'comment_count' => 'comment_count',
    ];

    /**
     * Orders applied in PHP (PhpOrder) when they are the only one.
     */
    private const PHP_ORDERS = ['rand', 'post__in', 'post_parent__in', 'post_name__in'];

    /**
     * Builds the sort parameter.
     *
     * @param  QueryInterface  $query  The query to read orderby and order from
     * @param  array<string, mixed>  $searchParams  The search parameters to add the sort to
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $search = $query->get('s');
        $isSearch = is_scalar($search) && (string) $search !== '';
        $orderby = $query->get('orderby');

        if ($isSearch && (empty($orderby) || $orderby === 'relevance')) {
            // Relevance, as WordPress orders a search by default
            return;
        }

        // Random, or the order of a list: put in order in PHP
        if (PhpOrder::of($query) !== null) {
            return;
        }

        $sort = $this->sort($query, $orderby);

        if ($sort === []) {
            return;
        }

        $searchParams['sort'] = $sort;

        if ($isSearch) {
            $searchParams['matchingStrategy'] = 'all';
        }
    }

    /**
     * @return list<string>
     */
    private function sort(QueryInterface $query, mixed $orderby): array
    {
        $order = QueryVars::order($query->get('order'));

        if (empty($orderby)) {
            // An empty array or false asks for no ORDER BY; anything else for the date
            return is_array($orderby) || $orderby === false ? [] : ['post_date:'.strtolower($order)];
        }

        if ($orderby === 'none') {
            return [];
        }

        if (is_array($orderby)) {
            $fields = [];

            foreach ($orderby as $field => $direction) {
                $fields[(string) $field] = QueryVars::order($direction);
            }
        } else {
            $fields = array_fill_keys(explode(' ', urldecode((string) $orderby)), $order);
        }

        $sort = [];

        foreach ($fields as $field => $direction) {
            $attribute = $this->attribute($field, $query);

            if ($attribute !== null) {
                $sort[] = $attribute.':'.strtolower($direction);
            }
        }

        // A list WordPress ignored every value of is the date, for a string
        if ($sort === [] && ! is_array($orderby)) {
            return ['post_date:'.strtolower($order)];
        }

        return $sort;
    }

    /**
     * The attribute an orderby value sorts on; null for a value WordPress ignores.
     *
     * @throws UnsupportedQuery When WordPress sorts on it and the index cannot
     */
    private function attribute(string $field, QueryInterface $query): ?string
    {
        if (isset(self::FIELDS[$field])) {
            return $this->v3Attribute(self::FIELDS[$field]);
        }

        if (in_array($field, self::PHP_ORDERS, true) || preg_match('/^RAND\(\d+\)$/i', $field)) {
            // An order on an empty list is ignored by WordPress
            if ($field !== 'rand' && ! preg_match('/^RAND/i', $field) && empty($query->get($field))) {
                return null;
            }

            // In PHP only on its own
            throw new UnsupportedQuery('unsupported_orderby:'.(str_starts_with(strtolower($field), 'rand') ? 'rand' : $field));
        }

        $clauses = self::metaClauses(QueryVars::metaQuery($query));

        if ($clauses === []) {
            return null;
        }

        $primary = reset($clauses);
        $primaryKey = is_string($primary['key'] ?? null) ? $primary['key'] : '';

        $metaKey = match (true) {
            in_array($field, ['meta_value', 'meta_value_num'], true) => $primaryKey,
            $primaryKey !== '' && $field === $primaryKey => $primaryKey,
            isset($clauses[$field]) => $clauses[$field]['key'] ?? null,
            default => false,
        };

        if ($metaKey === false) {
            return null;
        }

        if (! is_string($metaKey) || $metaKey === '' || ! in_array($metaKey, (array) Settings::get('indexed_meta_keys', []), true)) {
            throw new UnsupportedQuery('unsupported_orderby:'.$field);
        }

        if (! MetaValueFlags::sortable($metaKey)) {
            throw new UnsupportedQuery('unsupported_orderby:'.$field);
        }

        return "metas.{$metaKey}";
    }

    /**
     * An attribute to sort on; all but the date need the fields of schema 3.
     */
    private function v3Attribute(string $attribute): string
    {
        if ($attribute === 'post_date') {
            return $attribute;
        }

        if (IndexNames::activeSchema() < 3) {
            // Indexes built before had the raw title sortable, and nothing else
            if ($attribute === 'post_title_sort') {
                return 'post_title';
            }

            throw new UnsupportedQuery('schema_too_old');
        }

        return $attribute;
    }

    /**
     * The first-order clauses of a meta query, by name: WP_Meta_Query::get_clauses().
     *
     * @param  array<int|string, mixed>  $metaQuery
     * @return array<int|string, array<string, mixed>>
     */
    private static function metaClauses(array $metaQuery): array
    {
        $clauses = [];

        foreach ($metaQuery as $name => $entry) {
            if ($name === 'relation' || ! is_array($entry)) {
                continue;
            }

            if (isset($entry['key']) || isset($entry['value'])) {
                $clauses[$name] = $entry;
            } else {
                $clauses += self::metaClauses($entry);
            }
        }

        return $clauses;
    }
}
