<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\QueryInterface;

/**
 * Translates the WordPress orderby/order query vars into a Meilisearch sort.
 *
 * Only attributes declared sortable on the posts index can be sorted on
 * (post_title, post_date and the indexed meta keys): any other field is left
 * out, since Meilisearch rejects a sort on a non-sortable attribute.
 */
class OrderBuilder implements QueryBuilderInterface
{
    /**
     * WordPress orderby values mapped to document attributes.
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'date' => 'post_date',
        'post_date' => 'post_date',
        'title' => 'post_title',
        'post_title' => 'post_title',
    ];

    /**
     * Builds the sort parameter.
     *
     * @param  QueryInterface  $query  The query to read orderby and order from
     * @param  array  $searchParams  The search parameters to add the sort to
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $orderby = $query->get('orderby');

        if (empty($orderby)) {
            // As in WordPress: a search is ordered by relevance, anything else by date
            if (! empty($query->get('s'))) {
                return;
            }

            $orderby = 'date';
        }

        $fields = is_array($orderby)
            ? $orderby
            : array_fill_keys(preg_split('/[\s,]+/', trim((string) $orderby)) ?: [], $query->get('order'));

        $sort = [];

        foreach ($fields as $field => $direction) {
            $attribute = $this->sortableAttribute((string) $field, $query);

            if ($attribute !== null) {
                $sort[] = $attribute.':'.(strtoupper((string) $direction) === 'ASC' ? 'asc' : 'desc');
            }
        }

        if (! empty($sort)) {
            $searchParams['sort'] = $sort;
        }
    }

    /**
     * The sortable attribute an orderby field stands for, or null when it has none.
     *
     * @param  string  $field  An orderby value: a field, meta_value(_num), or a named meta_query clause
     * @param  QueryInterface  $query  The query, to resolve meta keys
     */
    private function sortableAttribute(string $field, QueryInterface $query): ?string
    {
        if (isset(self::FIELDS[$field])) {
            return self::FIELDS[$field];
        }

        if (in_array($field, ['meta_value', 'meta_value_num'], true)) {
            $metaKey = $query->get('meta_key');
        } else {
            // A named meta_query clause: 'orderby' => 'price_clause'
            $metaQuery = $query->get('meta_query');
            $metaKey = is_array($metaQuery) ? ($metaQuery[$field]['key'] ?? null) : null;
        }

        if (! is_string($metaKey) || $metaKey === '') {
            return null;
        }

        return in_array($metaKey, Settings::get('indexed_meta_keys', []), true) ? "metas.{$metaKey}" : null;
    }
}
