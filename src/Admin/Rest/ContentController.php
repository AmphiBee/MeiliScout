<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Admin\Rest;

use Pollora\MeiliScout\Config\SearchableAttributes;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Fields\FieldRegistry;
use Pollora\MeiliScout\Fields\MetaField;
use Pollora\MeiliScout\Search\SearchWeights;
use Pollora\MeiliScout\Search\WeightedSearch;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Indexables\TaxonomyIndexable;
use Pollora\MeiliScout\Services\MissedMetaKeys;
use Pollora\MeiliScout\Services\Indexer;
use Pollora\MeiliScout\Services\MetaKeyCatalog;
use WP_REST_Request;
use WP_REST_Response;

use function get_post_types;
use function get_taxonomies;
use function wp_count_posts;
use function wp_count_terms;

/**
 * What gets indexed: post types, taxonomies, meta keys of posts and of terms, and the fields searches look into.
 */
final class ContentController extends Controller
{
    /**
     * Meta keys the search field lists at most.
     */
    private const META_KEYS_LISTED = 20;

    /**
     * WordPress's own bookkeeping keys: never suggested as raw keys (one can still type them).
     */
    private const INTERNAL_PREFIXES = ['_wp_', '_oembed_', '_edit_', '_menu_item_', '_thumbnail_id'];

    public function __construct(
        private readonly MetaKeyCatalog $catalog = new MetaKeyCatalog,
    ) {}

    public function registerRoutes(): void
    {
        $this->route('/content', 'GET', [$this, 'show']);
        $this->route('/content', 'POST', [$this, 'update']);
        $this->route('/meta-keys', 'GET', [$this, 'metaKeys']);
        $this->route('/content/weights/preview', 'POST', [$this, 'previewWeights']);
    }

    public function show(): WP_REST_Response
    {
        return $this->respond($this->payload());
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $postTypes = array_values(array_intersect($this->strings($request, 'post_types'), array_keys($this->postTypes())));
        $taxonomies = array_values(array_intersect($this->strings($request, 'taxonomies'), array_keys($this->taxonomies())));
        $metaKeys = array_values(array_unique($this->strings($request, 'meta_keys')));

        Settings::save('indexed_post_types', $postTypes);
        Settings::save('indexed_taxonomies', $taxonomies);
        Settings::save('indexed_meta_keys', $metaKeys);

        if ($request->has_param('term_meta_keys')) {
            $termMetaKeys = array_values(array_unique($this->strings($request, 'term_meta_keys')));
            Settings::save(TaxonomyIndexable::META_KEYS_SETTING, $termMetaKeys);
            $this->forgetMissed(MissedMetaKeys::TERM_SETTING, $termMetaKeys);
        }

        if ($request->has_param('index_private')) {
            Settings::save(PostIndexable::INDEX_PRIVATE, (bool) $request->get_param('index_private'));
        }

        $this->forgetMissed(MissedMetaKeys::SETTING, $metaKeys);

        // After the meta keys: a meta key can be weighed once it is indexed
        if ($request->has_param('weights')) {
            $weights = $request->get_param('weights');
            is_array($weights) && $weights !== [] ? SearchWeights::save($weights) : delete_option('meiliscout/'.SearchWeights::SETTING);
        } elseif ($request->has_param('searchable')) {
            SearchableAttributes::save($this->strings($request, 'searchable'));
        }

        return $this->respond($this->payload());
    }

    /**
     * The best posts of a type for a search with weights that are not saved yet, and the weight that ranked each.
     */
    public function previewWeights(WP_REST_Request $request): WP_REST_Response
    {
        $type = sanitize_key((string) $request->get_param('type'));
        $weights = $request->get_param('weights');
        $client = ClientFactory::getClient();

        if ($client === null || ! in_array($type, SearchWeights::indexedTypes(), true)) {
            return $this->respond(['hits' => []]);
        }

        try {
            $hits = WeightedSearch::preview($client, IndexNames::active('posts'), $type, sanitize_text_field((string) $request->get_param('q')), is_array($weights) ? $weights : SearchWeights::profile($type));
        } catch (\Throwable $e) {
            return $this->respond(['hits' => [], 'error' => $e->getMessage()]);
        }

        return $this->respond(['hits' => $hits]);
    }

