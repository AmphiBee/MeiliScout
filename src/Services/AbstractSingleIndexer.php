<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Exception;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Pollora\MeiliScout\Contracts\HasDependentDocuments;
use Pollora\MeiliScout\Contracts\Indexable;

use function apply_filters;
use function current_time;
use function error_log;
use function update_option;

/**
 * Abstract base class for single-item indexing operations.
 *
 * This abstract class provides common functionality for indexing individual items
 * in Meilisearch. It handles client initialization, index management, logging,
 * and provides template methods for specialized indexers to implement.
 *
 * @package Pollora\MeiliScout\Services
 * @since 1.0.0
 */
abstract class AbstractSingleIndexer
{
    /**
     * Meilisearch client instance.
     *
     * @var Client
     */
    protected ?Client $client;

    /**
     * Indexable instance for formatting data.
     *
     * @var Indexable
     */
    protected ?Indexable $indexable = null;

    /**
     * Log of single indexing operations.
     *
     * @var array<string, mixed>
     */
    protected array $operationLog = [];

    /**
     * The name of the log option key for this indexer type.
     *
     * @var string
     */
    protected string $logOptionKey;

    /**
     * Static cache of index existence checks to avoid repeated API calls.
     *
     * @var array<string, bool>
     */
    protected static array $indexExistsCache = [];

    /**
     * Bytes of documents sent in one request, well under Meilisearch's default payload limit.
     */
    protected const MAX_PAYLOAD_BYTES = 10 * 1024 * 1024;

    /**
     * Counter for log operations to batch saves.
     *
     * @var int
     */
    protected int $logOperationCount = 0;

    /**
     * Number of operations between log saves for batch mode.
     *
     * @var int
     */
    protected int $logSaveInterval = 10;

    /**
     * Creates a new AbstractSingleIndexer instance.
     *
     * Initializes the Meilisearch client and operation log.
     */
    public function __construct()
    {
        $this->client = ClientFactory::getClient();
        $this->logOptionKey = $this->getLogOptionKey();
        $this->initializeOperationLog();
    }

    /**
     * The Meilisearch client, or an exception when Meilisearch could not be reached.
     *
     * Throwing an Exception (not calling a method on null, which raises an Error)
     * lets the hook handlers catch it, so a post still saves while Meilisearch is down.
     *
     * @throws \RuntimeException
     */
    protected function client(): Client
    {
        if ($this->client === null) {
            throw new \RuntimeException('Meilisearch is not reachable: check the host and the API key.');
        }

        return $this->client;
    }

    /**
     * The indexable, resolved on first use rather than in the constructor.
     *
     * `resolveIndexable()` applies the `meiliscout/indexables` filter, and this
     * class is built while plugins are still loading — before a consumer that
     * boots after MeiliScout has had a chance to register on it. Resolving on
     * first use is what makes the filter reach every indexing path.
     */
    protected function indexable(): Indexable
    {
        return $this->indexable ??= $this->createIndexable();
    }

    /**
     * Creates the appropriate indexable instance for this indexer.
     *
     * This method must be implemented by concrete classes to return
     * the specific indexable instance they work with.
     *
     * @return Indexable The indexable instance
     */
    abstract protected function createIndexable(): Indexable;

    /**
     * Gets the WordPress option key for storing operation logs.
     *
     * This method must be implemented by concrete classes to return
     * a unique option key for their operation logs.
     *
     * @return string The option key for operation logs
     */
    abstract protected function getLogOptionKey(): string;

    /**
     * Checks if an item should be indexed based on plugin configuration.
     *
     * This method must be implemented by concrete classes to determine
     * whether a specific item should be indexed based on plugin settings.
     *
     * @param mixed $item The item to check
     * @return bool True if the item should be indexed, false otherwise
     */
    abstract protected function shouldIndex(mixed $item): bool;

    /**
     * Gets the unique identifier for an item.
     *
     * This method must be implemented by concrete classes to extract
     * the unique identifier from an item for indexing operations.
     *
     * @param mixed $item The item to get the ID from
     * @return int|string The unique identifier
     */
    abstract protected function getItemId(mixed $item): int|string;

