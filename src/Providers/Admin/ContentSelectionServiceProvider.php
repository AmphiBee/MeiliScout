<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers\Admin;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\Indexer;

use function add_action;
use function add_query_arg;
use function add_submenu_page;
use function admin_url;
use function current_user_can;
use function esc_url;
use function get_post_types;
use function get_taxonomies;
use function Pollora\MeiliScout\get_template_part;
use function wp_redirect;
use function wp_verify_nonce;

/**
 * Service provider for managing content selection in the WordPress admin.
 */
class ContentSelectionServiceProvider extends ServiceProvider
{
    private ?Indexer $indexer = null;

    /**
     * Get the indexer instance, creating it if it doesn't exist.
     */
    private function getIndexer(): Indexer
    {
        if ($this->indexer === null) {
            $this->indexer = new Indexer;
        }

        return $this->indexer;
    }

    /**
     * Registers the service provider's hooks and actions.
     *
     * @return void
     */
    public function register()
    {
        if (!ClientFactory::isConfigured()) {
            return;
        }

        add_action('admin_post_meiliscout_content_selection', [$this, 'saveContentSelection']);
        add_action('admin_menu', [$this, 'addContentSelectionMenu']);
    }

    /**
     * Adds the content selection submenu page to WordPress admin.
     *
     * @return void
     */
    public function addContentSelectionMenu()
    {
        add_submenu_page(
            'meiliscout-settings',
            'Content Selection',
            'Content Selection',
            'manage_options',
            'meiliscout-content-selection',
            [$this, 'renderContentSelectionPage']
        );
    }

    /**
     * Renders the content selection page.
     *
     * @return void
     */
    public function renderContentSelectionPage()
    {
        $message = isset($_GET['content_updated']) ? 'Content selection has been successfully updated.' : '';

        get_template_part('components/alert', ['message' => $message]);

        // Vérifier les changements de structure
        $structureChanges = $this->getIndexer()->checkStructureChanges();

        get_template_part('content-selection', [
            'saved_post_types' => Settings::get('indexed_post_types', []),
            'saved_taxonomies' => Settings::get('indexed_taxonomies', []),
            'saved_meta_keys' => Settings::get('indexed_meta_keys', []),
            'non_indexable_meta_keys' => Settings::get('non_indexable_meta_keys', []),
            'post_types' => get_post_types(['public' => true], 'objects'),
            'taxonomies' => get_taxonomies(['public' => true], 'objects'),
            'structure_changes' => $structureChanges,
        ]);
    }

    /**
     * Handles the content selection form submission.
     *
     * @return void
     */
    public function saveContentSelection()
    {
        // Security nonce verification
        if (! isset($_POST['meiliscout_content_selection_nonce']) || ! wp_verify_nonce($_POST['meiliscout_content_selection_nonce'], 'meiliscout_content_selection_save')) {
            return;
        }

        // User capability check
        if (current_user_can('manage_options')) {
            // Get and sanitize form data
            $post_types = isset($_POST['post_types']) ? array_map('sanitize_text_field', $_POST['post_types']) : [];
            $taxonomies = isset($_POST['taxonomies']) ? array_map('sanitize_text_field', $_POST['taxonomies']) : [];
            $meta_keys = isset($_POST['meta_keys']) ? array_filter(array_map('sanitize_text_field', $_POST['meta_keys'])) : [];

            // Save options to database
            Settings::save('indexed_post_types', $post_types);
            Settings::save('indexed_taxonomies', $taxonomies);
            Settings::save('indexed_meta_keys', $meta_keys);

            // Nettoyer les meta keys non indexables
            $non_indexable_meta_keys = Settings::get('non_indexable_meta_keys', []);
            if (! empty($non_indexable_meta_keys) && ! empty($meta_keys)) {
                // Supprimer les meta keys qui sont maintenant indexables
                $cleaned_non_indexable_meta_keys = array_diff($non_indexable_meta_keys, $meta_keys);

                // Sauvegarder la liste mise à jour
                if ($cleaned_non_indexable_meta_keys !== $non_indexable_meta_keys) {
                    Settings::save('non_indexable_meta_keys', $cleaned_non_indexable_meta_keys);
                }
            }
        }

        // Redirect with success parameter
        $redirect_url = isset($_SERVER['HTTP_REFERER'])
            ? add_query_arg('content_updated', 'true', esc_url($_SERVER['HTTP_REFERER']))
            : admin_url('admin.php?page=meiliscout-settings&content_updated=true');
        wp_redirect($redirect_url);
        exit;
    }
}
