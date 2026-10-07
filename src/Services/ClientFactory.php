<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Meilisearch\Client;
use Pollora\MeiliScout\Config\Config;

use function error_log;
use function get_transient;
use function set_transient;

/**
 * Factory for creating and managing the Meilisearch client instances.
 */
class ClientFactory
{
    /**
     * Cache key prefix for the reachability probe.
     */
    private const PROBE_CACHE_PREFIX = 'meiliscout_probe_';

    /**
     * How long a successful probe is trusted, in seconds.
     */
    private const PROBE_TTL_REACHABLE = 300;

    /**
     * How long a failed probe is trusted, in seconds. Shorter than the success
     * window so a Meilisearch instance that comes back is picked up quickly.
     */
    private const PROBE_TTL_UNREACHABLE = 30;

    /**
     * The Meilisearch client instance, built with the admin key.
     */
    private static ?Client $instance = null;

    /**
     * The Meilisearch client instance, built with the search key.
     */
    private static ?Client $searchInstance = null;

    /**
     * Gets the client built with the admin key, used to write to the indexes.
     *
     * Returns null when the host or the key is missing, or when Meilisearch
     * does not answer.
     */
    public static function getClient(): ?Client
    {
        return self::$instance ??= self::makeClient('meili_key');
    }

    /**
     * Gets the client built with the search key, used to read from the indexes.
     *
     * Returns null when no search key is set or when Meilisearch does not answer.
     */
    public static function getSearchClient(): ?Client
    {
        return self::$searchInstance ??= self::makeClient('meili_search_key');
    }

    /**
     * Gets the client to search with: the search key when one is set, the
     * admin key otherwise.
     */
    public static function getReadClient(): ?Client
    {
        if (! empty(Config::get('meili_search_key'))) {
            return self::getSearchClient();
        }

        return self::getClient();
    }

    /**
     * Whether a host and an admin key are set. Does not contact Meilisearch.
     */
    public static function isConfigured(): bool
    {
        return ! empty(Config::get('meili_host')) && ! empty(Config::get('meili_key'));
    }

    /**
     * Whether Meilisearch answers with the admin key.
     */
    public static function isReachable(): bool
    {
        return self::getClient() !== null;
    }

    /**
     * Whether Meilisearch answers with the search key, when one is set.
     */
    public static function isSearchConfigured(): bool
    {
        return self::getSearchClient() !== null;
    }

    /**
     * Forgets the clients built so far, so the next call reads the settings again.
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$searchInstance = null;
    }

    /**
     * Builds a client from the configured host and the given key setting.
     */
    private static function makeClient(string $keySetting): ?Client
    {
        $host = Config::get('meili_host');
        $key = Config::get($keySetting);

        if (empty($host) || empty($key)) {
            return null;
        }

        if (! self::isValidHost($host)) {
            self::logError("Invalid Meilisearch host: {$host}");

            return null;
        }

        try {
            $client = new Client($host, $key);
        } catch (\Throwable $e) {
            self::logError('Failed to create the Meilisearch client: '.$e->getMessage());

            return null;
        }

        return self::isAvailable($client, $host, $key) ? $client : null;
    }

    /**
     * Checks that the host is a well-formed URL.
     *
     * Whether it resolves is left to the availability check: a DNS lookup would
     * reject `localhost`, IP addresses and names only known to /etc/hosts.
     */
    private static function isValidHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_URL) !== false
            && parse_url($host, PHP_URL_HOST) !== null;
    }

    /**
     * Checks that Meilisearch answers, caching the verdict for a short window.
     *
     * The verdict is keyed on the host and the key, so changing either one
     * triggers a new check.
     */
    private static function isAvailable(Client $client, string $host, string $key): bool
    {
        $cacheKey = self::PROBE_CACHE_PREFIX.md5($host.'|'.$key);
        $cached = get_transient($cacheKey);

        if ($cached !== false) {
            return $cached === 'available';
        }

        $available = self::probe($client);

        set_transient(
            $cacheKey,
            $available ? 'available' : 'unavailable',
            $available ? self::PROBE_TTL_REACHABLE : self::PROBE_TTL_UNREACHABLE
        );

        return $available;
    }

    /**
     * Calls the Meilisearch /health endpoint.
     */
    private static function probe(Client $client): bool
    {
        try {
            return ($client->health()['status'] ?? null) === 'available';
        } catch (\Throwable $e) {
            self::logError('Failed to reach Meilisearch: '.$e->getMessage());
        }

        return false;
    }

    /**
     * Logs an error message to the PHP error log.
     */
    private static function logError(string $message): void
    {
        error_log('MeiliScout: '.$message);
    }
}
