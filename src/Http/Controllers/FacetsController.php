<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Http\Controllers;

use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Import WordPress functions
 */
use function add_action;
use function parse_blocks;
use function register_rest_route;
use function render_block;
use function wp_kses_post;
use function wp_reset_postdata;

class FacetsController
{
    public function register(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('meiliscout/v1', '/facets', [
                'methods' => 'POST,GET',  // Allow both POST and GET
                'callback' => [$this, 'handleFacetsRequest'],
                'permission_callback' => function () {
                    return true; // Allow all requests for now
                },
                'args' => [
                    'query' => [
                        'required' => true,
                        'type' => 'object',
                    ],
                    'filters' => [
                        'required' => false,
                        'type' => 'object',
                        'default' => [],
                    ],
                ],
            ]);
        });
    }

    public function handleFacetsRequest(WP_REST_Request $request): WP_REST_Response
    {
        // Get JSON data directly from the request
        $queryArgs = $request->get_json_params()['query'] ?? [];
        $filters = $request->get_json_params()['filters'] ?? [];


        $template = $request->get_json_params()['template'] ?? '';

        // Convert Query Loop parameters to WP_Query parameters
        $queryArgs = $this->convertQueryParams($queryArgs);

        // Ensure Meilisearch is used
        $queryArgs['use_meilisearch'] = true;

        $queryWithoutFilters = new WP_Query($queryArgs);

        // Apply filters to the query
        if (! empty($filters)) {
            $this->applyFilters($queryArgs, $filters);
        }

        // Execute the query
        $query = new WP_Query($queryArgs);

        // Get the posts HTML using the provided template
        $postsHtml = $this->renderPostsWithTemplate($query->posts, $template);

        // Format facet distribution to use only term names
        $facetDistribution = $this->formatFacetDistribution($query->facet_distribution ?? []);

        // Prepare the response
        $response = [
            'facet_distribution' => $facetDistribution,
            'all_facet_values' => $this->formatFacetDistribution($queryWithoutFilters->facet_distribution ?? []),
            'facet_raw' => $query->facet_raw ?? [],
            'posts' => $postsHtml,
            'found_posts' => $query->found_posts,
            'max_num_pages' => $query->max_num_pages,
        ];

        return new WP_REST_Response($response, 200);
    }

    /**
     * Apply filters to the query arguments
     */
    private function applyFilters(array &$queryArgs, array $filters): void
    {
        $metaFilters = [];
        $taxFilters = [];

        foreach ($filters as $key => $filter) {
            if (! isset($filter['value'], $filter['type'])) {
                continue;
            }

            $value = $filter['value'];
            $type = $filter['type'];

            // Handle comma-separated string values
            if (is_string($value)) {
                $value = array_map('trim', explode(',', $value));
                $value = array_filter($value); // Remove empty values
            }

            if ($type === 'taxonomy') {
                if (is_array($value)) {
                    $taxFilters[] = [
                        'taxonomy' => $key,
                        'field' => 'name',
                        'terms' => $value,
                        'operator' => 'IN',
                    ];
                } else {
                    $taxFilters[] = [
                        'taxonomy' => $key,
                        'field' => 'name',
                        'terms' => [$value],
                        'operator' => 'IN',
                    ];
                }
            } else {
                // Meta filter
                if (str_ends_with($key, '_min') || str_ends_with($key, '_max')) {
                    $baseKey = str_replace(['_min', '_max'], '', $key);
                    $compare = str_ends_with($key, '_min') ? '>=' : '<=';

                    $metaFilters[] = [
                        'key' => $baseKey,
                        'value' => $value,
                        'compare' => $compare,
                        'type' => 'NUMERIC',
                    ];
                } else {
                    $metaFilters[] = [
                        'key' => $key,
                        'value' => $value,
                    ];
                }
            }
        }

        // Apply meta query if we have meta filters
        if (! empty($metaFilters)) {
            $metaFilters['relation'] = 'AND';
            $queryArgs['meta_query'] = $metaFilters;
        }

        // Apply tax query if we have taxonomy filters
        if (! empty($taxFilters)) {
            $taxFilters['relation'] = 'AND';
            $queryArgs['tax_query'] = $taxFilters;
        }
    }

    /**
     * Format facet distribution to use only term names
     */
    private function formatFacetDistribution(array $distribution): array
    {
        $formatted = [];

        foreach ($distribution as $taxonomy => $values) {
            if (isset($values['name']) && is_array($values['name'])) {
                $formatted[$taxonomy] = $values['name'];
            }
        }

        return $formatted;
    }

    /**
     * Convert Query Loop parameters to WP_Query parameters
     */
    private function convertQueryParams(array $params): array
    {
        $converted = [];

        // Map parameters
        $converted['posts_per_page'] = $params['perPage'] ?? 10;
        $converted['paged'] = $params['paged'] ?? 1;
        $converted['post_type'] = $params['postType'] ?? 'post';
        $converted['order'] = strtoupper($params['order'] ?? 'DESC');
        $converted['orderby'] = $params['orderBy'] ?? 'date';

        // Handle offset if present
        if (isset($params['offset']) && $params['offset'] > 0) {
            $converted['offset'] = $params['offset'];
        }

        // Handle taxonomy queries if present
        if (! empty($params['taxQuery'])) {
            $converted['tax_query'] = $this->buildTaxQuery($params['taxQuery']);
        }

        // Handle search query if present
        if (! empty($params['search'])) {
            $converted['s'] = $params['search'];
        }

        // Add facets to query if present
        if (! empty($params['facets'])) {
            $converted['facets'] = $params['facets'];
        }

        return $converted;
    }

    private function buildTaxQuery(array $taxQuery): array
    {
        $query = [];

        foreach ($taxQuery as $tax) {
            if (empty($tax['terms'])) {
                continue;
            }

            $query[] = [
                'taxonomy' => $tax['taxonomy'] ?? '',
                'field' => $tax['field'] ?? 'term_id',
                'terms' => $tax['terms'],
                'operator' => $tax['operator'] ?? 'IN',
            ];
        }

        if (count($query) > 1) {
            $query['relation'] = 'AND';
        }

        return $query;
    }

    private function renderPostsWithTemplate(array $posts, array $blocks): string
    {
        if (empty($posts)) {
            return '';
        }

        $output = '';

        // Find the post-template block and its inner blocks
        $innerBlocks = [];

        $postTemplateBlock = $this->findPostTemplateBlock($blocks);

        if ($postTemplateBlock && ! empty($postTemplateBlock['innerBlocks'])) {
            $innerBlocks = $postTemplateBlock['innerBlocks'];
        }

        // If no inner blocks found, use default template
        if (empty($innerBlocks)) {
            $innerBlocks = parse_blocks('<!-- wp:post-title {"isLink":true} /--><!-- wp:post-excerpt /-->')[0]['innerBlocks'] ?? [];
        }

        // Start the post-template wrapper
        $output .= '<div class="wp-block-post-template">';

        foreach ($posts as $post) {
            $GLOBALS['post'] = $post;

            // Start post article
            $output .= sprintf(
                '<article class="wp-block-post post-%d post type-post status-publish">',
                $post->ID
            );

            // Render each block in the template
            foreach ($innerBlocks as $block) {
                $block_content = render_block($block);

                if ($block_content) {
                    $output .= wp_kses_post($block_content);
                }
            }

            // Close post article
            $output .= '</article>';
        }

        // Close post-template wrapper
        $output .= '</div>';

        // Reset postdata
        wp_reset_postdata();

        return $output;
    }

    private function findPostTemplateBlock(array $blocks): ?array
    {
        foreach ($blocks as $block) {
            if ($block['blockName'] === 'core/post-template') {
                return $block;
            }

            if (! empty($block['innerBlocks'])) {
                $found = $this->findPostTemplateBlock($block['innerBlocks']);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }
}
