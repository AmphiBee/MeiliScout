<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings;

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Query\ListingQuery;
use Pollora\MeiliScout\Listings\Render\Renderer;
use Pollora\MeiliScout\Listings\Render\Store;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Listings\State\UrlCodec;
use Pollora\MeiliScout\Listings\Transport\RestController;

/**
 * The module's entry points, once it runs.
 */
final class Listings
{
    /**
     * The client's script module (build/listings/view.js).
     */
    public const MODULE = '@meiliscout/listing';

    public static function boot(): void
    {
        add_action('init', [self::class, 'registerModule']);
        add_action('rest_api_init', [RestController::class, 'routes']);
        add_action('template_redirect', [self::class, 'redirectToCanonical'], 0);
    }

    public static function registerModule(): void
    {
        $asset = dirname(__DIR__, 2).'/build/listings/view.asset.php';
        $asset = is_file($asset) ? require $asset : ['dependencies' => [], 'version' => false];

        wp_register_script_module(
            self::MODULE,
            plugins_url('build/listings/view.js', dirname(__DIR__)),
            $asset['dependencies'] ?? [],
            $asset['version'] ?? false
        );
    }

    /**
     * A listing's HTML in the state the current URL asks for.
     *
     * @param  array<string, mixed>  $args
     */
    public static function render(string $id, array $args = []): string
    {
        try {
            $definition = DefinitionRegistry::get($id);
        } catch (InvalidListing|\OutOfBoundsException $e) {
            // Only the people who can fix it see why
            return current_user_can('manage_options')
                ? sprintf('<div class="meiliscout-listing-error" role="alert">%s</div>', esc_html($e->getMessage()))
                : '';
        }

        $base = self::baseUrl($definition);
        $result = ListingQuery::run($definition, self::requestState($definition));

        Store::add($result, $base);
        wp_enqueue_script_module(self::MODULE);

        return wp_interactivity_process_directives(Renderer::listing($result, $base, $args));
    }

    /**
     * The state the current request asks for.
     */
    public static function requestState(ListingDefinition $definition): ListingState
    {
        $page = (int) get_query_var('paged');

        // A static front page paginates with `page`
        if ($page < 1 && is_front_page()) {
            $page = (int) get_query_var('page');
        }

        return UrlCodec::fromQueryString($definition, (string) ($_SERVER['QUERY_STRING'] ?? ''), max(1, $page));
    }

    /**
     * A listing's first page: its declared route, else the current URL without
     * its page and query string.
     */
    public static function baseUrl(ListingDefinition $definition): string
    {
        if (isset($definition->route['page'])) {
            return (string) get_permalink($definition->route['page']);
        }

        if (isset($definition->route['archive'])) {
            return (string) get_post_type_archive_link($definition->route['archive']);
        }

        global $wp;
        $path = $wp instanceof \WP ? (string) $wp->request : '';
        $path = (string) preg_replace('#(^|/)page/\d+/?$#', '', $path);

        return $path === '' ? home_url('/') : home_url(user_trailingslashit($path));
    }

    /**
     * 301 to a listing's canonical query string (the order of its parameters,
     * of its values, a form's separate bounds...) on its declared route.
     */
    public static function redirectToCanonical(): void
    {
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');

        if ($query === '' || ! in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            return;
        }

        foreach (DefinitionRegistry::ids() as $id) {
            try {
                $definition = DefinitionRegistry::get($id);
            } catch (InvalidListing) {
                continue;
            }

            if (! self::isOnRoute($definition)) {
                continue;
            }

            $canonical = UrlCodec::canonicalQuery($definition, $query);

            if ($canonical !== null) {
                $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
                wp_safe_redirect(home_url($path).($canonical !== '' ? '?'.$canonical : ''), 301, 'MeiliScout');
                exit;
            }

            return;
        }
    }

    private static function isOnRoute(ListingDefinition $definition): bool
    {
        return (isset($definition->route['page']) && is_page($definition->route['page']))
            || (isset($definition->route['archive']) && is_post_type_archive($definition->route['archive']));
    }
}
