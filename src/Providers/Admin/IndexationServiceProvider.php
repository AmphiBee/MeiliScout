<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers\Admin;

use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\Indexer;

use function Pollora\MeiliScout\get_template_part;

/**
 * Service provider for managing Meilisearch indexation in the WordPress admin.
 */
class IndexationServiceProvider extends ServiceProvider
{
    /**
     * Registers the service provider's hooks and actions.
     *
     * @return void
     */
    public function register()
    {
        if (! ClientFactory::isConfigured()) {
            return;
        }
        add_action('admin_menu', [$this, 'addIndexationMenu']);
        add_action('admin_post_meiliscout_indexation', [$this, 'handleIndexation']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('meiliscout_process_indexation', [$this, 'processIndexation']);
    }

    /**
     * Registers REST API routes for indexation status.
     *
     * @return void
     */
    public function registerRestRoutes()
    {
        register_rest_route('meiliscout/v1', '/indexation-status', [
            'methods' => 'GET',
            'callback' => [$this, 'getIndexationStatus'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Returns the current indexation status.
     *
     * @return \WP_REST_Response
     */
    public function getIndexationStatus()
    {
        $log = get_option('meiliscout/last_indexing_log', []);

        return rest_ensure_response($log);
    }

    /**
     * Handles the indexation form submission.
     *
     * @return void
     */
    public function handleIndexation()
    {
        if (! isset($_POST['meiliscout_indexation_nonce']) ||
            ! wp_verify_nonce($_POST['meiliscout_indexation_nonce'], 'meiliscout_indexation_action')) {
            return;
        }

        if (! current_user_can('manage_options')) {
            return;
        }

        // Initialize log with waiting message
        $indexer = new Indexer;
        $indexer->initializeLog();
        $indexer->log('info', 'Indexation will start soon...');

        // Schedule indexation event
        if (! wp_next_scheduled('meiliscout_process_indexation')) {
            wp_schedule_single_event(time() + 10, 'meiliscout_process_indexation', [
                [
                    'clear_indices' => isset($_POST['clear_indices']),
                    'index_posts' => isset($_POST['index_posts']),
                    'index_taxonomies' => isset($_POST['index_taxonomies']),
                ],
            ]);
        }

        wp_redirect(admin_url('admin.php?page=meiliscout-indexation'));
        exit;
    }

    /**
     * Processes the actual indexation.
     *
     * @param  array  $options  Indexation options
     * @return void
     */
    public function processIndexation($options)
    {
        $indexer = new Indexer;
        $indexer->index($options['clear_indices']);
    }

    /**
     * Renders the indexation page.
     *
     * @return void
     */
    public function renderIndexationPage()
    {
        get_template_part('indexation', [
            'last_log' => get_option('meiliscout/last_indexing_log', []),
        ]);
    }

    /**
     * Adds the indexation submenu page to WordPress admin.
     *
     * @return void
     */
    public function addIndexationMenu()
    {
        add_submenu_page(
            'meiliscout-settings',
            'Indexation',
            'Indexation',
            'manage_options',
            'meiliscout-indexation',
            [$this, 'renderIndexationPage']
        );
    }
}
