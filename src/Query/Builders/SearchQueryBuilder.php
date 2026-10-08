<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Config\SearchableAttributes;
use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\IndexSettings;

use function apply_filters;

/**
 * Translates s, sentence and search_columns.
 *
 * A search is ranked by relevance, where MySQL matches words with LIKE: the
 * results differ by nature, and are not compared strictly. sentence makes the
 * words one phrase; search_columns restricts the fields searched.
 */
class SearchQueryBuilder implements QueryBuilderInterface
{
    /**
     * The columns WordPress searches, and the document attributes holding them.
     */
    private const COLUMNS = [
        'post_title' => 'post_title',
        'post_excerpt' => 'post_excerpt',
        'post_content' => 'content_text',
    ];

    /**
     * @param  QueryInterface  $query  The WordPress query
     * @param  array<string, mixed>  $searchParams  The MeiliSearch search parameters to modify
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $searchTerm = $query->get('s');

        // '0' is a search too
        if (! is_scalar($searchTerm) || (string) $searchTerm === '') {
            return;
        }

        $searchTerm = (string) $searchTerm;

        if (! empty($query->get('sentence'))) {
            $searchTerm = '"'.trim(str_replace('"', ' ', $searchTerm)).'"';
        }

        $searchParams['q'] = $searchTerm;

        $attributes = $this->attributesToSearchOn($query);

        if ($attributes !== null) {
            $searchParams['attributesToSearchOn'] = $attributes;
        }
    }

    /**
     * The attributes search_columns restricts the search to, as WP_Query::parse_search() reads it.
     *
     * @return list<string>|null
     */
    private function attributesToSearchOn(QueryInterface $query): ?array
    {
        $columns = $query->get('search_columns');

        if (empty($columns)) {
            return null;
        }

        $columns = (array) apply_filters('post_search_columns', (array) $columns, (string) $query->get('s'), $query);
        $columns = array_values(array_intersect($columns, array_keys(self::COLUMNS)));

        // None WordPress supports: its default columns
        if ($columns === []) {
            $columns = array_keys(self::COLUMNS);
        }

        $attributes = array_map(static fn (string $column) => self::COLUMNS[$column], $columns);
        // As pushed to the index, which an indexable may have set otherwise than the admin
        $searchable = IndexSettings::searchable(IndexNames::active('posts')) ?? SearchableAttributes::forIndex();

        // Meilisearch searches only searchable attributes
        if (IndexSettings::firstUncovered($searchable, $attributes) !== null) {
            throw new UnsupportedQuery('unsupported_arg:search_columns');
        }

        return $attributes;
    }
}