    /**
     * Gets a human-readable name for an item for logging purposes.
     *
     * This method must be implemented by concrete classes to provide
     * a descriptive name for logging and debugging.
     *
     * @param mixed $item The item to get the name from
     * @return string The item name
     */
    abstract protected function getItemName(mixed $item): string;

    /**
     * Indexes or updates a single item in Meilisearch.
     *
     * This method handles the indexing of individual items using the template
     * method pattern. Concrete classes provide item-specific logic through
     * abstract methods.
     *
     * @param mixed $item The item to index
     * @return bool True if the item was successfully indexed, false otherwise
     *
     * @throws Exception If there's an error during the indexing process
     */
    public function indexItem(mixed $item): bool
    {
        try {
            // Check if this item should be indexed
            if (!$this->shouldIndex($item)) {
                $itemName = $this->getItemName($item);
                $this->logOperation('info', "Item '{$itemName}' is not configured for indexing");
                return true; // Not an error, just not configured for indexing
            }

            // Ensure the index exists with proper configuration
            $this->ensureIndexExists();

            // Format the item for indexing
            $document = $this->indexable()->formatForIndexing($item);

            // Index the document and the documents it brings along, then drop the ones it no longer brings
            $index = $this->client()->index($this->indexable()->getIndexName());
            $documents = $this->withDependentDocuments($document, $item);
            $this->writeWithDependentDocuments($index, $documents, [$this->getItemId($item)]);

            $itemName = $this->getItemName($item);
            $itemId = $this->getItemId($item);
            $this->logOperation('success', "Item '{$itemName}' (ID: {$itemId}) indexed successfully");

            return true;

        } catch (Exception $e) {
            $this->logOperation('error', "Failed to index item: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Removes an item from the Meilisearch index.
     *
     * This method removes an item from the search index, typically called
     * when the item is deleted or no longer meets indexing criteria.
     *
     * @param int|string $itemId The ID of the item to remove
     * @return bool True if the item was successfully removed, false otherwise
     */
    public function removeItem(int|string $itemId): bool
    {
        try {
            $index = $this->client()->index($this->indexable()->getIndexName());
            $index->deleteDocument($itemId);
            $this->removeDependentDocuments($index, [$itemId]);

            $this->logOperation('success', "Item (ID: {$itemId}) removed from index");
            return true;

        } catch (Exception $e) {
            $this->logOperation('error', "Failed to remove item {$itemId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * An item's document followed by the documents it brings along, when the indexable has any.
     *
     * @param array<string, mixed> $document
     * @return list<array<string, mixed>>
     */
    protected function withDependentDocuments(array $document, mixed $item): array
    {
        $indexable = $this->indexable();

        if (! $indexable instanceof HasDependentDocuments) {
            return [$document];
        }

        return [$document, ...$indexable->dependentDocuments($document, $item)];
    }

    /**
     * Writes the items' documents in requests of a bounded size, then deletes the documents the items
     * brought along before and no longer do. Writing first means a failed request never leaves an item
     * without the documents it brings along: the previous ones stay until a write succeeds.
     *
     * @param list<array<string, mixed>> $documents
     * @param list<int|string> $itemIds
     */
    protected function writeWithDependentDocuments(Indexes $index, array $documents, array $itemIds): void
    {
        foreach ($this->inBoundedRequests($documents) as $request) {
            $index->addDocuments($request);
        }

        $this->removeStaleDependentDocuments($index, $itemIds, $this->dependentDocumentIdsIn($documents, $itemIds));
    }

    /**
     * Meilisearch refuses a request over its payload limit (100 MB by default), indexing included.
     *
     * @param list<array<string, mixed>> $documents
     * @return list<list<array<string, mixed>>>
     */
    protected function inBoundedRequests(array $documents): array
    {
        $limit = (int) apply_filters('meiliscout/max_payload_bytes', self::MAX_PAYLOAD_BYTES);
        $requests = [];
        $request = [];
        $size = 0;

        foreach ($documents as $document) {
            $documentSize = strlen((string) json_encode($document));

            if ($request !== [] && $size + $documentSize > $limit) {
                $requests[] = $request;
                $request = [];
                $size = 0;
            }

            $request[] = $document;
            $size += $documentSize;
        }

        return $request === [] ? $requests : [...$requests, $request];
    }

    /**
     * @param list<array<string, mixed>> $documents
     * @param list<int|string> $itemIds
     * @return list<int|string>
     */
    protected function dependentDocumentIdsIn(array $documents, array $itemIds): array
    {
        $primaryKey = $this->indexable()->getPrimaryKey();
        $ids = array_column($documents, $primaryKey);

        return array_values(array_filter($ids, static fn (int|string $id): bool => ! in_array($id, $itemIds, true)));
    }

    /**
     * The primary key has to be filterable for the documents just written to be kept.
     *
     * @param list<int|string> $itemIds
     * @param list<int|string> $keptIds
     */
    protected function removeStaleDependentDocuments(Indexes $index, array $itemIds, array $keptIds): void
    {
        $dependentDocumentsFilter = $this->dependentDocumentsFilterOf($itemIds);

        if ($dependentDocumentsFilter === null) {
            return;
        }

        $staleFilter = $this->staleDependentDocumentsFilter($dependentDocumentsFilter, $keptIds);
        $index->deleteDocuments(['filter' => $staleFilter]);
    }

    /**
     * A filter matching the items' dependent documents, except those just written.
     *
     * @param list<int|string> $keptIds
     */
    protected function staleDependentDocumentsFilter(string $dependentDocumentsFilter, array $keptIds): string
    {
        if ($keptIds === []) {
            return $dependentDocumentsFilter;
        }

        $keptFilter = $this->indexable()->getPrimaryKey() . ' IN [' . $this->listed($keptIds) . ']';

        return '(' . $dependentDocumentsFilter . ') AND NOT ' . $keptFilter;
    }

    /**
     * A number is written bare, a string quoted with its quotes and backslashes escaped.
     *
     * @param list<int|string> $values
     */
    protected function listed(array $values): string
    {
        return implode(', ', array_map(
            static fn (int|string $value): string => is_int($value) ? (string) $value : '"' . addcslashes($value, '"\\') . '"',
            $values
        ));
    }

    /**
     * Deletes every document the given items brought along.
     *
     * @param list<int|string> $itemIds
     */
    protected function removeDependentDocuments(Indexes $index, array $itemIds): void
    {
        $this->removeStaleDependentDocuments($index, $itemIds, []);
    }

    /**
     * The filter matching what the given items brought along, or null when none of them can bring anything.
     *
     * @param list<int|string> $itemIds
     */
    protected function dependentDocumentsFilterOf(array $itemIds): ?string
    {
        $indexable = $this->indexable();

        if (! $indexable instanceof HasDependentDocuments || $itemIds === []) {
            return null;
        }

        return $indexable->dependentDocumentsFilter($itemIds);
    }

    // Only Indexer reads this filter: without it, real-time indexing overwrites
    // the substituted indexable's settings on every save.
    protected function resolveIndexable(Indexable $default): Indexable
    {
        foreach (apply_filters('meiliscout/indexables', [$default]) as $indexable) {
            if ($indexable instanceof $default) {
                return $indexable;
            }
        }

        return $default;
    }

    /**
     * Ensures that the Meilisearch index exists with proper configuration.
     *
     * This method creates the index if it doesn't exist and updates its
     * settings to match the current indexable configuration.
     *
     * @return void
     * @throws Exception If there's an error creating or configuring the index
     */
    protected function ensureIndexExists(): void
    {
        try {
            $indexName = $this->indexable()->getIndexName();
            $primaryKey = $this->indexable()->getPrimaryKey();
            $settings = $this->indexable()->getIndexSettings();

            $index = $this->client()->index($indexName);

            if (! $this->indexExists($indexName)) {
                $this->client()->createIndex($indexName, ['primaryKey' => $primaryKey]);
                $this->markIndexExists($indexName);
                IndexSettings::push($index, $indexName, $settings);

                return;
            }

            // Settings are sent only when they changed: each update is a task, often a full re-index
            IndexSettings::pushIfChanged($index, $indexName, $settings);

        } catch (Exception $e) {
            $indexName = $this->indexable()->getIndexName();
            $this->logOperation('error', "Failed to ensure index '{$indexName}' exists: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Checks if an index exists in Meilisearch.
     *
     * Uses static caching to avoid repeated API calls across indexer instances.
     *
     * @param string $indexName The name of the index
     * @return bool True if the index exists, false otherwise
     */
    protected function indexExists(string $indexName): bool
    {
        // Check static cache first
        if (isset(self::$indexExistsCache[$indexName])) {
            return self::$indexExistsCache[$indexName];
        }

        try {
            // One GET for this index: the index list is paginated, 20 at a time
            $this->client()->getIndex($indexName);
            self::$indexExistsCache[$indexName] = true;
        } catch (ApiException $e) {
            if ($e->httpStatus !== 404) {
                throw $e;
            }

            self::$indexExistsCache[$indexName] = false;
        }

        return self::$indexExistsCache[$indexName];
    }

    /**
     * Marks an index as existing in the cache.
     *
     * Call this after creating an index to update the cache.
     *
     * @param string $indexName The name of the index
     */
    protected function markIndexExists(string $indexName): void
    {
        self::$indexExistsCache[$indexName] = true;
    }

    /**
     * Clears the index existence cache.
     *
     * Call this if indexes might have been deleted externally.
     */
    public static function clearIndexCache(): void
    {
        self::$indexExistsCache = [];
    }

    /**
     * Initializes the operation log for tracking indexing operations.
     *
     * @return void
     */
    protected function initializeOperationLog(): void
    {
        $this->operationLog = [
            'start_time' => current_time('mysql'),
            'operations' => [],
        ];
    }

    /**
     * Logs a single indexing operation.
     *
     * Uses batched saves to reduce database writes during bulk operations.
     *
     * @param string $type The type of operation (info, success, error)
     * @param string $message The log message
     * @param bool $forceSave Force immediate save to database
     * @return void
     */
    protected function logOperation(string $type, string $message, bool $forceSave = false): void
    {
        $logEntry = [
            'type' => $type,
            'message' => $message,
            'time' => current_time('mysql'),
        ];

        $this->operationLog['operations'][] = $logEntry;
        $this->logOperationCount++;

        // Also log errors to WordPress error log for debugging
        if ($type === 'error') {
            $indexerType = static::class;
            error_log("MeiliScout {$indexerType} Error: {$message}");
        }

        // Batch saves: only save every N operations, on errors, or when forced
        if ($forceSave || $type === 'error' || $this->logOperationCount >= $this->logSaveInterval) {
            $this->saveOperationLog();
            $this->logOperationCount = 0;
        }
    }

    /**
     * Saves the operation log to WordPress options.
     *
     * @return void
     */
    protected function saveOperationLog(): void
    {
        // Keep only the last 50 operations to prevent the log from growing too large
        if (count($this->operationLog['operations']) > 50) {
            $this->operationLog['operations'] = array_slice($this->operationLog['operations'], -50);
        }

        // Not autoloaded: the log is written often and only read by the admin
        update_option($this->logOptionKey, $this->operationLog, false);
    }

    /**
     * Flushes any pending log operations to the database.
     *
     * Call this at the end of batch operations to ensure all logs are saved.
     *
     * @return void
     */
    public function flushLogs(): void
    {
        if ($this->logOperationCount > 0) {
            $this->saveOperationLog();
            $this->logOperationCount = 0;
        }
    }

    /**
     * Gets the current operation log.
     *
     * This is useful for debugging and monitoring single indexing operations.
     *
     * @return array<string, mixed> The operation log
     */
    public function getOperationLog(): array
    {
        return $this->operationLog;
    }

    /**
     * Clears the operation log.
     *
     * @return void
     */
    public function clearOperationLog(): void
    {
        $this->initializeOperationLog();
        $this->saveOperationLog();
    }
}
