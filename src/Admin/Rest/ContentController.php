<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Admin\Rest;

use Pollora\MeiliScout\Config\SearchableAttributes;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Services\Indexer;
use Pollora\MeiliScout\Services\MetaKeyCatalog;
use WP_REST_Request;
use WP_REST_Response;

use function get_post_types;
use function get_taxonomies;
use function wp_count_posts;
use function wp_count_terms;

/**
 * What gets indexed: post types, taxonomies, meta keys, and the fields searches look into.
 */
final class ContentController extends Controller
{
    /**
     * Meta keys the search field lists at most.
     */
    private const META_KEYS_LISTED = 20;

    public function __construct(
        private readonly MetaKeyCatalog $catalog = new MetaKeyCatalog,
    ) {}

    public function registerRoutes(): void
    {
        $this->route('/content', 'GET', [$this, 'show']);
        $this->route('/content', 'POST', [$this, 'update']);
        $this->route('/meta-keys', 'GET', [$this, 'metaKeys']);
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

        if ($request->has_param('index_private')) {
            Settings::save(PostIndexable::INDEX_PRIVATE, (bool) $request->get_param('index_private'));
        }

        // Keys now indexed are no longer missed by queries
        $missed = (array) Settings::get('non_indexable_meta_keys', []);
        $stillMissed = array_values(array_diff($missed, $metaKeys));
        if ($stillMissed !== $missed) {
            Settings::save('non_indexable_meta_keys', $stillMissed);
        }

        // After the meta keys: a meta key can be searched once it is indexed
        if ($request->has_param('searchable')) {
            SearchableAttributes::save($this->strings($request, 'searchable'));
        }

        return $this->respond($this->payload());
    }

    /**
     * The meta keys of the posts of the given types (the saved ones by default), matching a search.
     */
    public function metaKeys(WP_REST_Request $request): WP_REST_Response
    {
        $postTypes = $this->strings($request, 'post_types') ?: (array) Settings::get('indexed_post_types', []);
        $search = strtolower(sanitize_text_field((string) $request->get_param('search')));
        $exclude = $this->strings($request, 'exclude');

        $keys = array_values(array_filter(
            $this->catalog->keys($postTypes),
            static fn (array $key) => ! in_array($key['key'], $exclude, true)
                && ($search === '' || str_contains(strtolower($key['key']), $search))
        ));

        $listed = array_slice($keys, 0, self::META_KEYS_LISTED);
        $types = $this->catalog->types(array_column($listed, 'key'));

        return $this->respond([
            'total' => count($keys),
            'keys' => array_map(static fn (array $key) => [...$key, 'type' => $types[$key['key']]], $listed),
        ]);
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
            'meta_keys' => array_map(static fn (string $key) => [
                'key' => $key,
                'type' => $types[$key],
                'posts' => $presence[$key]['posts'] ?? 0,
                'post_types' => $presence[$key]['post_types'] ?? [],
            ], $selectedKeys),
            'meta_key_total' => count($catalog),
            // Keys queries filtered on, which MySQL served because they are not indexed
            'missed_meta_keys' => array_values(array_diff((array) Settings::get('non_indexable_meta_keys', []), $selectedKeys)),
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
     * Public post types, but attachments: they are never published.
     *
     * @return array<string, array{name: string, label: string, count: int}>
     */
    private function postTypes(): array
    {
        $types = [];

        foreach (get_post_types(['public' => true], 'objects') as $name => $type) {
            if ($name === 'attachment') {
                continue;
            }

            $types[$name] = [
                'name' => $name,
                'label' => (string) $type->labels->name,
                'count' => (int) (wp_count_posts($name)->publish ?? 0),
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
