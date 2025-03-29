<?php

namespace Pollora\MeiliScout\Config;

/**
 * Configuration management class that handles configuration retrieval from different sources.
 */
class Config
{
    /**
     * Retrieves a configuration value from environment variables, constants, or database.
     *
     * @param string $key     The configuration key to retrieve
     * @param mixed  $default The default value to return if the key is not found
     * @return mixed The configuration value or default if not found
     */
    public static function get(string $key, $default = null)
    {
        $constKey = strtoupper($key);
        if (getenv($constKey) !== false) {
            return getenv($constKey);
        }

        if (defined($constKey)) {
            return constant($key);
        }

        return self::getFromDatabase($key, $default);
    }

    /**
     * Checks if a configuration value is read-only (defined in environment or constants).
     *
     * @param string $key The configuration key to check
     * @return bool True if the configuration is read-only, false otherwise
     */
    public static function isReadOnly(string $key): bool
    {
        return getenv($key) !== false || defined($key);
    }

    /**
     * Retrieves a configuration value from the WordPress database.
     *
     * @param string $key     The configuration key to retrieve
     * @param mixed  $default The default value to return if the key is not found
     * @return mixed The configuration value from database or default if not found
     */
    protected static function getFromDatabase(string $key, $default = null)
    {
        $value = Settings::get($key, $default);
        return $value !== false ? $value : $default;
    }
}
