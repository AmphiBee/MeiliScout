<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers\Admin;

use Pollora\MeiliScout\Admin\Rest\ContentController;
use Pollora\MeiliScout\Admin\Rest\Controller;
use Pollora\MeiliScout\Admin\Rest\IndexationController;
use Pollora\MeiliScout\Admin\Rest\OverviewController;
use Pollora\MeiliScout\Admin\Rest\SearchPreviewController;
use Pollora\MeiliScout\Admin\Rest\SettingsController;
use Pollora\MeiliScout\Foundation\ServiceProvider;

use function add_action;
use function add_menu_page;
use function admin_url;
use function esc_html__;
use function load_plugin_textdomain;
use function plugin_basename;
use function wp_add_inline_script;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_json_encode;
use function wp_set_script_translations;
use function wp_style_add_data;

/**
 * The MeiliScout admin: one page, where a React app renders every screen
 * from the REST endpoints under meiliscout/v1.
 */
class AdminServiceProvider extends ServiceProvider
{
    public const PAGE = 'meiliscout';

    private const HANDLE = 'meiliscout-admin';

    /**
     * @var list<class-string<Controller>>
     */
    private const CONTROLLERS = [
        OverviewController::class,
        ContentController::class,
        IndexationController::class,
        SearchPreviewController::class,
        SettingsController::class,
    ];

    private string $hookSuffix = '';

    public function register(): void
    {
        add_action('init', [$this, 'loadTextdomain']);
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function loadTextdomain(): void
    {
        load_plugin_textdomain('meiliscout', false, dirname(plugin_basename(MEILISCOUT_DIR_PATH.'plugin.php')).'/languages');
    }

    public function addMenu(): void
    {
        $this->hookSuffix = (string) add_menu_page(
            'MeiliScout',
            'MeiliScout',
            'manage_options',
            self::PAGE,
            [$this, 'render'],
            'dashicons-search',
            100
        );
    }

    public function registerRoutes(): void
    {
        foreach (self::CONTROLLERS as $controller) {
            (new $controller)->registerRoutes();
        }
    }

    /**
     * The app's mount point; it renders the screen in the URL's hash.
     */
    public function render(): void
    {
        printf(
            '<div id="meiliscout-admin" class="meiliscout-admin"><p class="meiliscout-admin__loading">%s</p></div>',
            esc_html__('Loading…', 'meiliscout')
        );
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== $this->hookSuffix) {
            return;
        }

        $assetFile = MEILISCOUT_DIR_PATH.'build/app.asset.php';

        if (! file_exists($assetFile)) {
            return;
        }

        $asset = require $assetFile;

        wp_enqueue_script(self::HANDLE, MEILISCOUT_DIR_URL.'build/app.js', $asset['dependencies'], $asset['version'], true);
        // The asset file's version only follows the script
        $styleVersion = (string) filemtime(MEILISCOUT_DIR_PATH.'build/app.css');
        wp_enqueue_style(self::HANDLE, MEILISCOUT_DIR_URL.'build/app.css', [], $styleVersion);
        wp_style_add_data(self::HANDLE, 'rtl', 'replace');
        wp_set_script_translations(self::HANDLE, 'meiliscout', MEILISCOUT_DIR_PATH.'languages');

        wp_add_inline_script(self::HANDLE, 'window.meiliscoutAdmin = '.wp_json_encode([
            'version' => MEILISCOUT_VERSION,
            'pageUrl' => admin_url('admin.php?page='.self::PAGE),
            'docsUrl' => 'https://github.com/AmphiBee/MeiliScout#readme',
        ]).';', 'before');
    }
}
