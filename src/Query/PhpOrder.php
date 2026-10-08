<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Pollora\MeiliScout\Contracts\QueryInterface;

use function apply_filters;

/**
 * An order Meilisearch cannot sort on, applied in PHP: random, or the order of a list.
 *
 * orderby rand, post__in, post_name__in and post_parent__in: every result is
 * fetched (ids only), put in order here, and the page asked for is cut out.
 * Beyond a few hundred results, the query runs on MySQL instead.
 */
final class PhpOrder
{
    /**
     * Results put in order in PHP at most, by default.
     */
    public const DEFAULT_LIMIT = 1000;

    /**
     * @param  'rand'|'post__in'|'post_name__in'|'post_parent__in'  $kind
     * @param  list<int|string>  $list  The list whose order the posts follow
     */
    private function __construct(
        public readonly string $kind,
        private readonly array $list = [],
        private readonly ?int $seed = null,
    ) {}

    /**
     * The order a query asks for, when it is one to apply in PHP.
     */
    public static function of(QueryInterface $query): ?self
    {
        $orderby = $query->get('orderby');

        if (is_array($orderby)) {
            $orderby = count($orderby) === 1 ? (string) array_key_first($orderby) : null;
        }

        if (! is_string($orderby)) {
            return null;
        }

        if ($orderby === 'rand') {
            return new self('rand');
        }

        if (preg_match('/^RAND\((\d+)\)$/i', $orderby, $matches)) {
            return new self('rand', [], (int) $matches[1]);
        }

        if (! in_array($orderby, ['post__in', 'post_name__in', 'post_parent__in'], true)) {
            return null;
        }

        $list = $query->get($orderby);

        // WordPress ignores an order on an empty list
        if (! is_array($list) || $list === []) {
            return null;
        }

        $list = $orderby === 'post_name__in'
            ? array_map(static fn ($name) => function_exists('sanitize_title_for_query') ? sanitize_title_for_query((string) $name) : (string) $name, $list)
            : array_map(static fn ($id) => abs((int) $id), $list);

        return new self($orderby, array_values($list));
    }

    /**
     * The most results put in order in PHP.
     */
    public static function limit(): int
    {
        return max(1, (int) apply_filters('meiliscout/php_order_limit', self::DEFAULT_LIMIT));
    }

    /**
     * The attributes the hits need for this order.
     *
     * @return list<string>
     */
    public function attributes(): array
    {
        return match ($this->kind) {
            'post_name__in' => ['post_name'],
            'post_parent__in' => ['post_parent'],
            default => [],
        };
    }

    /**
     * The hits in this order.
     *
     * @param  list<array<string, mixed>>  $hits
     * @return list<array<string, mixed>>
     */
    public function sort(array $hits): array
    {
        if ($this->kind === 'rand') {
            if ($this->seed !== null) {
                mt_srand($this->seed);
                shuffle($hits);
                mt_srand();
            } else {
                shuffle($hits);
            }

            return $hits;
        }

        $field = ['post__in' => 'ID', 'post_name__in' => 'post_name', 'post_parent__in' => 'post_parent'][$this->kind];
        $positions = [];

        foreach ($this->list as $position => $value) {
            $positions[(string) $value] ??= $position;
        }

        // As MySQL's FIELD(): by position in the list; the sort is stable for equal positions
        usort($hits, static fn (array $a, array $b) => ($positions[(string) ($a[$field] ?? '')] ?? PHP_INT_MAX) <=> ($positions[(string) ($b[$field] ?? '')] ?? PHP_INT_MAX));

        return $hits;
    }
}
