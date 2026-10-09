<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings;

use Pollora\MeiliScout\Config\Settings;
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

    /**
     * The default look's stylesheet (build/listings/style.css).
     */
    public const STYLE = 'meiliscout-listings';

    /**
     * The tokens a palette slug sets, when the theme's palette has it, by order of preference.
     */
    private const PALETTE = [
        'accent' => ['primary', 'accent', 'brand'],
        'accent-contrast' => ['base', 'background', 'white'],
        'text' => ['contrast', 'foreground', 'dark', 'black'],
        'background' => ['base', 'background', 'light', 'white'],
    ];

    public static function registerModule(): void
    {
        $style = dirname(__DIR__, 2).'/build/listings/style.asset.php';
        $style = is_file($style) ? require $style : ['version' => false];
        wp_register_style(self::STYLE, plugins_url('build/listings/style.css', dirname(__DIR__)), [], $style['version'] ?? false);
        wp_add_inline_style(self::STYLE, self::themeColors());

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
     * The state and first page a listing is rendered for, instead of the
     * request's (the fragment endpoint).
     *
     * @var array<string, array{0: ListingState, 1: string}>
     */
    private static array $requests = [];

    /**
     * Renders a listing for a state and a first page rather than the current request's.
     */
    public static function setRequest(string $id, ListingState $state, string $base): void
    {
        self::$requests[$id] = [$state, $base];
        unset(self::$results[$id]);
    }

    /**
     * A listing run for the current request, once per page: its result and its
     * first page; null when it cannot be served.
     *
     * @return array{0: ListingResult, 1: string}|null
     */
    public static function result(string $id): ?array
    {
        if (isset(self::$results[$id])) {
            return self::$results[$id];
        }

        try {
            $definition = DefinitionRegistry::get($id);
        } catch (InvalidListing|\OutOfBoundsException) {
            return null;
        }

        [$state, $base] = self::$requests[$id] ?? [self::requestState($definition), self::baseUrl($definition)];
        $result = ListingQuery::run($definition, $state);

        Store::add($result, $base);
        wp_enqueue_script_module(self::MODULE);
        self::enqueueStyle();
        self::$results[$id] = [$result, $base];

        // Its state is printed by wp_footer(): a template without it does not hydrate
        if (defined('WP_DEBUG') && WP_DEBUG && ! has_action('shutdown', [self::class, 'checkFooter'])) {
            add_action('shutdown', [self::class, 'checkFooter']);
        }

        return self::$results[$id];
    }

    /**
     * The default look, unless the site turned it off.
     */
    public static function enqueueStyle(): void
    {
        /**
         * Filters whether the listings' default look is loaded.
         *
         * @param  bool  $load  Settings › Listings › Default styles (default on).
         */
        if (apply_filters('meiliscout/listings/load_styles', (bool) Settings::get('listings_styles', true))) {
            wp_enqueue_style(self::STYLE);
        }
    }

    /**
     * The colors of the default look, from the theme's palette (theme.json):
     * the tokens point at the presets whose slug is a usual one; currentColor
     * and neutral values otherwise.
     */
    public static function themeColors(): string
    {
        $colors = [];
        foreach (self::detectedColors() as $token => $slug) {
            $colors[$token] = 'var(--wp--preset--color--'.$slug.')';
        }

        // Settings › Listings: a color of the palette, or one of its own
        foreach ((array) Settings::get('listings_colors', []) as $token => $value) {
            if (! isset(self::PALETTE[$token]) || ! is_string($value)) {
                continue;
            }
            if (str_starts_with($value, 'preset:')) {
                $colors[$token] = 'var(--wp--preset--color--'.sanitize_key(substr($value, 7)).')';
            } elseif (sanitize_hex_color($value)) {
                $colors[$token] = $value;
            }
        }

        /**
         * Filters the colors of the listings' default look, by token (accent,
         * accent-contrast, text, muted, background, border): any CSS color.
         *
         * @param  array<string, string>  $colors  The ones found in the theme's palette.
         */
        $colors = (array) apply_filters('meiliscout/listings/theme_colors', $colors);
        $declarations = '';
        foreach ($colors as $token => $value) {
            // A color, nothing that could end the declaration
            if (preg_match('/^[a-z-]+$/', (string) $token) && ! preg_match('/[;{}<>]/', $value)) {
                $declarations .= '--meiliscout-color-'.$token.':'.$value.';';
            }
        }

        return $declarations === '' ? '' : ':root{'.$declarations.'}';
    }

    /**
     * The theme's palette: slug, name, color; WordPress's default colors after, unless left out.
     *
     * @return list<array{slug: string, name: string, color: string}>
     */
    public static function palette(bool $withDefault = true): array
    {
        $palette = function_exists('wp_get_global_settings') ? (array) wp_get_global_settings(['color', 'palette']) : [];
        $colors = [];

        foreach ($withDefault ? ['theme', 'custom', 'default'] : ['theme', 'custom'] as $origin) {
            foreach ((array) ($palette[$origin] ?? []) as $color) {
                $slug = (string) ($color['slug'] ?? '');
                if ($slug !== '' && ! isset($colors[$slug])) {
                    $colors[$slug] = ['slug' => $slug, 'name' => (string) ($color['name'] ?? $slug), 'color' => (string) ($color['color'] ?? '')];
                }
            }
        }

        return array_values($colors);
    }

    /**
     * The palette color each token takes by default: the first usual slug the theme has.
     *
     * @return array<string, string> Slugs, by token
     */
    public static function detectedColors(): array
    {
        // The theme's and the site's colors: WordPress's default palette is on every site
        $slugs = array_column(self::palette(false), 'slug', 'slug');
        $detected = [];

        foreach (self::PALETTE as $token => $candidates) {
            foreach ($candidates as $slug) {
                if (isset($slugs[$slug])) {
                    $detected[$token] = $slug;
                    break;
                }
            }
        }

        // No accent in the palette: the text's color, as many themes' buttons
        if (! isset($detected['accent']) && isset($detected['text'])) {
            $detected['accent'] = $detected['text'];
            if (isset($detected['background'])) {
                $detected['accent-contrast'] = $detected['background'];
            }
        }

        return $detected;
    }

    /**
     * The listing's form, when no part printed it yet on this page.
     */
    public static function form(string $id): string
    {
        $result = self::result($id);

        return $result === null ? '' : wp_interactivity_process_directives(Renderer::formOnce($result[0], $result[1]));
    }

    /**
     * @param  callable(ListingResult, string): string  $render
     */
    private static function withResult(string $id, callable $render): string
    {
        $result = self::result($id);

        if ($result === null) {
            try {
                DefinitionRegistry::get($id);
            } catch (InvalidListing|\OutOfBoundsException $e) {
                return self::error($e->getMessage());
            }

            return '';
        }

        return wp_interactivity_process_directives($render($result[0], $result[1]));
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
        self::$requests = [];
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

        if (isset($definition->route['post'])) {
            return (string) get_permalink($definition->route['post']);
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
            || (isset($definition->route['post']) && is_singular() && get_queried_object_id() === $definition->route['post'])
            || (isset($definition->route['archive']) && is_post_type_archive($definition->route['archive']));
    }
}
