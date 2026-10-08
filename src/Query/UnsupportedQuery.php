<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

/**
 * A query, or a clause of it, that Meilisearch cannot answer as MySQL would.
 *
 * Thrown while a query is translated: the whole query then runs on MySQL,
 * with the reason recorded, rather than on Meilisearch without the clause.
 */
final class UnsupportedQuery extends \RuntimeException
{
    /**
     * @param  string  $reason  Why, as recorded: unsupported_arg:author, unindexed_meta:price...
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Query not supported by Meilisearch: {$reason}");
    }
}
