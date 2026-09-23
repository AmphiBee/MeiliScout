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
     * The Meilisearch client instance.
     */
    private static ?Client $instance = null;

    /**
     * The Meilisearch search client instance.
     */
    private static ?Client $searchInstance = null;

    /**
     * How long a successful health check is cached, in seconds.
     */
    private const HEALTH_CACHE_TTL = 300;

    /**
     * How long a failed health check is cached, in seconds.
     */
    private const HEALTH_CACHE_TTL_FAILURE = 60;

    /**
     * Gets the Meilisearch client instance.
     * Creates it if it doesn't exist.
     */
    public static function getClient(): ?Client
    {
        if (self::$instance === null) {
            $host = Config::get('meili_host');
            $key = Config::get('meili_key');

            if (! self::isValidHost($host)) {
                self::logError("Invalid Meilisearch host: {$host}");

                return null;
            }

            if (empty($key)) {
                self::logError('Meilisearch API key is missing.');

                return null;
            }

            try {
                $client = new Client($host, $key);

                if (! self::isAvailable($client, $host)) {
                    self::logError('API key does not have required permissions.');

                    return null;
                }

                self::$instance = $client;
            } catch (\Throwable $e) {
                self::logError('Failed to connect to Meilisearch: '.$e->getMessage());

                return null;
            }
        }

        return self::$instance;
    }

    public static function getSearchClient(): ?Client
    {
        if (self::$searchInstance === null) {
            $host = Config::get('meili_host');
            $key = Config::get('meili_search_key');

            if (! self::isValidHost($host)) {
                self::logError("Invalid Meilisearch host: {$host}");

                return null;
            }

            if (empty($key)) {
                self::logError('Meilisearch API search key is missing.');

                return null;
            }

            try {
                $client = new Client($host, $key);
                if (! self::isAvailable($client, $host)) {
                    self::logError('API key does not have required permissions.');

                    return null;
                }

                self::$searchInstance = $client;
            } catch (\Throwable $e) {
                self::logError('Failed to connect to Meilisearch: '.$e->getMessage());

                return null;
            }
        }

        return self::$searchInstance;
    }

    public static function isConfigured(): bool
    {
        return ! (is_null(self::getClient()) && Config::get('meili_host') && Config::get('meili_key'));
    }

    public static function isSearchConfigured(): bool
    {
        return self::getSearchClient() !== null;
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

        // La résolution DNS est vérifiée avec le health check, dont le résultat est mis en cache
        $hostParts = parse_url($host);
        if (! isset($hostParts['host'])) {
            return false;
        }

        return true;
    }

    /**
     * Checks if the Meilisearch instance is reachable.
     *
     * The result is cached per host so that the DNS lookup and the HTTP call
     * to /health are not repeated on every request.
     */
    private static function isAvailable(Client $client, string $host): bool
    {
        $cacheKey = 'meiliscout_health_'.md5($host);
        $cached = get_transient($cacheKey);

        if ($cached !== false) {
            return $cached === 'available';
        }

        $available = self::checkAvailability($client, $host);

        set_transient(
            $cacheKey,
            $available ? 'available' : 'unavailable',
            $available ? self::HEALTH_CACHE_TTL : self::HEALTH_CACHE_TTL_FAILURE
        );

        return $available;
    }

    /**
     * Resolves the host and calls the Meilisearch /health endpoint.
     */
    private static function checkAvailability(Client $client, string $host): bool
    {
        $hostname = parse_url($host, PHP_URL_HOST);

        if (! $hostname || ! checkdnsrr($hostname, 'A')) {
            self::logError("Unable to resolve Meilisearch host: {$host}");

            return false;
        }

        try {
            return $client->health()['status'] === 'available';
        } catch (ApiException $e) {
            self::logError('Failed to fetch API keys: '.$e->getMessage());
        } catch (\Throwable $e) {
            self::logError('Failed to connect to Meilisearch: '.$e->getMessage());
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
