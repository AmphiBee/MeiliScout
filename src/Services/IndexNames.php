<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Pollora\MeiliScout\Config\Config;

use function apply_filters;
use function delete_option;
use function get_current_blog_id;
use function get_option;
use function home_url;
use function is_multisite;
use function update_option;

/**
 * Names the Meilisearch indexes, and tracks the ones searches read from.
 *
 * Indexes are prefixed, so that several sites (or a site and its staging copy)
 * can share a Meilisearch instance. The prefix comes from MEILI_INDEX_PREFIX,
 * or from the site's domain.
 *
 * The documents' format has a version. Searches read the *active* indexes
 * recorded by the last full indexation, in the format they were built with,
 * until a full indexation builds the *target* indexes in the current format
 * and activates them. A site indexed before 2.0 keeps searching its legacy
 * `posts` index in the meantime.
 */
final class IndexNames
{
    /**
     * Version of the documents' format.
     *
     * 1: terms in a flat `terms` list. 2: terms grouped in `taxonomies.<taxonomy>`.
     */
    public const SCHEMA_VERSION = 2;

    /**
     * The indexes of the plugin, by base name.
     */
    public const BASES = ['posts', 'taxonomies'];

    private const ACTIVE_OPTION = 'meiliscout/active_indexes';

    private const SCHEMA_OPTION = 'meiliscout/schema_version';

    private const LEGACY_OPTION = 'meiliscout/legacy_indexes';

    /**
     * Option written by every full indexation since 1.0: a site that has it was indexed.
     */
    private const INDEXED_OPTION = 'meiliscout/last_indexing_structure';

    /**
     * Indexes temporarily written under another name, while they are rebuilt.
     *
     * @var array<string, string>
     */
    private static array $redirects = [];

    /**
     * The prefix of every index name, separator included ('' when there is none).
     */
    public static function prefix(): string
    {
        $configured = Config::get('meili_index_prefix');
        $prefix = is_string($configured) && $configured !== '' ? $configured : self::sitePrefix();
        $prefix = (string) apply_filters('meiliscout/index_prefix', $prefix);

        $prefix = trim((string) preg_replace('/[^a-z0-9_-]+/', '_', strtolower($prefix)), '_-');

        return $prefix === '' ? '' : $prefix.'_';
    }

    /**
     * The name an index is written to.
     */
    public static function target(string $base): string
    {
        $name = self::name($base);

        return self::$redirects[$name] ?? $name;
    }

    /**
     * The name of an index in the current format, whatever it is written to right now.
     */
    public static function name(string $base): string
    {
        return self::prefix().$base;
    }

    /**
     * The name an index is read from.
     */
    public static function active(string $base): string
    {
        $active = get_option(self::ACTIVE_OPTION, []);

        if (is_array($active) && isset($active[$base])) {
            return (string) $active[$base];
        }

        // Indexed before 2.0: the legacy, unprefixed index
        return self::wasIndexedBefore() ? $base : self::name($base);
    }

    /**
     * The version of the format the active indexes were built with.
     */
    public static function activeSchema(): int
    {
        $version = get_option(self::SCHEMA_OPTION, null);

        if ($version !== null && $version !== false) {
            return (int) $version;
        }

        return self::wasIndexedBefore() ? 1 : self::SCHEMA_VERSION;
    }

    /**
     * Whether a full indexation is needed to move searches to the target indexes.
     */
    public static function migrationPending(): bool
    {
        if (self::activeSchema() < self::SCHEMA_VERSION) {
            return true;
        }

        foreach (self::BASES as $base) {
            if (self::active($base) !== self::name($base)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The index a write to $indexName must be copied to, so that searches still
     * reading a previous index see it too; null when there is none.
     */
    public static function mirrorOf(string $indexName): ?string
    {
        foreach (self::BASES as $base) {
            if (self::name($base) === $indexName) {
                $active = self::active($base);

                return $active !== $indexName ? $active : null;
            }
        }

        return null;
    }

    /**
     * Moves searches to the target indexes, once a full indexation built them.
     *
     * The indexes searches leave are recorded, for the admin to delete them.
     */
    public static function activate(): void
    {
        $legacy = self::legacyIndexes();
        $active = [];

        foreach (self::BASES as $base) {
            $previous = self::active($base);
            $active[$base] = self::name($base);

            if ($previous !== $active[$base]) {
                $legacy[] = $previous;
            }
        }

        update_option(self::ACTIVE_OPTION, $active);
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
        update_option(self::LEGACY_OPTION, array_values(array_unique(array_diff($legacy, $active))), false);
    }

    /**
     * Records the target indexes as the active ones on a site never indexed.
     *
     * Called before a full indexation writes its first option: that option is
     * how a site indexed before 2.0 is told apart, and without this a new site
     * would take the unprefixed names for legacy indexes to migrate from.
     */
    public static function adoptIfNew(): void
    {
        if (get_option(self::ACTIVE_OPTION, null) !== null || self::wasIndexedBefore()) {
            return;
        }

        update_option(self::ACTIVE_OPTION, array_combine(self::BASES, array_map([self::class, 'name'], self::BASES)));
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
    }

    /**
     * The indexes searches no longer read, left for the admin to delete.
     *
     * @return list<string>
     */
    public static function legacyIndexes(): array
    {
        $legacy = get_option(self::LEGACY_OPTION, []);

        return is_array($legacy) ? array_values($legacy) : [];
    }

    /**
     * Forgets the legacy indexes, once deleted.
     */
    public static function forgetLegacyIndexes(): void
    {
        delete_option(self::LEGACY_OPTION);
    }

    /**
     * Writes an index under another name until the redirect is lifted.
     */
    public static function redirect(string $indexName, string $to): void
    {
        self::$redirects[$indexName] = $to;
    }

    /**
     * Lifts a redirect.
     */
    public static function lift(string $indexName): void
    {
        unset(self::$redirects[$indexName]);
    }

    /**
     * The site's domain, plus its id on a multisite network.
     */
    private static function sitePrefix(): string
    {
        $prefix = (string) parse_url(home_url(), PHP_URL_HOST);

        if (is_multisite()) {
            $prefix .= '_'.get_current_blog_id();
        }

        return $prefix;
    }

    private static function wasIndexedBefore(): bool
    {
        $structure = get_option(self::INDEXED_OPTION, false);

        return $structure !== false && $structure !== null;
    }
}
