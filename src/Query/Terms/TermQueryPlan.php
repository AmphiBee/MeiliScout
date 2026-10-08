<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Terms;

/**
 * What a term query asks Meilisearch, and what is left to do in PHP, as WordPress does it.
 */
final class TermQueryPlan
{
    /**
     * @var list<string> The filter, clause by clause
     */
    public array $filters = [];

    /**
     * @var list<string> The sort
     */
    public array $sort = [];

    /**
     * The order of a list (include, slug__in), applied in PHP as MySQL's FIELD().
     *
     * @var array{field: string, values: list<int|string>, desc: bool}|null
     */
    public ?array $listOrder = null;

    /**
     * A search ranked by relevance (search without CONTAINS).
     */
    public ?string $q = null;

    public bool $relevance = false;

    /**
     * @var list<string>
     */
    public array $taxonomies = [];

    public string $fields = 'all';

    public int $childOf = 0;

    public bool $hierarchical = false;

    public bool $hideEmpty = false;

    public bool $padCounts = false;

    public int $number = 0;

    public int $offset = 0;

    public bool $updateMetaCache = true;

    /**
     * The terms of each post object_ids names, in the queried taxonomies; null without object_ids.
     *
     * @var array<int, list<int>>|null
     */
    public ?array $objectTerms = null;

    /**
     * Whether WordPress pages in SQL (LIMIT); otherwise it filters and pages in PHP.
     */
    public bool $limited = false;

    /**
     * The search parameters, without the page.
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        $params = [];

        if ($this->filters !== []) {
            $params['filter'] = implode(' AND ', array_map(static fn (string $filter) => str_contains($filter, ' OR ') ? "({$filter})" : $filter, $this->filters));
        }

        if ($this->sort !== []) {
            $params['sort'] = $this->sort;
        }

        if ($this->q !== null) {
            $params['q'] = $this->q;
            $params['attributesToSearchOn'] = ['name', 'slug'];
        }

        return $params;
    }

    /**
     * Whether the page can be asked for: WordPress pages in SQL, and the order is Meilisearch's.
     */
    public function pagedByMeilisearch(): bool
    {
        return $this->limited && $this->listOrder === null;
    }
}
