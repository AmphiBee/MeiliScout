<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Domain\Search\Enums\ComparisonOperator;
use Pollora\MeiliScout\Domain\Search\Enums\TaxonomyFields;
use Pollora\MeiliScout\Domain\Search\Validators\EnumValidator;
use Pollora\MeiliScout\Query\QueryVars;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * Builder for taxonomy query filters.
 * 
 * Handles the conversion of WordPress tax_query parameters to MeiliSearch filter syntax.
 */
class TaxQueryBuilder extends AbstractFilterBuilder
{
    private const OPERATORS = ['IN', 'NOT IN', 'AND', 'EXISTS', 'NOT EXISTS'];

    /**
     * The clauses WordPress parsed, cat, tag and taxonomy query vars included.
     */
    protected function queries(QueryInterface $query): array
    {
        return QueryVars::taxQuery($query);
    }

    /**
     * As WP_Tax_Query::is_first_order_clause().
     */
    protected function isClause(array $entry): bool
    {
        return $entry === []
            || array_key_exists('terms', $entry)
            || array_key_exists('taxonomy', $entry)
            || array_key_exists('include_children', $entry)
            || array_key_exists('field', $entry)
            || array_key_exists('operator', $entry);
    }

    /**
     * {@inheritdoc}
     *
     * Builds a filter expression for a taxonomy query clause, with the
     * outcomes of WP_Tax_Query::get_sql_for_clause(): no term to look for
     * is no post for IN, and no restriction for NOT IN and AND.
     *
     * @param array $query The taxonomy query clause
     * @return string The MeiliSearch filter expression
     */
    protected function buildSingleFilter(array $query): string
    {
        $operator = strtoupper(trim((string) ($query['operator'] ?? 'IN')));

        if (! in_array($operator, self::OPERATORS, true)) {
            throw new UnsupportedQuery('unsupported_tax_operator:'.$operator);
        }

        // Indexes built before 2.0 only have the flat `terms` list
        if (IndexNames::activeSchema() < 2) {
            return $this->buildLegacyFilter([...$query, 'operator' => $operator]);
        }

        $taxonomy = $query['taxonomy'] ?? '';

        if ($taxonomy === '') {
            // WordPress accepts term_taxonomy_ids of any taxonomy; the documents have them per taxonomy
            throw new UnsupportedQuery('unsupported_tax_query:no_taxonomy');
        }

        // The taxonomy becomes part of an attribute name: keep to the characters taxonomy names use
        if (! is_string($taxonomy) || ! preg_match('/^[a-zA-Z0-9_-]+$/', $taxonomy)) {
            throw new UnsupportedQuery('unsupported_tax_query:taxonomy');
        }

        // An unknown taxonomy is an error WordPress answers with no post
        if (function_exists('taxonomy_exists') && ! taxonomy_exists($taxonomy)) {
            return self::MATCH_NOTHING;
        }

        $field = EnumValidator::getValidValueOrDefault(
            TaxonomyFields::class,
            is_string($query['field'] ?? null) ? $query['field'] : TaxonomyFields::getDefault()->value,
            TaxonomyFields::getDefault()
        )->value;

        // A post has a term of the taxonomy when it has a term id there
        if ($operator === 'EXISTS') {
            return "taxonomies.{$taxonomy}.term_id EXISTS";
        }

        if ($operator === 'NOT EXISTS') {
            return "taxonomies.{$taxonomy}.term_id NOT EXISTS";
        }

        $values = $this->terms($query['terms'] ?? [], $field);

        // As in WordPress, a term of a hierarchical taxonomy brings its children along
        if ($values !== [] && ($query['include_children'] ?? true)) {
            // From posts schema 5 each term carries its ancestors: a term with its
            // descendants is one value of the tree. AND keeps the list WordPress
            // builds (every term and every child), which the tree cannot express.
            if ($operator !== 'AND' && IndexNames::activeSchema() >= 5 && $this->isHierarchical($taxonomy)) {
                $ids = $this->termIds($taxonomy, $field, $values);

                if ($ids === []) {
                    return $operator === 'IN' ? self::MATCH_NOTHING : '';
                }

                return "taxonomies.{$taxonomy}.tree {$operator} [{$this->formatArrayValues($ids)}]";
            }

            $missing = 0;
            $withChildren = $this->withChildren($taxonomy, $field, $values, $missing);

            // AND on a term that does not exist is an error WordPress answers with no post
            if ($operator === 'AND' && $missing > 0) {
                return self::MATCH_NOTHING;
            }

            if ($withChildren !== null) {
                [$field, $values] = ['term_id', $withChildren];
            }
        }

        if ($values === []) {
            return $operator === 'IN' ? self::MATCH_NOTHING : '';
        }

        $key = "taxonomies.{$taxonomy}.{$field}";

        return match ($operator) {
            'IN' => "{$key} IN [{$this->formatArrayValues($values)}]",
            'NOT IN' => "{$key} NOT IN [{$this->formatArrayValues($values)}]",
            // AND: the post carries every one of the terms
            'AND' => '('.implode(' AND ', array_map(fn ($value) => "{$key} = {$this->formatValue($value)}", $values)).')',
        };
    }

