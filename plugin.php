<?php

/**
 * Plugin Name: MeiliScout
 * Description: Meilisearch integration for WordPress with a modular approach
 * Version: 2.0.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Author: AmphiBee
 * License: MIT
 * Text Domain: meiliscout
 * Domain Path: /languages
 */
if (! defined('ABSPATH')) {
    exit; // Security: prevent direct access
}

// Define plugin constants
define('MEILISCOUT_DIR_PATH', plugin_dir_path(__FILE__));
define('MEILISCOUT_DIR_URL', plugin_dir_url(__FILE__));
define('MEILISCOUT_VERSION', '2.0.0');

// Load the plugin's own dependencies when it ships them (release zip). Installed
// with Composer as a dependency of the site, the site's autoloader has them.
if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
}

if (! class_exists(Pollora\MeiliScout\Foundation\Application::class)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            .esc_html__('MeiliScout cannot start: its dependencies are missing. Install the release zip, or run composer install.', 'meiliscout')
            .'</p></div>';
    });

    return;
}

use Pollora\MeiliScout\Foundation\Application;

// Initialize and boot the application
$app = new Application;
$app->boot();
