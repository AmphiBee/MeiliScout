<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Definition;

/**
 * One facet of a listing: where its values come from, how several combine,
 * and how it shows in the URL.
 */
final class FacetDefinition
{
    public const LIST = 'list';

    public const RANGE = 'range';

    public const BOOLEAN = 'boolean';

    /**
     * @param  'taxonomy'|'meta'  $source
     * @param  'list'|'range'|'boolean'  $type
     * @param  'or'|'and'  $logic  How the values of a list combine
     * @param  array<string, string>  $labels  Meta values' labels, by value
     */
    public function __construct(
        public readonly string $key,
        public readonly string $source,
        public readonly string $name,
        public readonly string $type,
        public readonly string $logic,
        public readonly bool $hierarchical,
        public readonly string $param,
        public readonly string $label,
        public readonly array $labels,
        public readonly int $limit,
        public readonly int $decimals,
        public readonly string $booleanValue,
    ) {}

    public function isTaxonomy(): bool
    {
        return $this->source === 'taxonomy';
    }

    /**
     * Its values combine as a disjunction: their counts ignore its own selection.
     */
    public function isDisjunctive(): bool
    {
        return $this->type === self::RANGE || ($this->type === self::LIST && $this->logic === 'or');
    }

    /**
     * The document field its values are counted (and its bounds read) on.
     *
     * A term of a hierarchical taxonomy is counted with its descendants (posts
     * schema 5, taxonomies.<taxonomy>.tree), a flat one by its id.
     */
    public function countField(): string
    {
        if ($this->isTaxonomy()) {
            return "taxonomies.{$this->name}.".($this->hierarchical ? 'tree' : 'term_id');
        }

        return "metas.{$this->name}";
    }

    /**
     * The meta type of its numeric comparisons, as WP_Meta_Query reads it.
     */
    public function numericType(): string
    {
        return $this->decimals > 0 ? "DECIMAL(20,{$this->decimals})" : 'NUMERIC';
    }
}
