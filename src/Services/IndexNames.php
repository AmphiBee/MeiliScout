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
 * Each index's documents have a format, with its own version. Searches read
 * the *active* indexes recorded by the last full indexation, in the format
 * they were built with, until a full indexation builds the *target* indexes
 * in the current format and activates them. A site indexed before 2.0 keeps
 * searching its legacy `posts` index in the meantime.
 */
final class IndexNames
{
    /**
     * Version of the documents' format, by index.
     *
     * Posts: 1, terms in a flat `terms` list. 2, terms grouped in
     * `taxonomies.<taxonomy>`. 3, fields for WP_Query arguments (ids and
     * counts as numbers, has_password, date timestamps and parts, post_title_sort).
     * 4, every value of a meta key (a list when there are several, '' kept),
     * title and comment statuses filterable, attachments' mime groups and
     * parent statuses.
     *
     * Terms: up to 3, the term's fields as WordPress gives them. 4, the fields of
     * term queries (ids and counts as numbers, name_sort, description_sort,
     * tree_count) and every value of a term meta key.
     *
     * Versions were shared by every index up to 3: an index moves to the next
     * one on its own, and only its own indexation is needed.
     */
    public const SCHEMA_VERSIONS = ['posts' => 5, 'taxonomies' => 4];

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
     * The current version of an index's format.
     */
    public static function schemaVersion(string $base): int
    {
        return self::SCHEMA_VERSIONS[$base] ?? 1;
    }

    /**
     * The version of the format an active index was built with.
     */
    public static function activeSchema(string $base = 'posts'): int
    {
        $versions = get_option(self::SCHEMA_OPTION, null);

        // One version for every index, as recorded up to schema 3
        if (is_numeric($versions)) {
            return (int) $versions;
        }

        if (is_array($versions) && isset($versions[$base])) {
            return (int) $versions[$base];
        }

        if (is_array($versions) || self::wasIndexedBefore()) {
            // An index added since, or a site indexed before 2.0
            return is_array($versions) ? 0 : 1;
        }

        return self::schemaVersion($base);
    }

    /**
     * The indexes a full indexation needs to build to move searches to their target, in the current format.
     *
     * @return list<string> Base names
     */
    public static function pendingBases(): array
    {
        return array_values(array_filter(
            self::BASES,
            static fn (string $base) => self::activeSchema($base) < self::schemaVersion($base) || self::active($base) !== self::name($base)
        ));
    }

    /**
     * Whether a full indexation is needed to move searches to the target indexes.
     */
    public static function migrationPending(): bool
    {
        return self::pendingBases() !== [];
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
     * Moves searches to the target indexes a full indexation built, in the current format.
     *
     * The other indexes stay as they are. The indexes searches leave are
     * recorded, for the admin to delete them.
     *
     * @param  list<string>  $bases  The indexes built, by base name
     */
    public static function activate(array $bases = self::BASES): void
    {
        $legacy = self::legacyIndexes();
        $active = [];
        $versions = [];

        foreach (self::BASES as $base) {
            $previous = self::active($base);
            $built = in_array($base, $bases, true);
            $active[$base] = $built ? self::name($base) : $previous;
            $versions[$base] = $built ? self::schemaVersion($base) : self::activeSchema($base);

            if ($previous !== $active[$base]) {
                $legacy[] = $previous;
            }
        }

        update_option(self::ACTIVE_OPTION, $active);
        update_option(self::SCHEMA_OPTION, $versions);
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
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSIONS);
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
