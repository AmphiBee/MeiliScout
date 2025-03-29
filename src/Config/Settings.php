<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config;

/**
 * Handles WordPress-specific settings storage and retrieval for MeiliScout.
 */
class Settings
{
    /**
     * Formats the configuration key by adding the plugin prefix and removing 'MEILI_' if present.
     *
     * @param string $key The configuration key to format
     * @return string The formatted configuration key
     */
    private static function formatKey(string $key): string
    {
        return 'meiliscout/'.$key;
    }

    /**
     * Saves a configuration value to the WordPress database.
     *
     * @param string $key   The configuration key to save
     * @param mixed  $value The value to save
     * @return void
     */
    public static function save(string $key, $value)
    {
        update_option(self::formatKey($key), $value);
    }

    /**
     * Retrieves a configuration value from the WordPress database.
     *
     * @param string $key     The configuration key to retrieve
     * @param mixed  $default The default value to return if the key is not found
     * @return mixed The configuration value or default if not found
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return get_option(self::formatKey($key), $default);
    }
}
