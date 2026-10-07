<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config;

/**
 * How content saved in WordPress reaches Meilisearch.
 *
 * Set in the admin, unless MEILISCOUT_ASYNC_INDEXING is defined in the
 * environment or as a constant: it then wins, and the admin shows it locked.
 */
final class RealtimeIndexing
{
    /**
     * Once per item, at the end of the request that changed it.
     */
    public const SHUTDOWN = 'shutdown';

    /**
     * Queued, and sent by WP-Cron.
     */
    public const ASYNC = 'async';

    /**
     * Never: only full indexations update the indexes.
     */
    public const OFF = 'off';

    public const MODES = [self::SHUTDOWN, self::ASYNC, self::OFF];

    private const ENV_KEY = 'meiliscout_async_indexing';

    private const SETTING = 'realtime_indexing';

    public static function mode(): string
    {
        if (self::isLocked()) {
            return filter_var(Config::get(self::ENV_KEY), FILTER_VALIDATE_BOOLEAN) ? self::ASYNC : self::SHUTDOWN;
        }

        $mode = Settings::get(self::SETTING, self::SHUTDOWN);

        return in_array($mode, self::MODES, true) ? $mode : self::SHUTDOWN;
    }

    /**
     * Whether the environment sets the mode.
     */
    public static function isLocked(): bool
    {
        return Config::isReadOnly(self::ENV_KEY);
    }

    public static function save(string $mode): void
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("Unknown real-time indexing mode: {$mode}");
        }

        Settings::save(self::SETTING, $mode);
    }
}
