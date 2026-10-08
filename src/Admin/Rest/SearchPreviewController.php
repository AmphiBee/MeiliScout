<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Admin\Rest;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Diagnostics\QueryParity;
use Pollora\MeiliScout\Diagnostics\TermQueryParity;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexNames;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function esc_html;
use function get_edit_post_link;
use function get_object_taxonomies;
use function get_post_type_object;
use function get_taxonomy;
use function get_terms;

/**
 * Searches the index searches read, as the site does, and shows what came back.
 */
final class SearchPreviewController extends Controller
{
    private const LIMIT = 20;

    /**
     * Highlight markers Meilisearch puts around matches, replaced by <mark> once the text is escaped.
     */
    private const MARK_START = "\u{E000}";

    private const MARK_END = "\u{E001}";

    public function registerRoutes(): void
    {
        $this->route('/search', 'POST', [$this, 'search']);
        $this->route('/wp-query', 'POST', [$this, 'wpQuery']);
    }

    /**
     * Runs WP_Query arguments (with kind=terms, get_terms() ones) on MySQL and on Meilisearch, as the current user, and compares them.
     */
    public function wpQuery(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $args = $request->get_param('args');

        if (! is_array($args)) {
            return new WP_Error('meiliscout_invalid_args', __('The arguments must be a JSON object, e.g. {"cat": 3}.', 'meiliscout'), ['status' => 400]);
        }

        // As the user testing: no one else's permissions, and the engine is chosen here
        unset($args['_user'], $args['use_meilisearch']);

        $mode = (string) $request->get_param('mode');
        $modes = [QueryParity::MODE_ORDER, QueryParity::MODE_SET, QueryParity::MODE_COUNT, QueryParity::MODE_SEARCH];
        $mode = in_array($mode, $modes, true) ? $mode : QueryParity::MODE_ORDER;
        $args['_user'] = get_current_user_id();

        if ($request->get_param('kind') === 'terms') {
            return $this->termQuery($args, $mode === QueryParity::MODE_COUNT ? QueryParity::MODE_ORDER : $mode);
        }

        $result = QueryParity::compare($args, $mode);

        $posts = fn (array $ids): array => array_map(static function (int $id): array {
            $post = get_post($id);

            return [
                'id' => $id,
                'title' => $post ? wp_strip_all_tags(get_the_title($post)) : '#'.$id,
                'type' => $post ? $post->post_type : '',
                'status' => $post ? $post->post_status : '',
            ];
        }, $ids);

        return $this->respond([
            'outcome' => $result['outcome'],
            'reason' => $result['reason'] ?? null,
            'notes' => $result['notes'],
            'mysql' => ['found' => $result['mysql_found'] ?? null, 'posts' => $posts($result['mysql_ids'] ?? [])],
            'meilisearch' => ['found' => $result['meili_found'] ?? null, 'posts' => $posts($result['meili_ids'] ?? [])],
            'params' => $result['params'] ?? null,
        ]);
    }

    /**
     * Runs get_terms() arguments on both engines; each side lists what get_terms() returned, by key.
     *
     * @param  array<string, mixed>  $args
     */
    private function termQuery(array $args, string $mode): WP_REST_Response
    {
        $result = TermQueryParity::compare($args, $mode);

        $items = static function (array $values): array {
            $items = [];

            foreach ($values as $key => $value) {
                $term = is_int($value) ? get_term($value) : null;

                $items[] = [
                    'id' => $key,
                    'title' => $term instanceof \WP_Term ? $term->name.' (#'.$term->term_id.')' : (is_scalar($value) ? (string) $value : (string) wp_json_encode($value)),
                    'type' => $term instanceof \WP_Term ? $term->taxonomy : '',
                    'status' => '',
                ];
            }

            return $items;
        };

        return $this->respond([
            'outcome' => $result['outcome'],
            'reason' => $result['reason'] ?? null,
            'notes' => $result['notes'],
            'mysql' => ['found' => $result['mysql_found'] ?? null, 'posts' => $items($result['mysql_ids'] ?? [])],
            'meilisearch' => ['found' => $result['meili_found'] ?? null, 'posts' => $items($result['meili_ids'] ?? [])],
            'params' => $result['params'] ?? null,
        ]);
    }

