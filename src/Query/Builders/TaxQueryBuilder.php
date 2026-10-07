<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Domain\Search\Enums\ComparisonOperator;
use Pollora\MeiliScout\Domain\Search\Enums\TaxonomyFields;
use Pollora\MeiliScout\Domain\Search\Validators\EnumValidator;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * Builder for taxonomy query filters.
 * 
 * Handles the conversion of WordPress tax_query parameters to MeiliSearch filter syntax.
 */
class TaxQueryBuilder extends AbstractFilterBuilder
{
    /**
     * {@inheritdoc}
     */
    protected function getQueryKey(): string
    {
        return 'tax_query';
    }

    /**
     * {@inheritdoc}
     * 
     * Builds a filter expression for a taxonomy query clause.
     * 
     * @param array $query The taxonomy query clause
     * @return string The MeiliSearch filter expression
     */
    protected function buildSingleFilter(array $query): string
    {
        // Indexes built before 2.0 only have the flat `terms` list
        if (IndexNames::activeSchema() < 2) {
            return $this->buildLegacyFilter($query);
        }

        $taxonomy = $query['taxonomy'] ?? '';

        // The taxonomy becomes part of an attribute name: keep to the characters taxonomy names use
        if (! is_string($taxonomy) || ! preg_match('/^[a-zA-Z0-9_-]+$/', $taxonomy)) {
            return '';
        }

        $field = EnumValidator::getValidValueOrDefault(
            TaxonomyFields::class,
            $query['field'] ?? TaxonomyFields::getDefault()->value,
            TaxonomyFields::getDefault()
        )->value;

        $operator = EnumValidator::getValidValueOrDefault(
            ComparisonOperator::class,
            $query['operator'] ?? ComparisonOperator::getTaxonomyDefault()->value,
            ComparisonOperator::getTaxonomyDefault()
        );

        if (! in_array($operator, ComparisonOperator::getTaxonomyOperators(), true)) {
            return '';
        }

        // A post has a term of the taxonomy when it has a term id there
        if ($operator === ComparisonOperator::EXISTS) {
            return "taxonomies.{$taxonomy}.term_id EXISTS";
        }

        if ($operator === ComparisonOperator::NOT_EXISTS) {
            return "taxonomies.{$taxonomy}.term_id NOT EXISTS";
        }

        if (! array_key_exists('terms', $query)) {
            return '';
        }

        $values = array_values(array_filter((array) $query['terms'], fn ($value) => $value !== '' && $value !== null));

        if ($values === []) {
            return '';
        }

        // As in WordPress, a term of a hierarchical taxonomy brings its children along
        if ($operator !== ComparisonOperator::AND && ($query['include_children'] ?? true)) {
            $withChildren = $this->withChildren($taxonomy, $field, $values);

            if ($withChildren !== null) {
                [$field, $values] = ['term_id', $withChildren];
            }
        }

        $key = "taxonomies.{$taxonomy}.{$field}";

        return match ($operator) {
            ComparisonOperator::IN => "{$key} IN [{$this->formatArrayValues($values)}]",
            ComparisonOperator::NOT_IN => "{$key} NOT IN [{$this->formatArrayValues($values)}]",
            // AND: the post carries every one of the terms
            ComparisonOperator::AND => '('.implode(' AND ', array_map(fn ($value) => "{$key} = {$this->formatValue($value)}", $values)).')',
            default => '',
        };
    }

    /**
     * The ids of the given terms and of all their descendants, or null when the
     * taxonomy is flat or WordPress is not there to tell.
     *
     * @param  list<int|string>  $values  Term ids, slugs, names or term_taxonomy_ids
     * @return list<int>|null
     */
    private function withChildren(string $taxonomy, string $field, array $values): ?array
    {
        if (! function_exists('is_taxonomy_hierarchical') || ! is_taxonomy_hierarchical($taxonomy)) {
            return null;
        }

        $ids = [];

        foreach ($values as $value) {
            $term = get_term_by($field, $value, $taxonomy);

            if (! $term instanceof \WP_Term) {
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
