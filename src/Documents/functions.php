<?php

declare(strict_types=1);

use Pollora\MeiliScout\Contracts\Indexable;
use Pollora\MeiliScout\Documents\ItemSync;

if (! function_exists('meiliscout_sync_items')) {
    /**
     * Adds or replaces the documents of some items in an indexable's index, without a full indexation.
     *
     * The index is created with the indexable's settings when missing. With the
     * asynchronous queue on, the documents go out with WP-Cron, as posts do.
     *
     * @param  iterable<mixed>  $items  What the indexable's formatForIndexing() takes
     * @return int The number of documents sent or queued
     *
     * @throws \RuntimeException When Meilisearch is not configured and the documents are to be written at once
     * @throws \Meilisearch\Exceptions\ApiException When Meilisearch refuses the write
     */
    function meiliscout_sync_items(Indexable $indexable, iterable $items): int
    {
        return ItemSync::make()->sync($indexable, $items);
    }
}

if (! function_exists('meiliscout_delete_items')) {
    /**
     * Deletes the documents of an indexable's index that match a Meilisearch filter (`attachment_id = 123`).
     *
     * The attributes the filter reads must be filterable in the indexable's settings.
     *
     * @throws \RuntimeException When Meilisearch is not configured and the deletion is to run at once
     * @throws \Meilisearch\Exceptions\ApiException When Meilisearch refuses the deletion
     */
    function meiliscout_delete_items(Indexable $indexable, string $filter): void
    {
        ItemSync::make()->delete($indexable, $filter);
    }
}
