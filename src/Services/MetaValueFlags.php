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
 * Recorded while posts and terms are indexed (posts schema 4, terms schema 4),
 * for the keys documents carry, apart for post and term metas:
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
 *  - empty: a value is ''. Meilisearch sorts it last, MySQL first.
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

    public const EMPTY = 'empty';

    /**
     * Options holding the flags, by object type.
     */
    private const OPTIONS = ['post' => 'meiliscout/meta_value_flags', 'term' => 'meiliscout/term_meta_value_flags'];

    /**
     * Suffix of the options a full indexation fills.
     */
    private const RUN_SUFFIX = '_run';

    /**
     * Flags noted during this request, not written yet, by object type and key.
     *
     * @var array<string, array<string, array<string, true>>>
     */
    private static array $pending = [];

    /**
     * The flags of a meta key.
     *
     * @param  'post'|'term'  $objectType  Whose meta key
     * @return array<string, true>
     */
    public static function of(string $key, string $objectType = 'post'): array
    {
        $flags = get_option(self::option($objectType), []);

        return is_array($flags) && isset($flags[$key]) && is_array($flags[$key]) ? $flags[$key] : [];
    }

    /**
     * @param  'post'|'term'  $objectType
     */
    public static function has(string $key, string $flag, string $objectType = 'post'): bool
    {
        return isset(self::of($key, $objectType)[$flag]);
    }

    /**
     * Whether Meilisearch sorts on a key as MySQL does: one value per post, not serialized,
     * not numbers mixed with text (MySQL orders them all as text), none empty.
     *
     * @param  'post'|'term'  $objectType
     */
    public static function sortable(string $key, string $objectType = 'post'): bool
    {
        $flags = self::of($key, $objectType);

        return ! isset($flags[self::MULTIPLE]) && ! isset($flags[self::STRUCTURED]) && ! isset($flags[self::EMPTY])
            && ! (isset($flags[self::NUMERIC]) && isset($flags[self::NON_NUMERIC]));
    }

    /**
     * Notes the values of a key, as one post (or term) has them, unserialized.
     *
     * @param  list<mixed>  $values
     * @param  'post'|'term'  $objectType
     */
    public static function note(string $key, array $values, string $objectType = 'post'): void
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

                if ($value === '') {
                    $flags[self::EMPTY] = true;
                }
            }
        }

        if ($flags === []) {
            return;
        }

        if (self::$pending === [] && function_exists('add_action')) {
            add_action('shutdown', [self::class, 'flush']);
        }

        self::$pending[$objectType][$key] = [...(self::$pending[$objectType][$key] ?? []), ...$flags];
    }

    /**
     * Writes the flags noted. Called after each batch, and at the end of the request.
     */
    public static function flush(): void
    {
        $pending = self::$pending;
        self::$pending = [];

        foreach ($pending as $objectType => $flags) {
            $option = self::option($objectType);
            self::merge($option, $flags, true);

            if (get_option($option.self::RUN_SUFFIX, null) !== null) {
                self::merge($option.self::RUN_SUFFIX, $flags, false);
            }
        }
    }

    /**
     * A full indexation starts: the flags it notes will replace the current ones.
     */
    public static function startRun(): void
    {
        self::flush();

        foreach (self::OPTIONS as $option) {
            update_option($option.self::RUN_SUFFIX, [], false);
        }
    }

    /**
     * A full indexation is done: its flags are the current ones.
     *
     * @param  list<'post'|'term'>  $objectTypes  The metas it indexed: the posts', the terms', or both
     */
    public static function finishRun(array $objectTypes = ['post', 'term']): void
    {
        self::flush();

        foreach (self::OPTIONS as $objectType => $option) {
            $run = get_option($option.self::RUN_SUFFIX, null);
            delete_option($option.self::RUN_SUFFIX);

            if (is_array($run) && in_array($objectType, $objectTypes, true)) {
                update_option($option, $run);
            }
        }
    }

    private static function option(string $objectType): string
    {
        return self::OPTIONS[$objectType] ?? self::OPTIONS['post'];
    }

    /**
     * @param  array<string, array<string, true>>  $flags
     */
    private static function merge(string $option, array $flags, bool $autoload): void
    {
        $saved = get_option($option, []);
        $saved = is_array($saved) ? $saved : [];
        $merged = $saved;

        foreach ($flags as $key => $keyFlags) {
            $merged[$key] = [...(is_array($saved[$key] ?? null) ? $saved[$key] : []), ...$keyFlags];
        }

        if ($merged !== $saved) {
            update_option($option, $merged, $autoload);
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
