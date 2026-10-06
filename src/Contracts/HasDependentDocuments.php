<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Contracts;

/**
 * An indexable whose items also bring documents of their own, such as the variants of a product.
 *
 * The dependent documents are written with the item's document and removed with it, so none of them
 * outlives the item that produced it.
 */
interface HasDependentDocuments
{
    /**
     * The documents an item's document brings along, once that document is complete.
     *
     * @param array<string, mixed> $document
     * @return list<array<string, mixed>>
     */
    public function dependentDocuments(array $document, mixed $item): array;

    /**
     * A Meilisearch filter matching every dependent document of the given items, or null when none of them can bring
     * any: no deletion is then sent.
     *
     * @param non-empty-list<int|string> $itemIds
     */
    public function dependentDocumentsFilter(array $itemIds): ?string;
}
