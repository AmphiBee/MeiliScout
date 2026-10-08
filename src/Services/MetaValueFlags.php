<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use function add_action;
use function delete_option;
use function get_option;
use function update_option;

/**
 * What the indexed values of each meta key are like, for queries to tell when Meilisearch compares them as MySQL does.
 *
 * Recorded while posts are indexed (schema 4), for the keys documents carry:
 *  - multiple: a post has several values for the key (a list in the document).
 *    Meilisearch matches a list when one of its values matches, as MySQL
 *    matches a post when one of its rows does; but a negation (!=, NOT IN)
 *    excludes the list when one value matches, where MySQL keeps the post
 *    for its other rows. Sorting on it has no order MySQL would follow either.
 *  - structured: a value is a serialized array, which documents hold
 *    unserialized. MySQL compares the serialized text.
 *  - non_numeric: a value is no number ('' included). MySQL casts it to 0
 *    in a numeric comparison or sort; Meilisearch never compares it to a number.
 *  - numeric: a value is a number. With non_numeric, the key mixes both:
 *    MySQL sorts them all as text, Meilisearch the numbers first.
 *
 * A flag is only ever added by real-time indexing. A full indexation records
 * them anew, and replaces the previous ones once it is done.
 */
final class MetaValueFlags
{
    public const MULTIPLE = 'multiple';

    public const STRUCTURED = 'structured';

    public const NON_NUMERIC = 'non_numeric';

    public const NUMERIC = 'numeric';

    private const OPTION = 'meiliscout/meta_value_flags';

    private const RUN_OPTION = 'meiliscout/meta_value_flags_run';

    /**
     * Flags noted during this request, not written yet.
     *
     * @var array<string, array<string, true>>
     */
    private static array $pending = [];

    /**
     * The flags of a meta key.
     *
     * @return array<string, true>
     */
    public static function of(string $key): array
    {
        $flags = get_option(self::OPTION, []);

        return is_array($flags) && isset($flags[$key]) && is_array($flags[$key]) ? $flags[$key] : [];
    }

    public static function has(string $key, string $flag): bool
    {
        return isset(self::of($key)[$flag]);
    }

    /**
     * Notes the values of a key, as one post has them (unserialized).
     *
     * @param  list<mixed>  $values
     */
    public static function note(string $key, array $values): void
    {
        $flags = [];

        if (count($values) > 1) {
            $flags[self::MULTIPLE] = true;
        }

        foreach ($values as $value) {
            if (is_array($value) || is_object($value)) {
                $flags[self::STRUCTURED] = true;
            } elseif (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $flags[self::NUMERIC] = true;
            } else {
                $flags[self::NON_NUMERIC] = true;
            }
        }

        if ($flags === []) {
            return;
        }

        if (self::$pending === [] && function_exists('add_action')) {
            add_action('shutdown', [self::class, 'flush']);
        }

        self::$pending[$key] = [...(self::$pending[$key] ?? []), ...$flags];
    }

    /**
     * Writes the flags noted. Called after each batch, and at the end of the request.
     */
    public static function flush(): void
    {
        $pending = self::$pending;
        self::$pending = [];

        if ($pending === []) {
            return;
        }

        self::merge(self::OPTION, $pending);

        if (get_option(self::RUN_OPTION, null) !== null) {
            self::merge(self::RUN_OPTION, $pending);
        }
    }

    /**
     * A full indexation starts: the flags it notes will replace the current ones.
     */
    public static function startRun(): void
    {
        self::flush();
        update_option(self::RUN_OPTION, [], false);
    }

    /**
     * A full indexation is done: its flags are the current ones.
     */
    public static function finishRun(): void
    {
        self::flush();
        $run = get_option(self::RUN_OPTION, null);

        if (is_array($run)) {
            update_option(self::OPTION, $run);
            delete_option(self::RUN_OPTION);
        }
    }

    /**
     * @param  array<string, array<string, true>>  $flags
     */
    private static function merge(string $option, array $flags): void
    {
        $saved = get_option($option, []);
        $saved = is_array($saved) ? $saved : [];
        $merged = $saved;

        foreach ($flags as $key => $keyFlags) {
            $merged[$key] = [...(is_array($saved[$key] ?? null) ? $saved[$key] : []), ...$keyFlags];
        }

        if ($merged !== $saved) {
            update_option($option, $merged, $option === self::OPTION);
        }
    }

    /**
     * Forgets the flags noted and not written. For tests.
     */
    public static function reset(): void
    {
        self::$pending = [];
    }
}
