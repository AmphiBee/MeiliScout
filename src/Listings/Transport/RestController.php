<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Transport;

use Pollora\MeiliScout\Listings\Blocks\BlockListings;
use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\Query\ListingQuery;
use Pollora\MeiliScout\Listings\Render\Renderer;
use Pollora\MeiliScout\Listings\Render\Store;
use Pollora\MeiliScout\Listings\Seo\SeoPolicy;
use Pollora\MeiliScout\Listings\State\UrlCodec;

/**
 * The listings' public endpoints, under meiliscout/v1/listings/{id}:
 *
 * - token: a fresh tenant token, when the one in the page expired.
 * - fragment?url=: the results and pagination of the listing at that URL, in
 *   a minimal document the interactivity router takes as a page
 *   (navigate(url, {html})). GET, cacheable by URL; a listing with
 *   personalised cards is private.
 */
final class RestController
{
    private const NAMESPACE = 'meiliscout/v1';

    public static function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/listings/(?P<id>[a-z0-9][a-z0-9_-]*)/token', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'token'],
        ]);

        register_rest_route(self::NAMESPACE, '/listings/(?P<id>[a-z0-9][a-z0-9_-]*)/fragment', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'args' => ['url' => ['type' => 'string', 'required' => true]],
            'callback' => [self::class, 'fragment'],
        ]);
    }

    public static function token(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $definition = self::definition((string) $request['id']);

        if ($definition instanceof \WP_Error) {
            return $definition;
        }

        $token = TenantTokens::forListing($definition);

        if ($token === null) {
            return new \WP_Error('meiliscout_no_token', 'No token can be signed.', ['status' => 503]);
        }

        $response = new \WP_REST_Response($token);
        $response->header('Cache-Control', 'public, max-age=300');

        return $response;
    }

    public static function fragment(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $definition = self::definition((string) $request['id']);

        if ($definition instanceof \WP_Error) {
            return $definition;
        }

        // A path on this site: the URL of the listing's page the client goes to
        $url = (string) $request['url'];
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);

        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return new \WP_Error('meiliscout_bad_url', 'The url must be a path on this site.', ['status' => 400]);
        }

        $page = preg_match('#/page/(\d+)/?$#', $path, $m) ? (int) $m[1] : 1;
        $base = $definition->route !== [] ? Listings::baseUrl($definition) : home_url(user_trailingslashit((string) preg_replace('#/page/\d+/?$#', '', $path)));
        // Facets in the path, after the listing's first page
        $state = UrlCodec::fromRequest($definition, $path, $query, $base) ?? UrlCodec::fromQueryString($definition, $query, $page);
        // A block listing renders its block again (its Post Template, its pagination); the router takes its regions
        $region = BlockListings::isBlock($definition->id) ? BlockListings::fragment($definition->id, $state, $base) : null;

        if ($region === null) {
            $result = ListingQuery::run($definition, $state);
            Store::add($result, $base);
            $region = wp_interactivity_process_directives(Renderer::regions($result, $base));
        } else {
            $result = Listings::result($definition->id)[0] ?? null;
        }

        // The router reads its URL from this state after setting the one it navigated to (prototype P1)
        $data = ['state' => ['core/router' => ['url' => UrlCodec::url($definition, $state, $base)]]];
        $seoTitle = $result !== null ? SeoPolicy::viewOf($result, $base)->title : '';
        $title = $definition->route !== [] ? sprintf('<title>%s</title>', esc_html($seoTitle !== '' ? $seoTitle : self::title($definition, $state->page))) : '';
        $html = '<!doctype html><html><head>'.$title
            .'<script type="application/json" id="wp-script-module-data-@wordpress/interactivity">'.wp_json_encode($data, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES).'</script>'
            .'</head><body>'.$region.'</body></html>';

        $private = $definition->personalised;
        add_filter('rest_pre_serve_request', static function ($served) use ($html, $private) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: '.($private ? 'private, no-store' : 'public, max-age=60'));
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by the renderer

            return true;
        });

        return new \WP_REST_Response(null);
    }

    /**
     * The document title of a listing's page, as wp_get_document_title() writes it.
     */
    private static function title(ListingDefinition $definition, int $page): string
    {
        $post = $definition->route['page'] ?? $definition->route['post'] ?? null;
        $title = $post !== null
            ? get_the_title($post)
            : (string) (get_post_type_object((string) ($definition->route['archive'] ?? ''))?->labels->name ?? '');

        $parts = array_filter([
            'title' => html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            /* translators: %s: page number */
            'page' => $page > 1 ? sprintf(__('Page %s'), $page) : null,
            'site' => get_bloginfo('name', 'display'),
        ]);

        $parts = apply_filters('document_title_parts', $parts);

        return implode(' '.apply_filters('document_title_separator', '-').' ', $parts);
    }

    private static function definition(string $id): ListingDefinition|\WP_Error
    {
        try {
            return DefinitionRegistry::get($id);
        } catch (InvalidListing|\OutOfBoundsException) {
            return new \WP_Error('meiliscout_unknown_listing', 'Unknown listing.', ['status' => 404]);
        }
    }
}
