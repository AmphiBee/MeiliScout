<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings;

use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Indexables\TaxonomyIndexable;
use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\IndexSettings;
use Pollora\MeiliScout\Suggest\Definition\InvalidSuggest;
use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;

/**
 * What browsers may read from the posts and taxonomies indexes once listings
 * are on, or a suggest field is declared (design, decision A): their tenant
 * tokens search them directly, so the indexes return a list of public fields
 * rather than every one (`*`).
 *
 * - The fields a card is made of, the ones MeiliScout's queries read back
 *   (post_parent, post_name: FieldsBuilder, PhpOrder), the terms, and the
 *   meta keys some listing declares public (public_metas).
 * - `card`, the field MeiliFacets projects; its own narrower list is
 *   intersected with this one, since it starts from MeiliScout's settings.
 * - meiliscout/post/displayed_attributes keeps the last word: this list is
 *   its default, set at priority 0.
 * - The taxonomies index returns a term's own fields, never its metas
 *   (TERM_FIELDS; meiliscout/term/displayed_attributes).
 *
 * A changed list is a settings update of the index, no re-indexation: it is
 * pushed in the background as soon as a request sees it changed, and on every
 * save anyway (settings are pushed when they changed).
 */
final class PublicFields
{
    public const FIELDS = [
        'ID',
        'post_type',
        'post_title',
        'post_name',
        'post_excerpt',
        'post_parent',
        'content_text',
        'url',
        'thumbnail',
        'post_date',
        'post_date_ts',
        'terms',
        'taxonomies',
        'card',
    ];

    /**
     * What browsers may read of a term: the fields WordPress gives it, its
     * counts and its address. MeiliScout's own term queries read the ids and
     * counts back, and load the terms from the database.
     */
    public const TERM_FIELDS = [
        'term_id',
        'term_taxonomy_id',
        'taxonomy',
        'name',
        'slug',
        'description',
        'parent',
        'count',
        'tree_count',
        'url',
    ];

    /**
     * Pushes the indexes' settings once their lists changed.
     */
    public const SYNC_HOOK = 'meiliscout/listings/sync_fields';

    /**
     * The values of a field the index counts while the module runs: every
     * value a facet offers comes from a facet distribution.
     */
    public const MAX_VALUES_PER_FACET = 1000;

    /**
     * The list last seen, to notice a change without asking Meilisearch.
     */
    private const SIGNATURE = 'meiliscout/listings_fields_signature';

    /**
     * Whether the front listings module runs.
     */
    private static bool $listings = false;

    /**
     * Whether the listings module runs. Browsers also read the indexes for a
     * declared suggest field: then the indexes return the list, else every field.
     */
    public static function boot(bool $module): void
    {
        self::$listings = $module;

        add_filter('meiliscout/post/displayed_attributes', static fn (array $attributes): array => self::active() ? self::restrict($attributes) : $attributes, 0);
        add_filter('meiliscout/term/displayed_attributes', static fn (array $attributes): array => self::active() ? self::restrictTerms($attributes) : $attributes, 0);

        if ($module) {
            add_filter('meiliscout/post/max_values_per_facet', static fn (int $max) => max($max, self::MAX_VALUES_PER_FACET), 0);
        }

        add_action(self::SYNC_HOOK, [self::class, 'sync']);
        // Listings and suggest fields are declared by then (functions.php, init)
        add_action('wp_loaded', static fn () => self::watch());
    }

    /**
     * Whether browsers search the indexes: the listings run, or a suggest field is declared.
     */
    public static function active(): bool
    {
        return self::$listings || SuggestRegistry::ids() !== [];
    }

    /**
     * @param  list<string>  $attributes
     * @return list<string>
     */
    public static function restrict(array $attributes): array
    {
        // Narrowed already: not ours to widen
        return $attributes === ['*'] ? self::allowlist() : $attributes;
    }

    /**
     * @param  list<string>  $attributes
     * @return list<string>
     */
    public static function restrictTerms(array $attributes): array
    {
        return $attributes === ['*'] ? self::TERM_FIELDS : $attributes;
    }

    /**
     * The public fields, and every declared listing's public meta keys.
     *
     * @return list<string>
     */
    public static function allowlist(): array
    {
        $fields = self::FIELDS;

        foreach (DefinitionRegistry::ids() as $id) {
            try {
                foreach (DefinitionRegistry::get($id)->publicMetas as $key) {
                    $fields[] = "metas.{$key}";
                }
            } catch (InvalidListing) {
                // Its errors show in the admin; it reads nothing
            }
        }

        foreach (SuggestRegistry::ids() as $id) {
            try {
                foreach (SuggestRegistry::get($id)->publicMetas as $key) {
                    $fields[] = "metas.{$key}";
                }
            } catch (InvalidSuggest) {
                // Its errors show in the admin; it reads nothing
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Schedules a push of the settings when the list differs from the last one seen.
     */
    public static function watch(): void
    {
        $signature = md5((string) wp_json_encode(self::active() ? [...self::allowlist(), PostIndexable::maxValuesPerFacet(), ...self::TERM_FIELDS] : ['*']));
        // A site that never ran the module has the default list
        $seen = get_option(self::SIGNATURE, md5((string) wp_json_encode(['*'])));

        if ($seen === $signature) {
            return;
        }

        update_option(self::SIGNATURE, $signature, true);

        if (! wp_next_scheduled(self::SYNC_HOOK)) {
            wp_schedule_single_event(time(), self::SYNC_HOOK);
        }
    }

    /**
     * Pushes the posts and taxonomies indexables' settings, when they changed,
     * to the indexes searches read and to the ones writes go to.
     */
    public static function sync(): void
    {
        $client = ClientFactory::getClient();

        if ($client === null) {
            return;
        }

        $indexables = ['posts' => new PostIndexable, 'taxonomies' => new TaxonomyIndexable];
        foreach (apply_filters('meiliscout/indexables', [new PostIndexable, new TaxonomyIndexable]) as $candidate) {
            if ($candidate instanceof PostIndexable) {
                $indexables['posts'] = $candidate;
            } elseif ($candidate instanceof TaxonomyIndexable) {
                $indexables['taxonomies'] = $candidate;
            }
        }

        foreach ($indexables as $base => $indexable) {
            $settings = $indexable->getIndexSettings();

            foreach (array_unique([IndexNames::active($base), $indexable->getIndexName()]) as $name) {
                try {
                    // Never create an index here: a full indexation does
                    $client->getIndex($name);
                    IndexSettings::pushIfChanged($client->index($name), $name, $settings);
                } catch (\Throwable $e) {
                    error_log("MeiliScout: could not update the fields the index {$name} returns: ".$e->getMessage());
                }
            }
        }
    }
}
