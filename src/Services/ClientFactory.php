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

                if (! self::isAvailable($client)) {
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
