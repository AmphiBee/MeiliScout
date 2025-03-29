<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers\Admin;

use Pollora\MeiliScout\Config\Config;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Foundation\ServiceProvider;

use function Pollora\MeiliScout\get_template_part;

/**
 * Service provider for managing MeiliScout settings in the WordPress admin.
 */
class SettingsServiceProvider extends ServiceProvider
{
    /**
     * Registers the service provider's hooks and actions.
     *
     * @return void
     */
    public function register()
    {
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_post_meiliscout_settings', [$this, 'saveSettings']);
    }

    /**
     * Adds the MeiliScout settings menu to WordPress admin.
     *
     * @return void
     */
    public function addAdminMenu()
    {
        add_menu_page(
            'MeiliScout Settings', // Page title
            'MeiliScout',          // Menu title
            'manage_options',       // Capability
            'meiliscout-settings', // Menu slug
            [$this, 'renderSettingsPage'], // Callback function
            'dashicons-admin-generic',     // Icon
            100                            // Position
        );
    }

    /**
     * Renders the settings page template.
     *
     * @return void
     */
    public function renderSettingsPage()
    {
        $message = isset($_GET['settings_updated']) ? 'Settings have been successfully updated.' : '';

        get_template_part('components/alert', ['message' => $message]);

        get_template_part('settings', [
            'host' => Config::get('meili_host'),
            'key' => Config::get('meili_key'),
        ]);
    }

    /**
     * Handles the settings form submission.
     *
     * @return void
     */
    public function saveSettings()
    {
        // Security nonce verification
        if (! isset($_POST['meiliscout_settings_nonce']) || ! wp_verify_nonce($_POST['meiliscout_settings_nonce'], 'meiliscout_settings_save')) {
            error_log('Nonce verification failed');
            return;
        }

        // User capability check
        if (current_user_can('manage_options')) {
            // Get and sanitize form data
            $meili_host = sanitize_text_field($_POST['meili_host']);
            $meili_key = sanitize_text_field($_POST['meili_key']);

            // Save options to database
            Settings::save('meili_host', $meili_host);
            Settings::save('meili_key', $meili_key);
        } else {
            error_log('User does not have permission to manage options');
        }

        // Redirect with success parameter
        $redirect_url = isset($_SERVER['HTTP_REFERER'])
            ? add_query_arg('settings_updated', 'true', esc_url($_SERVER['HTTP_REFERER']))
            : admin_url('admin.php?page=meiliscout-settings&settings_updated=true');
        wp_redirect($redirect_url);
        exit;
    }
}
