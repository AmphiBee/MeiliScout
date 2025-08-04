<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Contracts;

interface Indexable
{
    /**
     * Returns the Meilisearch index name.
     */
    public function getIndexName(): string;

    /**
     * Returns the primary key name for data indexed by Meilisearch.
     */
    public function getPrimaryKey(): string;

    /**
     * Returns the index configuration.
     */
    public function getIndexSettings(): array;

    /**
     * Retrieves the items to index.
     */
    public function getItems(): iterable;

    /**
     * Formats an item for indexing.
     */
    public function formatForIndexing(mixed $item): array;

    /**
     * Formats an item for search.
     */
    public function formatForSearch(array $hit): mixed;
}
