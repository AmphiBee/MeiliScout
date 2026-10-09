<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Foundation\ServiceProvider;

/**
 * The front listings module: filterable listings of posts, counted by
 * Meilisearch. Off by default (Settings › Listings), and only from the
 * WordPress version whose router it relies on.
 *
 * The functions themes call (meiliscout_register_listing()...) always exist:
 * a theme does not break when the module is off, its listings render nothing.
 */
final class ListingsServiceProvider extends ServiceProvider
{
    /**
     * The interactivity router's client navigation as listings use it.
     */
    public const MIN_WORDPRESS = '6.9';

    public function register(): void
    {
        require_once __DIR__.'/functions.php';

        if (self::unavailable() !== null) {
            return;
        }

        Listings::boot();
    }

    /**
     * Why the module does not run, or null when it does.
     *
     * @return 'disabled'|'wordpress_version'|null
     */
    public static function unavailable(): ?string
    {
        if (! Settings::get('listings_enabled', false)) {
            return 'disabled';
        }

        global $wp_version;

        if (is_string($wp_version) && version_compare($wp_version, self::MIN_WORDPRESS, '<')) {
            return 'wordpress_version';
        }

        return null;
    }
}
