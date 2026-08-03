<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Pollora\MeiliScout\Config\Config;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating and managing the Meilisearch client instance.
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
     * The Meilisearch client instance.
     */
    private static ?Client $instance = null;

    /**
     * Gets the Meilisearch client instance.
     * Creates it if it doesn't exist.
     */
    public static function getClient(): ?Client
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = Config::get('meili_host');
        $key = Config::get('meili_key');

        if (empty($key)) {
            self::logError('Meilisearch API key is missing.');

            return null;
        }

        if (! self::isReachable($host, $key)) {
            return null;
        }

        try {
            self::$instance = new Client($host, $key);
        } catch (\Throwable $e) {
            self::logError('Failed to connect to Meilisearch: '.$e->getMessage());

            return null;
        }

        return self::$instance;
    }

    /**
     * Verifies that the host resolves and that Meilisearch answers, caching the
     * verdict for a short window.
     *
     * Both checks are expensive and neither used to be cached: isValidHost()
     * does a synchronous DNS lookup and isAvailable() an HTTP round trip. Since
     * the container builds the client eagerly, every single request paid for
     * both — including requests that never search anything, such as a GraphQL
     * query on a decoupled front end.
     */
    private static function isReachable(?string $host, string $key): bool
    {
        $cacheKey = self::PROBE_CACHE_PREFIX.md5((string) $host.'|'.$key);
        $cached = function_exists('get_transient') ? get_transient($cacheKey) : false;

        if ($cached === 'reachable') {
            return true;
        }

        if ($cached === 'unreachable') {
            return false;
        }

        $reachable = self::probe($host, $key);

        if (function_exists('set_transient')) {
            set_transient(
                $cacheKey,
                $reachable ? 'reachable' : 'unreachable',
                $reachable ? self::PROBE_TTL_REACHABLE : self::PROBE_TTL_UNREACHABLE
            );
        }

        return $reachable;
    }

    /**
     * Runs the actual host and availability checks.
     */
    private static function probe(?string $host, string $key): bool
    {
        if (! self::isValidHost($host)) {
            self::logError("Invalid Meilisearch host: {$host}");

            return false;
        }

        try {
            if (! self::isAvailable(new Client($host, $key))) {
                self::logError('API key does not have required permissions.');

                return false;
            }
        } catch (\Throwable $e) {
            self::logError('Failed to connect to Meilisearch: '.$e->getMessage());

            return false;
        }

        return true;
    }

    public static function isConfigured(): bool
    {
        return ! (is_null(self::getClient()) && Config::get('meili_host') && Config::get('meili_key'));
    }

    /**
     * Checks if the Meilisearch host is valid.
     */
    private static function isValidHost(?string $host): bool
    {
        if (empty($host)) {
            return false;
        }

        // Vérifie si l'URL est bien formatée
        if (! filter_var($host, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Vérifie si l'hôte peut être résolu
        $hostParts = parse_url($host);
        if (! isset($hostParts['host']) || ! checkdnsrr($hostParts['host'], 'A')) {
            return false;
        }

        return true;
    }

    /**
     * Checks if the API key has required permissions.
     */
    private static function isAvailable(Client $client): bool
    {
        try {
            return $client->health()['status'] === 'available';
        } catch (ApiException $e) {
            self::logError('Failed to fetch API keys: '.$e->getMessage());
        }

        return false;
    }

    /**
     * Logs an error message.
     */
    private static function logError(string $message): void
    {
        if (class_exists(LoggerInterface::class)) {
            /** @var LoggerInterface $logger */
            $logger = app(LoggerInterface::class);
            $logger->error($message);
        }
    }
}
