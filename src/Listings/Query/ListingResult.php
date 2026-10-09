<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Query;

use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\State\ListingState;

/**
 * A listing in a state: its posts (a WP_Query), its facets' values and counts,
 * and what the client needs to count them again.
 */
final class ListingResult
{
    /**
     * @param  array<string, array{options: list<array{value: string, label: string, count: int, selected: bool, depth: int}>, stats: array{min: float, max: float}|null}>  $facets
     * @param  array<string, mixed>|null  $template  What the client replays (PlanTemplate), null when it cannot
     */
    public function __construct(
        public readonly ListingDefinition $definition,
        public readonly ListingState $state,
        public readonly \WP_Query $query,
        public readonly array $facets,
        public readonly ?array $template,
        public readonly ?string $countsError = null,
    ) {}

    public function total(): int
    {
        return (int) $this->query->found_posts;
    }

    public function pages(): int
    {
        return (int) $this->query->max_num_pages;
    }
}
