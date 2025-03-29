<?php
/**
 * Plugin Name: MeiliScout
 * Description: Meilisearch integration for WordPress with a modular approach
 * Version: 1.0.0
 * Author: AmphiBee
 * License: MIT
 */

if (!defined('ABSPATH')) {
    exit; // Security: prevent direct access
}

// Define plugin constants
define('MEILISCOUT_DIR_PATH', plugin_dir_path(__FILE__));
define('MEILISCOUT_DIR_URL', plugin_dir_url(__FILE__));

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

use Pollora\MeiliScout\Foundation\Application;

// Initialize and boot the application
$app = new Application();
$app->boot();
