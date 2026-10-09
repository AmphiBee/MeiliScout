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

/**
 * What browsers may read from the posts index once listings are on (design,
 * decision A): their tenant tokens search it directly, so the index returns a
 * list of public fields rather than every one (`*`).
 *
 * - The fields a card is made of, the ones MeiliScout's queries read back
 *   (post_parent, post_name: FieldsBuilder, PhpOrder), the terms, and the
 *   meta keys some listing declares public (public_metas).
 * - `card`, the field MeiliFacets projects; its own narrower list is
 *   intersected with this one, since it starts from MeiliScout's settings.
 * - meiliscout/post/displayed_attributes keeps the last word: this list is
 *   its default, set at priority 0.
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
        'post_date',
        'post_date_ts',
        'terms',
        'taxonomies',
        'card',
    ];

    /**
     * Pushes the posts index's settings once its list changed.
     */
    public const SYNC_HOOK = 'meiliscout/listings/sync_fields';

    /**
     * The list last seen, to notice a change without asking Meilisearch.
     */
    private const SIGNATURE = 'meiliscout/listings_fields_signature';

    /**
     * Whether the module runs: then the index returns the list, else every field.
     */
    public static function boot(bool $module): void
    {
        if ($module) {
            add_filter('meiliscout/post/displayed_attributes', [self::class, 'restrict'], 0);
        }

        add_action(self::SYNC_HOOK, [self::class, 'sync']);
        // Listings are declared by then (functions.php, init)
        add_action('wp_loaded', static fn () => self::watch($module));
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

        return array_values(array_unique($fields));
    }

    /**
     * Schedules a push of the settings when the list differs from the last one seen.
     */
    public static function watch(bool $module): void
    {
        $signature = md5((string) wp_json_encode($module ? self::allowlist() : ['*']));
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
     * Pushes the posts indexable's settings, when they changed, to the index
     * searches read and to the one writes go to.
     */
    public static function sync(): void
    {
        $client = ClientFactory::getClient();

        if ($client === null) {
            return;
        }

        $indexable = new PostIndexable;
        foreach (apply_filters('meiliscout/indexables', [new PostIndexable, new TaxonomyIndexable]) as $candidate) {
            if ($candidate instanceof PostIndexable) {
                $indexable = $candidate;
                break;
            }
        }

        $settings = $indexable->getIndexSettings();

        foreach (array_unique([IndexNames::active('posts'), $indexable->getIndexName()]) as $name) {
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
