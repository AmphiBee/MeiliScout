<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Query\UnsupportedQuery;

/**
 * Translates meiliscout_hybrid: a search by keywords and by meaning, through an embedder of the index.
 *
 * ```php
 * 'meiliscout_hybrid' => ['embedder' => 'mistral', 'semanticRatio' => 0.5, 'rankingScoreThreshold' => null]
 * ```
 *
 * Without a search term there is nothing to embed, and the var is ignored.
 * The ranking scores are asked for, for the query to expose them.
 */
final class HybridSearchBuilder implements QueryBuilderInterface
{
    public const QUERY_VAR = 'meiliscout_hybrid';

    /**
     * Meilisearch's own default: as much keywords as meaning.
     */
    private const DEFAULT_SEMANTIC_RATIO = 0.5;

    private const INVALID = 'invalid_arg:'.self::QUERY_VAR;

    /**
     * @param  array<string, mixed>  $searchParams
     *
     * @throws UnsupportedQuery When the var is not an embedder with ratios between 0 and 1
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $hybrid = $query->get(self::QUERY_VAR);

        if (empty($hybrid) || ! isset($searchParams['q'])) {
            return;
        }

        if (! is_array($hybrid) || ! is_string($hybrid['embedder'] ?? null) || $hybrid['embedder'] === '') {
            throw new UnsupportedQuery(self::INVALID);
        }

        $searchParams['hybrid'] = [
            'embedder' => $hybrid['embedder'],
            'semanticRatio' => self::ratio($hybrid['semanticRatio'] ?? self::DEFAULT_SEMANTIC_RATIO),
        ];
        $searchParams['showRankingScore'] = true;

        if (isset($hybrid['rankingScoreThreshold'])) {
            $searchParams['rankingScoreThreshold'] = self::ratio($hybrid['rankingScoreThreshold']);
        }
    }

    /**
     * @throws UnsupportedQuery
     */
    private static function ratio(mixed $value): float
    {
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
            throw new UnsupportedQuery(self::INVALID);
        }

        return (float) $value;
    }
}
