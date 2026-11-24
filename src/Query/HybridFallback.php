<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;

/**
 * When a hybrid search fails because of its embedder, the search runs again by keywords alone, on Meilisearch.
 *
 * Keywords on Meilisearch still beat MySQL's LIKE: a slow or unavailable
 * embedding API never sends a search to MySQL nor breaks it. An embedder that
 * failed is not asked again for the rest of the request.
 */
final class HybridFallback
{
    /**
     * Meilisearch's errors that come from the embedder, not from the search.
     */
    private const EMBEDDER_ERRORS = ['vector_embedding_error', 'invalid_search_embedder', 'invalid_search_hybrid_query'];

    /**
     * The reason given when Meilisearch did not answer in time, or at all: there is no error code.
     */
    private const UNANSWERED = 'timeout';

    /**
     * Embedders that failed during this request.
     *
     * @var array<string, true>
     */
    private static array $failed = [];

    /**
     * Whether a search failed because of its embedder, and is to run again by keywords.
     *
     * @param  array<string, mixed>  $params
     */
    public static function applies(array $params, \Throwable $error): bool
    {
        if (! isset($params['hybrid'])) {
            return false;
        }

        return ($error instanceof ApiException && in_array($error->errorCode, self::EMBEDDER_ERRORS, true))
            || $error instanceof CommunicationException;
    }

    /**
     * Why the search fell back: Meilisearch's error code, or `timeout`.
     */
    public static function reason(\Throwable $error): string
    {
        return $error instanceof ApiException && is_string($error->errorCode) ? $error->errorCode : self::UNANSWERED;
    }

    /**
     * The same search by keywords alone.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function keywordsOnly(array $params): array
    {
        unset($params['hybrid']);

        return $params;
    }

    /**
     * Remembers that an embedder failed, for the other searches of the request.
     *
     * @param  array<string, mixed>  $params
     */
    public static function remember(array $params): void
    {
        $embedder = $params['hybrid']['embedder'] ?? null;

        if (is_string($embedder)) {
            self::$failed[$embedder] = true;
        }
    }

    /**
     * Whether the search's embedder already failed during this request.
     *
     * @param  array<string, mixed>  $params
     */
    public static function failedBefore(array $params): bool
    {
        $embedder = $params['hybrid']['embedder'] ?? null;

        return is_string($embedder) && isset(self::$failed[$embedder]);
    }

    /**
     * Forgets the embedders that failed. For tests, and between requests of a long-running process.
     */
    public static function reset(): void
    {
        self::$failed = [];
    }
}
