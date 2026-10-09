<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Query;

use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\State\ListingState;

/**
 * A listing in a state: its posts (a WP_Query) or, in the client transport,
 * its cards (Hits); its facets' values and counts, and what the client needs
 * to count them again.
 */
final class ListingResult
{
    /**
     * @param  \WP_Query|null  $query  The posts, null when the cards come from the documents
     * @param  array<string, array{options: list<array{value: string, label: string, count: int, selected: bool, depth: int}>, stats: array{min: float, max: float}|null}>  $facets
     * @param  array<string, mixed>|null  $template  What the client replays (PlanTemplate), null when it cannot
     * @param  list<array<string, mixed>>|null  $hits  The client transport's cards, from the documents
     */
    public function __construct(
        public readonly ListingDefinition $definition,
        public readonly ListingState $state,
        public readonly ?\WP_Query $query,
        public readonly array $facets,
        public readonly ?array $template,
        public readonly ?string $countsError = null,
        public readonly ?array $hits = null,
        private readonly int $hitsTotal = 0,
    ) {}

    public function total(): int
    {
        return $this->query !== null ? (int) $this->query->found_posts : $this->hitsTotal;
    }

    public function pages(): int
    {
        if ($this->query !== null) {
            return (int) $this->query->max_num_pages;
        }

        return (int) ceil($this->hitsTotal / max(1, $this->definition->perPage));
    }
}