    /**
     * Keys now indexed are no longer missed by queries.
     *
     * @param  list<string>  $indexed
     */
    private function forgetMissed(string $setting, array $indexed): void
    {
        $missed = (array) Settings::get($setting, []);
        $stillMissed = array_values(array_diff($missed, $indexed));

        if ($stillMissed !== $missed) {
            Settings::save($setting, $stillMissed);
        }
    }

    /**
     * The fields to add, matching a search: the named fields first (ACF, Meta
     * Box, WooCommerce, declared ones), then the raw meta keys of the content.
     * At most META_KEYS_LISTED, however many match: the whole list is never sent.
     *
     * With kind=term, the meta keys of the terms of the given taxonomies.
     * With source=acf (…), only the fields of that source.
     */
    public function metaKeys(WP_REST_Request $request): WP_REST_Response
    {
        $isTerm = $request->get_param('kind') === 'term';
        $search = strtolower(sanitize_text_field((string) $request->get_param('search')));
        $exclude = $this->strings($request, 'exclude');

        if ($isTerm) {
            return $this->respond($this->termKeys($search, $exclude, $this->strings($request, 'taxonomies') ?: (array) Settings::get('indexed_taxonomies', [])));
        }

        $scope = $this->strings($request, 'post_types') ?: (array) Settings::get('indexed_post_types', []);

        return $this->respond($this->postKeys($search, $exclude, $scope, sanitize_key((string) $request->get_param('source'))));
    }

    /**
     * @param  list<string>  $exclude
     * @param  list<string>  $taxonomies
     * @return array{total: int, keys: list<array<string, mixed>>}
     */
    private function termKeys(string $search, array $exclude, array $taxonomies): array
    {
        $keys = array_values(array_filter(
            $this->catalog->termKeys($taxonomies),
            static fn (array $key) => ! in_array($key['key'], $exclude, true)
                && ($search === '' || str_contains(strtolower($key['key']), $search))
        ));

        $listed = array_slice($keys, 0, self::META_KEYS_LISTED);
        $types = $this->catalog->types(array_column($listed, 'key'), 'term');

        return [
            'total' => count($keys),
            'keys' => array_map(static fn (array $key) => [...$key, 'type' => $types[$key['key']]], $listed),
        ];
    }

    /**
     * @param  list<string>  $exclude
     * @param  list<string>  $scope  Post types
     * @return array{total: int, keys: list<array<string, mixed>>, sources: list<array{source: string, group: string, count: int}>}
     */
    private function postKeys(string $search, array $exclude, array $scope, string $source): array
    {
        $presence = array_column($this->catalog->keys($scope), null, 'key');
        $named = FieldRegistry::all();
        $companions = FieldRegistry::companionKeys();

        $items = array_map(static fn (MetaField $field) => [
            ...$field->toArray(),
            'registered' => true,
            'posts' => $presence[$field->key]['posts'] ?? 0,
            'post_types' => $presence[$field->key]['post_types'] ?? [],
        ], FieldRegistry::search($search, $scope, $exclude, $source));

        // Raw keys: what the content has, minus what is named already and what only accompanies a named field
        if ($source === '') {
            foreach ($presence as $key => $row) {
                if (isset($named[$key]) || isset($companions[$key]) || in_array($key, $exclude, true)
                    || self::isInternal($key)
                    || ($search !== '' && ! str_contains(strtolower($key), $search))) {
                    continue;
                }

                $items[] = [
                    'key' => $key,
                    'label' => null,
                    'group' => null,
                    'source' => null,
                    'type' => null,
                    'post_types' => $row['post_types'],
                    'registered' => false,
                    'posts' => $row['posts'],
                ];
            }
        }

        $listed = array_slice($items, 0, self::META_KEYS_LISTED);
        $guess = array_column(array_filter($listed, static fn (array $item) => $item['type'] === null), 'key');
        $types = $this->catalog->types($guess);

        return [
            'total' => count($items),
            'keys' => array_map(static fn (array $item) => [...$item, 'type' => $item['type'] ?? $types[$item['key']] ?? null], $listed),
            'sources' => FieldRegistry::sources($scope),
        ];
    }

