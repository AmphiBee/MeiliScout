<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Http\Controllers;

use Exception;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Query\MeiliQueryBuilder;
use Pollora\MeiliScout\Query\WPQueryAdapter;
use Pollora\MeiliScout\Services\ClientFactory;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

use function add_action;
use function register_rest_route;
use function render_block;
use function wp_kses_post;
use function wp_reset_postdata;

/**
 * REST API controller for archive facets functionality.
 * 
 * This controller handles AJAX requests for archive facet searches,
 * providing both facet data and rendered post content.
 */
class ArchiveFacetsController
{
    /**
     * MeiliSearch query builder instance.
     *
     * @var MeiliQueryBuilder
     */
    private MeiliQueryBuilder $queryBuilder;

    /**
     * Constructor.
     *
     * @param MeiliQueryBuilder $queryBuilder Query builder instance
     */
    public function __construct(MeiliQueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('meiliscout/v1', '/archive-facets', [
                'methods' => ['POST', 'GET'],
                'callback' => [$this, 'handleArchiveFacetsRequest'],
                'permission_callback' => '__return_true', // Public endpoint
                'args' => [
                    'query' => [
                        'required' => false,
                        'type' => 'string',
                        'default' => '',
                        'description' => 'Search query string'
                    ],
                    'archiveContext' => [
                        'required' => true,
                        'type' => 'object',
                        'description' => 'Archive context information'
                    ],
                    'filters' => [
                        'required' => false,
                        'type' => 'object',
                        'default' => [],
                        'description' => 'Active filters'
                    ],
                    'pagination' => [
                        'required' => false,
                        'type' => 'object',
                        'default' => ['page' => 1, 'perPage' => 10],
                        'description' => 'Pagination settings'
                    ],
                    'facets' => [
                        'required' => false,
                        'type' => 'array',
                        'default' => [],
                        'description' => 'Requested facet attributes'
                    ]
                ]
            ]);
        });
    }

    /**
     * Handle archive facets request.
     *
     * @param WP_REST_Request $request The REST request object
     * @return WP_REST_Response The response object
     */
    public function handleArchiveFacetsRequest(WP_REST_Request $request): WP_REST_Response
    {
        try {
            // Get request parameters
            $searchQuery = $request->get_param('query') ?? '';
            $archiveContext = $request->get_param('archiveContext') ?? [];
            $filters = $request->get_param('filters') ?? [];
            $pagination = $request->get_param('pagination') ?? ['page' => 1, 'perPage' => 10];
            $requestedFacets = $request->get_param('facets') ?? [];

            // Build WP_Query arguments
            $queryArgs = $this->buildQueryArgs($searchQuery, $archiveContext, $filters, $pagination);

            // Execute query without filters to get all facet values
            $queryWithoutFilters = new WP_Query($queryArgs);

            // Apply filters for the main query
            if (!empty($filters)) {
                $this->applyFilters($queryArgs, $filters);
            }

            // Execute main query
            $query = new WP_Query($queryArgs);

            // Get rendered posts HTML
            $postsHtml = $this->renderPostsHtml($query->posts, $archiveContext);

            // Format facet distribution
            $facetDistribution = $this->formatFacetDistribution($query->facet_distribution ?? []);
            $allFacetValues = $this->formatFacetDistribution($queryWithoutFilters->facet_distribution ?? []);

            // Build response
            $response = [
                'posts' => $postsHtml,
                'facet_distribution' => $facetDistribution,
                'all_facet_values' => $allFacetValues,
                'found_posts' => $query->found_posts,
                'max_num_pages' => $query->max_num_pages,
                'current_page' => $pagination['page'],
                'archive_context' => $archiveContext,
                'query' => $searchQuery,
                'filters' => $filters
            ];

            return new WP_REST_Response($response, 200);

        } catch (Exception $e) {
            return new WP_REST_Response([
                'error' => true,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Build WP_Query arguments from request parameters.
     *
     * @param string $searchQuery Search query string
     * @param array $archiveContext Archive context data
     * @param array $filters Active filters
     * @param array $pagination Pagination settings
     * @return array WP_Query arguments
     */
    private function buildQueryArgs(string $searchQuery, array $archiveContext, array $filters, array $pagination): array
    {
        $postType = $archiveContext['post_type'] ?? 'post';
        $page = $pagination['page'] ?? 1;
        $perPage = $pagination['perPage'] ?? get_option('posts_per_page', 10);

        $args = [
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'use_meilisearch' => true,
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        // Add search query if provided
        if (!empty($searchQuery)) {
            $args['s'] = $searchQuery;
        }

        // Add archive-specific constraints
        switch ($archiveContext['type'] ?? '') {
            case 'category':
                $args['cat'] = $archiveContext['data']['term_id'] ?? 0;
                break;
                
            case 'tag':
                $args['tag_id'] = $archiveContext['data']['term_id'] ?? 0;
                break;
                
            case 'author':
                $args['author'] = $archiveContext['data']['author_id'] ?? 0;
                break;
                
            case 'date':
                $this->addDateConstraints($args, $archiveContext['data'] ?? []);
                break;
                
            case 'taxonomy':
                $args['tax_query'] = [[
                    'taxonomy' => $archiveContext['data']['taxonomy'] ?? '',
                    'field' => 'term_id',
                    'terms' => $archiveContext['data']['term_id'] ?? 0
                ]];
                break;
        }

        // Add facet configuration
        $facetConfig = $this->getArchiveFacetConfig($postType);
        if (!empty($facetConfig)) {
            $args['facets'] = array_column($facetConfig, 'attribute');
        }

        return $args;
    }

    /**
     * Apply filters to query arguments.
     *
     * @param array &$queryArgs Query arguments (passed by reference)
     * @param array $filters Active filters
     * @return void
     */
    private function applyFilters(array &$queryArgs, array $filters): void
    {
        $metaFilters = [];
        $taxFilters = [];

        foreach ($filters as $attribute => $values) {
            if (empty($values)) {
                continue;
            }

            // Normalize values to array
            if (!is_array($values)) {
                $values = [$values];
            }

            // Determine filter type based on attribute
            if (strpos($attribute, 'meta_') === 0) {
                // Meta field filter
                $metaKey = str_replace('meta_', '', $attribute);
                $metaFilters[] = [
                    'key' => $metaKey,
                    'value' => $values,
                    'compare' => count($values) > 1 ? 'IN' : '=',
                ];
            } else {
                // Taxonomy filter
                $taxFilters[] = [
                    'taxonomy' => $attribute,
                    'field' => 'name',
                    'terms' => $values,
                    'operator' => 'IN',
                ];
            }
        }

        // Apply meta query
        if (!empty($metaFilters)) {
            $queryArgs['meta_query'] = $metaFilters;
            if (count($metaFilters) > 1) {
                $queryArgs['meta_query']['relation'] = 'AND';
            }
        }

        // Apply taxonomy query
        if (!empty($taxFilters)) {
            $existingTaxQuery = $queryArgs['tax_query'] ?? [];
            $queryArgs['tax_query'] = array_merge($existingTaxQuery, $taxFilters);
            if (count($queryArgs['tax_query']) > 1) {
                $queryArgs['tax_query']['relation'] = 'AND';
            }
        }
    }

    /**
     * Add date constraints to query arguments.
     *
     * @param array &$queryArgs Query arguments (passed by reference)
     * @param array $dateData Date data from archive context
     * @return void
     */
    private function addDateConstraints(array &$queryArgs, array $dateData): void
    {
        if (empty($dateData['year'])) {
            return;
        }

        $queryArgs['year'] = $dateData['year'];

        if (!empty($dateData['month'])) {
            $queryArgs['monthnum'] = $dateData['month'];
        }

        if (!empty($dateData['day'])) {
            $queryArgs['day'] = $dateData['day'];
        }
    }

    /**
     * Render posts HTML using the current theme's template.
     *
     * @param array $posts Array of WP_Post objects
     * @param array $archiveContext Archive context data
     * @return string Rendered HTML
     */
    private function renderPostsHtml(array $posts, array $archiveContext): string
    {
        if (empty($posts)) {
            return '<p class="no-posts-found">' . 
                   esc_html__('No posts found matching your criteria.', 'meiliscout') . 
                   '</p>';
        }

        $output = '';
        $postType = $archiveContext['post_type'] ?? 'post';

        // Start posts container
        $output .= '<div class="meiliscout-posts-container archive-posts">';

        foreach ($posts as $post) {
            $GLOBALS['post'] = $post;
            setup_postdata($post);

            // Start post article
            $output .= sprintf(
                '<article id="post-%d" class="%s">',
                $post->ID,
                esc_attr(implode(' ', get_post_class('', $post->ID)))
            );

            // Use theme's content template or fallback
            $template = $this->getPostTemplate($postType);
            if ($template) {
                ob_start();
                include $template;
                $output .= ob_get_clean();
            } else {
                // Fallback template
                $output .= $this->renderDefaultPostContent($post);
            }

            // End post article
            $output .= '</article>';
        }

        // End posts container
        $output .= '</div>';

        wp_reset_postdata();

        return $output;
    }

    /**
     * Get the appropriate post template file.
     *
     * @param string $postType Post type name
     * @return string|null Template file path or null if not found
     */
    private function getPostTemplate(string $postType): ?string
    {
        $templates = [
            "content-{$postType}.php",
            'content.php',
            'index.php'
        ];

        foreach ($templates as $template) {
            $path = locate_template($template);
            if ($path) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Render default post content when no template is found.
     *
     * @param \WP_Post $post Post object
     * @return string Rendered HTML
     */
    private function renderDefaultPostContent(\WP_Post $post): string
    {
        $output = '';
        
        // Post title
        $output .= '<header class="entry-header">';
        $output .= '<h2 class="entry-title">';
        $output .= '<a href="' . esc_url(get_permalink($post->ID)) . '">';
        $output .= esc_html(get_the_title($post->ID));
        $output .= '</a>';
        $output .= '</h2>';
        $output .= '</header>';

        // Post meta
        $output .= '<div class="entry-meta">';
        $output .= '<time class="entry-date">';
        $output .= esc_html(get_the_date('', $post->ID));
        $output .= '</time>';
        $output .= '</div>';

        // Post excerpt
        $output .= '<div class="entry-summary">';
        $output .= wp_kses_post(get_the_excerpt($post->ID));
        $output .= '</div>';

        return $output;
    }

    /**
     * Format facet distribution for frontend consumption.
     *
     * @param array $distribution Raw facet distribution from MeiliSearch
     * @return array Formatted facet distribution
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
     * Get facet configuration for the specified post type.
     *
     * @param string $postType Post type name
     * @return array Facet configuration
     */
    private function getArchiveFacetConfig(string $postType): array
    {
        $facetsConfig = Settings::get('archive_facets_configuration', []);
        $postTypeFacets = $facetsConfig[$postType] ?? [];

        // Filter to only enabled facets
        $enabledFacets = array_filter($postTypeFacets, function ($facet) {
            return $facet['enabled'] ?? false;
        });

        // Sort by sort_order
        usort($enabledFacets, function ($a, $b) {
            return ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
        });

        return $enabledFacets;
    }
}