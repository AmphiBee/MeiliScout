<?php

/**
 * Loads the WordPress site the plugin is installed in, before the unit tests'
 * stand-ins for WordPress functions get a chance to be declared: tests/Pest.php
 * requires this file when MEILISCOUT_INTEGRATION is set (phpunit.integration.xml).
 *
 * Run inside the site, e.g. in the demo's container:
 *   ddev exec --dir /var/www/html/public/wp-content/plugins/meiliscout composer test:integration
 *
 * WP_LOAD_PATH points at another wp-load.php.
 */

$wpLoad = getenv('WP_LOAD_PATH') ?: dirname(__DIR__, 5).'/wp-load.php';

if (! is_file($wpLoad)) {
    fwrite(STDERR, "Integration tests need a WordPress site: no wp-load.php at {$wpLoad} (set WP_LOAD_PATH).\n");
    exit(1);
}

// wp-config.php and wp-settings.php expect to run in the global scope
global $wpdb, $table_prefix, $wp_rewrite, $wp_query, $wp_the_query, $wp, $wp_version, $wp_db_version, $post, $current_user;

$_SERVER['HTTP_HOST'] ??= (string) parse_url((string) getenv('DDEV_PRIMARY_URL') ?: 'http://localhost', PHP_URL_HOST);
$_SERVER['REQUEST_URI'] ??= '/';

define('WP_USE_THEMES', false);
require $wpLoad;