    public function search(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // The key the site searches with
        $client = ClientFactory::getReadClient();

        if ($client === null) {
            return $this->unreachable();
        }

        $query = sanitize_text_field((string) $request->get_param('q'));
        $postTypes = $this->strings($request, 'post_types');
        $terms = (array) $request->get_param('terms');
        $taxonomies = $this->facetedTaxonomies();
        $legacy = IndexNames::activeSchema() < 2;

        // The filters, by the facet they come from
        $filters = [];

        if ($postTypes !== []) {
            $filters['post_type'] = $this->in('post_type', $postTypes);
        }

        foreach ($terms as $taxonomy => $slugs) {
            if (in_array($taxonomy, $taxonomies, true) && is_array($slugs) && $slugs !== []) {
                $attribute = $legacy ? 'terms.slug' : "taxonomies.{$taxonomy}.slug";
                $filters[$attribute] = $this->in($attribute, array_map('strval', $slugs));
            }
        }

        $params = [
            'filter' => $this->filter($filters),
            'facets' => ['post_type', ...($legacy ? [] : array_map(static fn ($taxonomy) => "taxonomies.{$taxonomy}.slug", $taxonomies))],
            'limit' => self::LIMIT,
            'attributesToRetrieve' => ['ID', 'post_type', 'post_title', 'post_excerpt', 'content_text', 'terms', 'url'],
            'attributesToHighlight' => ['post_title', 'post_excerpt', 'content_text'],
            'attributesToCrop' => ['content_text:32'],
            'highlightPreTag' => self::MARK_START,
            'highlightPostTag' => self::MARK_END,
        ];

        $indexName = IndexNames::active('posts');

        try {
            $results = $client->index($indexName)->search($query, $params);
        } catch (\Throwable $e) {
            return new WP_Error('meiliscout_search_failed', $e->getMessage(), ['status' => 502]);
        }

        $facets = $results->getFacetDistribution();

        // A facet in use counts its values without its own filter: the other choices stay listed
        foreach (array_keys($filters) as $attribute) {
            try {
                $facets[$attribute] = $client->index($indexName)->search($query, [
                    'filter' => $this->filter(array_diff_key($filters, [$attribute => true])),
                    'facets' => [$attribute],
                    'limit' => 0,
                ])->getFacetDistribution()[$attribute] ?? [];
            } catch (\Throwable) {
                // The counts with the filter stay
            }
        }

        return $this->respond([
            'index' => $indexName,
            'total' => $results->getEstimatedTotalHits() ?? $results->getTotalHits() ?? $results->getHitsCount(),
            'processing_time_ms' => $results->getProcessingTimeMs(),
            'hits' => array_map(fn (array $hit) => $this->hit($hit), $results->getHits()),
            'facets' => [
                'post_type' => $this->typeFacet($facets['post_type'] ?? []),
                'taxonomies' => $this->termFacets($taxonomies, $facets),
            ],
            // What the app shows under "Request sent to Meilisearch"
            'request' => ['index' => $indexName, 'q' => $query, ...array_intersect_key($params, array_flip(['filter', 'facets', 'limit']))],
        ]);
    }

    /**
     * The public taxonomies of the indexed post types.
     *
     * @return list<string>
     */
    private function facetedTaxonomies(): array
    {
        $taxonomies = [];

        foreach ((array) Settings::get('indexed_post_types', []) as $postType) {
            foreach (get_object_taxonomies((string) $postType, 'objects') as $name => $taxonomy) {
                if ($taxonomy->public && $name !== 'post_format') {
                    $taxonomies[$name] = $name;
                }
            }
        }

        return array_values($taxonomies);
    }

    /**
     * @param  array<string, mixed>  $hit
     * @return array<string, mixed>
     */
    private function hit(array $hit): array
    {
        $formatted = (array) ($hit['_formatted'] ?? []);
        $excerpt = (string) ($formatted['post_excerpt'] ?? '') !== ''
            ? (string) $formatted['post_excerpt']
            : (string) ($formatted['content_text'] ?? '');
        $postType = (string) ($hit['post_type'] ?? '');

        return [
            'id' => (int) ($hit['ID'] ?? 0),
            'post_type' => $postType,
            'type_label' => $this->typeLabel($postType),
            'title' => $this->marked((string) ($formatted['post_title'] ?? $hit['post_title'] ?? '')),
            'excerpt' => $this->marked($excerpt),
            'terms' => array_values(array_unique(array_map(
                static fn ($term) => (string) ($term['name'] ?? ''),
                array_filter((array) ($hit['terms'] ?? []), 'is_array')
            ))),
            'url' => (string) ($hit['url'] ?? ''),
            'edit_url' => (string) get_edit_post_link((int) ($hit['ID'] ?? 0), 'raw'),
        ];
    }

    /**
     * Escapes a highlighted text, then turns the markers into <mark>: the result is safe HTML.
     */
    private function marked(string $text): string
    {
        return str_replace([self::MARK_START, self::MARK_END], ['<mark>', '</mark>'], esc_html($text));
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{value: string, label: string, count: int}>
     */
    private function typeFacet(array $counts): array
    {
        return array_map(
            fn (string $type, int $count) => ['value' => $type, 'label' => $this->typeLabel($type), 'count' => $count],
            array_keys($counts),
            array_values($counts)
        );
    }

    /**
     * @param  list<string>  $taxonomies
     * @param  array<string, array<string, int>>  $facets
     * @return list<array{taxonomy: string, label: string, values: list<array{value: string, label: string, count: int}>}>
     */
    private function termFacets(array $taxonomies, array $facets): array
    {
        $groups = [];

        foreach ($taxonomies as $taxonomy) {
            $counts = $facets["taxonomies.{$taxonomy}.slug"] ?? [];

            if ($counts === []) {
                continue;
            }

            $names = [];
            foreach ((array) get_terms(['taxonomy' => $taxonomy, 'slug' => array_keys($counts), 'hide_empty' => false]) as $term) {
                if ($term instanceof \WP_Term) {
                    $names[$term->slug] = $term->name;
                }
            }

            arsort($counts);

            $groups[] = [
                'taxonomy' => $taxonomy,
                'label' => (string) (get_taxonomy($taxonomy)->labels->name ?? $taxonomy),
                'values' => array_map(
                    static fn (string $slug, int $count) => ['value' => $slug, 'label' => $names[$slug] ?? $slug, 'count' => $count],
                    array_map('strval', array_keys($counts)),
                    array_values($counts)
                ),
            ];
        }

        return $groups;
    }

    private function typeLabel(string $postType): string
    {
        return (string) (get_post_type_object($postType)->labels->singular_name ?? $postType);
    }

    /**
     * Published posts, matching every facet filter.
     *
     * @param  array<string, string>  $filters
     */
    private function filter(array $filters): string
    {
        return implode(' AND ', [$this->in('post_status', PostIndexable::indexableStatuses()), ...array_values($filters)]);
    }

    /**
     * @param  list<string>  $values
     */
    private function in(string $attribute, array $values): string
    {
        $quoted = array_map(static fn (string $value) => "'".addslashes($value)."'", $values);

        return sprintf('%s IN [%s]', $attribute, implode(', ', $quoted));
    }
}
