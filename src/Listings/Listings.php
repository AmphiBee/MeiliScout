<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings;

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Query\ListingQuery;
use Pollora\MeiliScout\Listings\Query\ListingResult;
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
     * Each listing's result on this page, run once whatever the parts printed.
     *
     * @var array<string, array{0: ListingResult, 1: string}>
     */
    private static array $results = [];

    /**
     * A listing's HTML in the state the current URL asks for.
     *
     * @param  array<string, mixed>  $args  search: false leaves the search field out
     */
    public static function render(string $id, array $args = []): string
    {
        return self::withResult($id, fn (ListingResult $result, string $base) => Renderer::listing($result, $base, $args));
    }

    /**
     * One part of a listing (Renderer::PARTS), for a template that places them itself.
     *
     * @param  array<string, mixed>  $args  facet: the facet's key
     */
    public static function part(string $id, string $part, array $args = []): string
    {
        return self::withResult($id, function (ListingResult $result, string $base) use ($part, $args): string {
            try {
                return Renderer::part($result, $base, $part, $args);
            } catch (\InvalidArgumentException $e) {
                return self::error($e->getMessage());
            }
        });
    }

    /**
     * @param  callable(ListingResult, string): string  $render
     */
    private static function withResult(string $id, callable $render): string
    {
        if (! isset(self::$results[$id])) {
            try {
                $definition = DefinitionRegistry::get($id);
            } catch (InvalidListing|\OutOfBoundsException $e) {
                return self::error($e->getMessage());
            }

            $base = self::baseUrl($definition);
            $result = ListingQuery::run($definition, self::requestState($definition));

            Store::add($result, $base);
            wp_enqueue_script_module(self::MODULE);
            self::$results[$id] = [$result, $base];

            // Its state is printed by wp_footer(): a template without it does not hydrate
            if (defined('WP_DEBUG') && WP_DEBUG && ! has_action('shutdown', [self::class, 'checkFooter'])) {
                add_action('shutdown', [self::class, 'checkFooter']);
            }
        }

        [$result, $base] = self::$results[$id];

        return wp_interactivity_process_directives($render($result, $base));
    }

    /**
     * Debug: a page printed listings without calling wp_footer().
     */
    public static function checkFooter(): void
    {
        if (! did_action('wp_footer') && PHP_SAPI !== 'cli' && ! wp_doing_ajax() && ! (defined('REST_REQUEST') && REST_REQUEST)) {
            _doing_it_wrong('meiliscout_listing', 'A listing was printed on a page that does not call wp_footer(): its state is never printed, and it does not react.', '2.1.0');
        }
    }

    /**
     * Only the people who can fix it see why a listing does not show.
     */
    private static function error(string $message): string
    {
        return current_user_can('manage_options')
            ? sprintf('<div class="meiliscout-listing-error" role="alert">%s</div>', esc_html($message))
            : '';
    }

    /**
     * Forgets the listings run on this page (tests).
     */
    public static function forget(): void
    {
        self::$results = [];
        Renderer::forgetForms();
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

        if (! in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
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

            // UrlCodec has the last word: redirect_canonical() writes a page's
            // query string again, the comma between two values as %2C (one value)
            remove_action('template_redirect', 'redirect_canonical');

            $canonical = $query === '' ? null : UrlCodec::canonicalQuery($definition, $query);

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
