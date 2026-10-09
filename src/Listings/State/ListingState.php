<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\State;

/**
 * What a visitor chose on a listing: the values of each facet, the bounds of
 * each range, the sort, the page and the search. Immutable, canonical (values
 * unique and sorted), built by UrlCodec.
 */
final class ListingState
{
    /**
     * @param  array<string, list<string>>  $values  List and boolean facets, by key: term slugs, meta values
     * @param  array<string, array{min?: float, max?: float}>  $ranges  Range facets, by key
     */
    public function __construct(
        public readonly array $values = [],
        public readonly array $ranges = [],
        public readonly string $sort = '',
        public readonly int $page = 1,
        public readonly string $search = '',
    ) {}

    /**
     * @return list<string>
     */
    public function valuesOf(string $facet): array
    {
        return $this->values[$facet] ?? [];
    }

    /**
     * @return array{min?: float, max?: float}
     */
    public function rangeOf(string $facet): array
    {
        return $this->ranges[$facet] ?? [];
    }

    public function isActive(string $facet): bool
    {
        return ($this->values[$facet] ?? []) !== [] || ($this->ranges[$facet] ?? []) !== [];
    }

    public function hasFilters(): bool
    {
        return array_filter($this->values) !== [] || array_filter($this->ranges) !== [] || $this->search !== '';
    }

    /**
     * The same choices on another page.
     */
    public function onPage(int $page): self
    {
        return new self($this->values, $this->ranges, $this->sort, max(1, $page), $this->search);
    }
}
