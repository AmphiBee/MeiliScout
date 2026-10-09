<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Admin;

use Pollora\MeiliScout\Admin\Rest\Controller;
use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;
use Pollora\MeiliScout\Listings\Diagnostics\CountParity;
use Pollora\MeiliScout\Listings\Diagnostics\ListingChecks;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\Seo\Adapters\Adapters;
use Pollora\MeiliScout\Listings\Seo\Sitemap\SitemapEntries;
use Pollora\MeiliScout\Listings\Seo\Sitemap\Sitemaps;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Listings screen (design §13): the declared listings, PHP and blocks,
 * with their URL in each language, facets, prefixes, parameters and checks;
 * and their counts against MySQL, on demand.
 */
final class ListingsController extends Controller
{
    public function registerRoutes(): void
    {
        $this->route('/listings', 'GET', [$this, 'index']);
        $this->route('/listings/(?P<id>[a-z0-9][a-z0-9_-]*)/parity', 'GET', [$this, 'parity'], [
            'lang' => ['type' => 'string', 'default' => ''],
        ]);
    }

    public function index(): WP_REST_Response
    {
        $adapter = Languages::adapter();

        return $this->respond([
            'listings' => ListingChecks::all(),
            'languages' => array_map(fn (string $language) => ['code' => $language, 'locale' => $adapter->locale($language)], $adapter->languages()),
            'seo_adapter' => Adapters::current()->name(),
            // MeiliScout's own sitemap of the views: the SEO plugin's index names them too
            'sitemap' => ['url' => Sitemaps::url(), 'views' => count(SitemapEntries::all())],
        ]);
    }

    public function parity(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $definition = DefinitionRegistry::get((string) $request['id']);
        } catch (InvalidListing|\OutOfBoundsException $e) {
            return new WP_Error('meiliscout_listing', $e->getMessage(), ['status' => 404]);
        }

        $language = (string) $request['lang'];
        if ($language !== '' && ! in_array($language, Languages::adapter()->languages(), true)) {
            return new WP_Error('meiliscout_listing', __('Unknown language.', 'meiliscout'), ['status' => 400]);
        }

        return $this->respond(CountParity::compare($definition, $language));
    }
}
