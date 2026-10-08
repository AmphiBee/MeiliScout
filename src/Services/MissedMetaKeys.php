<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Pollora\MeiliScout\Config\Settings;

use function add_action;

/**
 * Remembers the meta keys queries filtered on that are not indexed, for the admin to offer them.
 *
 * Front-end queries meet them on every page view: they are kept in memory and
 * written once, at the end of the request, and only when one is new.
 */
final class MissedMetaKeys
{
    public const SETTING = 'non_indexable_meta_keys';

    /**
     * Keys missed during this request.
     *
     * @var array<string, true>
     */
    private static array $pending = [];

    public static function record(string $key): void
    {
        if (self::$pending === []) {
            add_action('shutdown', [self::class, 'flush']);
        }

        self::$pending[$key] = true;
    }

    /**
     * Adds this request's keys to the saved ones. Hooked on shutdown.
     */
    public static function flush(): void
    {
        if (self::$pending === []) {
            return;
        }

        $saved = (array) Settings::get(self::SETTING, []);
        $all = array_values(array_unique([...$saved, ...array_keys(self::$pending)]));
        self::$pending = [];

        if (count($all) !== count($saved)) {
            Settings::save(self::SETTING, $all);
        }
    }

    /**
     * The keys missed during this request, not written yet. For tests.
     *
     * @return list<string>
     */
    public static function pending(): array
    {
        return array_keys(self::$pending);
    }

    /**
     * Forgets the keys of this request not written yet. For tests.
     */
    public static function reset(): void
    {
        self::$pending = [];
    }
}
