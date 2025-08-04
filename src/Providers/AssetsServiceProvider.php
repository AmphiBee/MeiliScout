<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Foundation\Webpack;
use Pollora\MeiliScout\Http\Controllers\FacetsController;

use function add_action;
use function add_filter;
use function esc_attr;
use function is_admin;
use function rest_url;
use function serialize_block;
use function wp_create_nonce;
use function wp_json_encode;
use function wp_localize_script;
use function wp_set_script_translations;

/**
 * Service provider for managing and enqueueing plugin assets.
 */
class AssetsServiceProvider extends ServiceProvider
{
    /**
     * The Webpack instance for asset handling.
     */
    protected Webpack $webpack;

    /**
     * Creates a new AssetsServiceProvider instance.
     */
    public function __construct()
    {
        $this->webpack = new Webpack;
    }

    /**
     * Registers the service provider's hooks and actions.
     */
    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueGutenbergAssets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontendAssets']);

        // Register REST API endpoints
        $facetsController = new FacetsController;
        $facetsController->register();

        add_filter('render_block_core/query', function ($block_content, $block) {
            // Vérifier si c'est notre variation de bloc
            if (empty($block['attrs']['namespace']) || $block['attrs']['namespace'] !== 'meiliscout/query-loop-search') {
                return $block_content;
            }

            // Trouver le bloc post-template
            if (! empty($block['innerBlocks'])) {
                $post_template_block = self::find_post_template_block($block['innerBlocks']);
                if ($post_template_block) {
                    $template_string = serialize_block($post_template_block);
                    $encoded = rawurlencode($template_string);
                }
            }

            // Préparer les données pour l'attribut data-query
            $query_data = [
                'perPage' => $block['attrs']['query']['perPage'] ?? 10,
                'pages' => $block['attrs']['query']['pages'] ?? 0,
                'offset' => $block['attrs']['query']['offset'] ?? 0,
                'postType' => $block['attrs']['query']['postType'] ?? 'post',
                'order' => $block['attrs']['query']['order'] ?? 'desc',
                'orderBy' => $block['attrs']['query']['orderBy'] ?? 'date',
                'use_meilisearch' => true,
                'facets' => $block['attrs']['query']['facets'] ?? [],
            ];

            // Ajouter la classe is-meilisearch
            $block_content = str_replace(
                'class="wp-block-query',
                'class="wp-block-query is-meilisearch',
                $block_content
            );

            // Ajouter les attributs data
            $data_attributes = sprintf(
                ' data-query-id="%s" data-query="%s" data-enable-url-params="%s"',
                esc_attr($block['attrs']['queryId'] ?? 'default'),
                esc_attr(wp_json_encode($query_data)),
                esc_attr($block['attrs']['enableUrlParams'] ?? 'false')
            );

            // Si on a un template, l'ajouter aussi
            if (isset($encoded)) {
                $data_attributes .= sprintf(' data-template="%s"', esc_attr($encoded));
            }

            // Insérer les attributs data juste après l'ouverture de la div
            $block_content = preg_replace(
                '/(<div[^>]*class="[^"]*wp-block-query[^"]*")/i',
                '$1'.$data_attributes,
                $block_content
            );

            return $block_content;
        }, 10, 2);

        /**
         * Recherche récursive du bloc core/post-template
         */
    }

    public static function find_post_template_block($blocks)
    {
        foreach ($blocks as $block) {
            if ($block['blockName'] === 'core/post-template') {
                return $block;
            }

            if (! empty($block['innerBlocks'])) {
                $found = self::find_post_template_block($block['innerBlocks']);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Vérifie si nous sommes sur une page d'administration MeiliScout.
     */
    private function isMeiliScoutAdminPage(): bool
    {
        if (! is_admin() || ! isset($_GET['page'])) {
            return false;
        }

        $meiliscout_pages = [
            'meiliscout-settings',
            'meiliscout-content-selection',
            'meiliscout-indexation',
        ];

        return in_array($_GET['page'], $meiliscout_pages);
    }

    /**
     * Enqueues the plugin's assets using Webpack.
     */
    public function enqueueAssets(): void
    {
        // Enqueue Tailwind CSS and other assets only on MeiliScout admin pages
        if ($this->isMeiliScoutAdminPage()) {
            $this->webpack->enqueueAssets('main', 'main');
        }
    }

    /**
     * Enqueues the Gutenberg assets.
     */
    public function enqueueGutenbergAssets(): void
    {
        // Enqueue les assets Gutenberg avec les dépendances WordPress
        $this->webpack->enqueueAssets('meiliscout/query-loop', 'query-loop/index');
        $this->webpack->enqueueAssets('meiliscout/query-loop-facet', 'query-loop-facet/index');

        // Enregistre les traductions
        wp_set_script_translations('meiliscout-gutenberg', 'meiliscout');
    }

    /**
     * Enqueues the frontend assets.
     */
    public function enqueueFrontendAssets(): void
    {
        $this->webpack->enqueueAssets('frontend', 'front-facets');

        // Add nonce for REST API
        wp_localize_script('meiliscout-front-facets', 'meiliscoutSettings', [
            'nonce' => wp_create_nonce('wp_rest'),
            'restUrl' => rest_url(),
        ]);
    }
}
