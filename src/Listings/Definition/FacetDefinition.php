<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Definition;

use Pollora\MeiliScout\Listings\Language\Languages;

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
     * @param  'taxonomy'|'meta'|'author'  $source
     * @param  'list'|'range'|'boolean'  $type
     * @param  'or'|'and'  $logic  How the values of a list combine
     * @param  array<string, string>  $labels  Meta values' labels, by value
     * @param  string|null  $path  Its prefix in the URL's path ({prefix}-{a},{b}), null when it is a parameter
     * @param  array<string, string>  $paths  Its prefix by language, when they differ (fr => type, en => kind)
     * @param  bool  $search  A field narrowing its values by their label (a list)
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
        public readonly ?string $path = null,
        public readonly array $paths = [],
        public readonly bool $search = false,
    ) {}

    /**
     * Its prefix in the path in a language (the request's by default).
     */
    public function prefix(?string $language = null): ?string
    {
        return $this->paths[$language ?? Languages::current()] ?? $this->path;
    }

    /**
     * Every prefix it may have in a path, whatever the language.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        return array_values(array_unique(array_filter([$this->path, ...array_values($this->paths)])));
    }

    /**
     * Its values go in the URL's path rather than its query string.
     */
    public function inPath(): bool
    {
        return $this->path !== null;
    }

    public function isTaxonomy(): bool
    {
        return $this->source === 'taxonomy';
    }

    /**
     * Its values are the posts' authors: user slugs in the URL, user ids in the index.
     */
    public function isAuthor(): bool
    {
        return $this->source === 'author';
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

        if ($this->isAuthor()) {
            return 'post_author';
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
