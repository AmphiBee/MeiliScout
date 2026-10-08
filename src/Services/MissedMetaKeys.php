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
     * The term meta keys term queries missed.
     */
    public const TERM_SETTING = 'non_indexable_term_meta_keys';

    /**
     * Keys missed during this request, by setting.
     *
     * @var array<string, array<string, true>>
     */
    private static array $pending = [];

    /**
     * @param  'post'|'term'  $objectType  Whose meta key
     */
    public static function record(string $key, string $objectType = 'post'): void
    {
        if (self::$pending === []) {
            add_action('shutdown', [self::class, 'flush']);
        }

        self::$pending[$objectType === 'term' ? self::TERM_SETTING : self::SETTING][$key] = true;
    }

    /**
     * Adds this request's keys to the saved ones. Hooked on shutdown.
     */
    public static function flush(): void
    {
        $pending = self::$pending;
        self::$pending = [];

        foreach ($pending as $setting => $keys) {
            $saved = (array) Settings::get($setting, []);
            $all = array_values(array_unique([...$saved, ...array_keys($keys)]));

            if (count($all) !== count($saved)) {
                Settings::save($setting, $all);
            }
        }
    }

    /**
     * The keys missed during this request, not written yet. For tests.
     *
     * @param  'post'|'term'  $objectType
     * @return list<string>
     */
    public static function pending(string $objectType = 'post'): array
    {
        return array_keys(self::$pending[$objectType === 'term' ? self::TERM_SETTING : self::SETTING] ?? []);
    }

    /**
     * Forgets the keys of this request not written yet. For tests.
     */
    public static function reset(): void
    {
        self::$pending = [];
    }
}