    private static function isInternal(string $key): bool
    {
        foreach (self::INTERNAL_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $selectedTypes = (array) Settings::get('indexed_post_types', []);
        $selectedTaxonomies = (array) Settings::get('indexed_taxonomies', []);
        $selectedKeys = array_values(array_filter((array) Settings::get('indexed_meta_keys', []), 'is_string'));

        $catalog = $this->catalog->keys($selectedTypes);
        $presence = array_column($catalog, null, 'key');
        $types = $this->catalog->types($selectedKeys);

        $selectedTermKeys = array_values(array_filter((array) Settings::get(TaxonomyIndexable::META_KEYS_SETTING, []), 'is_string'));
        $termCatalog = $this->catalog->termKeys($selectedTaxonomies);
        $termPresence = array_column($termCatalog, null, 'key');
        $termTypes = $this->catalog->types($selectedTermKeys, 'term');

        $structure = (new Indexer)->checkStructureChanges();

        return [
            'post_types' => array_values(array_map(
                static fn (array $type) => [...$type, 'selected' => in_array($type['name'], $selectedTypes, true)],
                $this->postTypes()
            )),
            'taxonomies' => array_values(array_map(
                static fn (array $taxonomy) => [...$taxonomy, 'selected' => in_array($taxonomy['name'], $selectedTaxonomies, true)],
                $this->taxonomies()
            )),
            'meta_keys' => array_map(static function (string $key) use ($types, $presence) {
                $named = FieldRegistry::get($key);

                return [
                    'key' => $key,
                    'label' => $named?->label,
                    'group' => $named?->group,
                    'source' => $named?->source,
                    // The declarer knows what it stores (a flag stored as 0 and 1 is not a number); else the values tell
                    'type' => $named->type ?? $types[$key],
                    'posts' => $presence[$key]['posts'] ?? 0,
                    'post_types' => $presence[$key]['post_types'] ?? [],
                ];
            }, $selectedKeys),
            'field_sources' => FieldRegistry::sources($selectedTypes),
            'meta_key_total' => count($catalog),
            // Keys queries filtered on, which MySQL served because they are not indexed
            'missed_meta_keys' => array_values(array_diff((array) Settings::get(MissedMetaKeys::SETTING, []), $selectedKeys)),
            'term_meta_keys' => array_map(static fn (string $key) => [
                'key' => $key,
                'type' => $termTypes[$key],
                'terms' => $termPresence[$key]['terms'] ?? 0,
                'taxonomies' => $termPresence[$key]['taxonomies'] ?? [],
            ], $selectedTermKeys),
            'term_meta_key_total' => count($termCatalog),
            'missed_term_meta_keys' => array_values(array_diff((array) Settings::get(MissedMetaKeys::TERM_SETTING, []), $selectedTermKeys)),
            'weights' => [
                'configured' => SearchWeights::isActive(),
                'defaults' => SearchWeights::DEFAULT_PROFILE,
                'types' => (object) array_combine($selectedTypes, array_map(static fn (string $type) => (object) SearchWeights::profile($type), $selectedTypes)),
            ],
            'searchable' => [
                'configured' => SearchableAttributes::configured(),
                'suggested' => SearchableAttributes::SUGGESTED,
                'available' => SearchableAttributes::available(),
            ],
            'index_private' => (bool) Settings::get(PostIndexable::INDEX_PRIVATE, false),
            'needs_indexation' => $structure['has_changed'],
            'last_indexed' => $structure['last_indexed'],
        ];
    }

    /**
     * Public post types; attachments (media) are counted in their own status, inherit.
     *
     * @return array<string, array{name: string, label: string, count: int}>
     */
    private function postTypes(): array
    {
        $types = [];

        foreach (get_post_types(['public' => true], 'objects') as $name => $type) {
            $counts = wp_count_posts($name);

            $types[$name] = [
                'name' => $name,
                'label' => (string) $type->labels->name,
                'count' => (int) ($name === 'attachment' ? ($counts->inherit ?? 0) : ($counts->publish ?? 0)),
            ];
        }

        return $types;
    }

    /**
     * Public taxonomies, but post formats.
     *
     * @return array<string, array{name: string, label: string, count: int}>
     */
    private function taxonomies(): array
    {
        $taxonomies = [];

        foreach (get_taxonomies(['public' => true], 'objects') as $name => $taxonomy) {
            if ($name === 'post_format') {
                continue;
            }

            $count = wp_count_terms(['taxonomy' => $name, 'hide_empty' => false]);

            $taxonomies[$name] = [
                'name' => $name,
                'label' => (string) $taxonomy->labels->name,
                'count' => is_numeric($count) ? (int) $count : 0,
            ];
        }

        return $taxonomies;
    }
}