    /**
     * The terms of a clause, as WP_Tax_Query::clean_query() reads them.
     *
     * @return list<int|string>
     */
    private function terms(mixed $terms, string $field): array
    {
        $terms = is_array($terms) ? $terms : [$terms];

        if (in_array($field, ['slug', 'name'], true)) {
            $terms = array_filter($terms, static fn ($term) => is_scalar($term) && (string) $term !== '');

            // WP_Term_Query sanitizes the slugs it looks for
            if ($field === 'slug' && function_exists('sanitize_title')) {
                $terms = array_map(static fn ($term) => sanitize_title((string) $term), $terms);
            }

            return array_values(array_unique(array_map('strval', $terms)));
        }

        // Ids, as wp_parse_id_list() reads them
        $ids = [];

        foreach ($terms as $term) {
            foreach (is_string($term) ? (preg_split('/[\s,]+/', $term) ?: []) : [$term] as $id) {
                if (is_scalar($id) && (string) $id !== '') {
                    $ids[] = abs((int) $id);
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function isHierarchical(string $taxonomy): bool
    {
        return function_exists('is_taxonomy_hierarchical') && is_taxonomy_hierarchical($taxonomy);
    }

    /**
     * The ids of the given terms, those no term has left out.
     *
     * @param  list<int|string>  $values  Term ids, slugs, names or term_taxonomy_ids
     * @return list<int>
     */
    private function termIds(string $taxonomy, string $field, array $values): array
    {
        if ($field === 'term_id') {
            return array_map('intval', $values);
        }

        $ids = [];

        foreach ($values as $value) {
            $term = get_term_by($field, $value, $taxonomy);

            if ($term instanceof \WP_Term) {
                $ids[] = (int) $term->term_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The ids of the given terms and of all their descendants, or null when the
     * taxonomy is flat or WordPress is not there to tell.
     *
     * @param  list<int|string>  $values  Term ids, slugs, names or term_taxonomy_ids
     * @param  int  $missing  Set to the number of values no term has
     * @return list<int>|null
     */
    private function withChildren(string $taxonomy, string $field, array $values, int &$missing): ?array
    {
        $missing = 0;

        if (! $this->isHierarchical($taxonomy)) {
            return null;
        }

        $ids = [];

        foreach ($values as $value) {
            $term = get_term_by($field, $value, $taxonomy);

            if (! $term instanceof \WP_Term) {
                $missing++;

                continue;
            }

            $ids[] = (int) $term->term_id;

            $children = get_term_children((int) $term->term_id, $taxonomy);

            if (is_array($children)) {
                array_push($ids, ...array_map('intval', $children));
            }
        }

        return $ids === [] ? null : array_values(array_unique($ids));
    }

    /**
     * Builds a filter against the flat `terms` list of indexes built before 2.0.
     *
     * Meilisearch matches terms.taxonomy and terms.slug independently there:
     * a tag named like a category satisfies the category filter.
     */
    private function buildLegacyFilter(array $query): string
    {
        if (empty($query['taxonomy'])) {
            return '';
        }

        $taxonomy = $query['taxonomy'];

        $field = EnumValidator::getValidValueOrDefault(
            TaxonomyFields::class,
            $query['field'] ?? TaxonomyFields::getDefault()->value,
            TaxonomyFields::getDefault()
        )->value;

        /** @var ComparisonOperator $operator */
        $operator = EnumValidator::getValidValueOrDefault(
            ComparisonOperator::class,
            $query['operator'] ?? ComparisonOperator::getTaxonomyDefault()->value,
            ComparisonOperator::getTaxonomyDefault()
        );

        if (!in_array($operator, ComparisonOperator::getTaxonomyOperators(), true)) {
            return '';
        }

        $fieldKey = "terms.{$field}";
        $taxonomyKey = "terms.taxonomy";

        $taxonomyValue = $this->formatValue($taxonomy);

        // A post has the taxonomy when one of its terms belongs to it
        if ($operator === ComparisonOperator::EXISTS) {
            return "{$taxonomyKey} = {$taxonomyValue}";
        }

        if ($operator === ComparisonOperator::NOT_EXISTS) {
            return "NOT {$taxonomyKey} = {$taxonomyValue}";
        }

        // Handle cases requiring values
        if (!array_key_exists('terms', $query)) {
            return '';
        }

        $terms = is_array($query['terms'])
            ? $this->formatArrayValues($query['terms'])
            : $this->formatValue($query['terms']);

        if (empty($terms)) {
            return '';
        }

        // Special case for NOT IN / != : use an enclosing NOT clause
        if (in_array($operator, [ComparisonOperator::NOT_IN, ComparisonOperator::NOT_EQUALS], true)) {
            return "NOT ({$taxonomyKey} = {$taxonomyValue} AND {$fieldKey} IN [{$terms}])";
        }

        // AND: the post carries every one of the terms
        if ($operator === ComparisonOperator::AND) {
            $values = is_array($query['terms']) ? $query['terms'] : [$query['terms']];
            $each = array_map(fn ($value) => "{$fieldKey} = {$this->formatValue($value)}", $values);

            return "({$taxonomyKey} = {$taxonomyValue} AND ".implode(' AND ', $each).')';
        }

        // Default case: simple filter
        return "({$taxonomyKey} = {$taxonomyValue} AND {$fieldKey} {$operator->value} [{$terms}])";
    }
}
