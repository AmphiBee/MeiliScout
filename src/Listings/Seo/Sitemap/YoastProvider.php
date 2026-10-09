<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo\Sitemap;

/**
 * The listings' views in Yoast SEO's sitemaps: meiliscout-listing-sitemap.xml.
 * Loaded only when Yoast is (Sitemaps::yoast()).
 */
final class YoastProvider implements \WPSEO_Sitemap_Provider
{
    /**
     * @param  string  $type
     * @return bool
     */
    public function handles_type($type)
    {
        return $type === Sitemaps::TYPE;
    }

    /**
     * @param  int  $max_entries
     * @return list<array{loc: string, lastmod: string}>
     */
    public function get_index_links($max_entries)
    {
        $pages = (int) ceil(count(SitemapEntries::all()) / max(1, (int) $max_entries));
        $links = [];

        for ($page = 1; $page <= $pages; $page++) {
            $links[] = [
                'loc' => \WPSEO_Sitemaps_Router::get_base_url(Sitemaps::TYPE.'-sitemap'.($page > 1 ? $page : '').'.xml'),
                'lastmod' => gmdate(DATE_W3C),
            ];
        }

        return $links;
    }

    /**
     * @param  string  $type
     * @param  int  $max_entries
     * @param  int  $current_page
     * @return list<array{loc: string}>
     */
    public function get_sitemap_links($type, $max_entries, $current_page)
    {
        $entries = array_slice(SitemapEntries::all(), (max(1, (int) $current_page) - 1) * (int) $max_entries, (int) $max_entries);

        return array_map(fn (array $entry) => ['loc' => $entry['loc']], $entries);
    }
}
