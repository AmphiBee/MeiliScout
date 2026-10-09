<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo\Sitemap;

use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\Language\Polylang;

/**
 * The listings' views in WordPress's sitemaps: /wp-sitemap-listings-1.xml.
 */
final class CoreProvider extends \WP_Sitemaps_Provider
{
    /**
     * Letters only: WordPress's rewrite rule reads the name as [a-z]+.
     */
    public const NAME = 'listings';

    public function __construct()
    {
        $this->name = self::NAME;
        $this->object_type = Sitemaps::TYPE;
    }

    /**
     * @param  int  $page_num
     * @param  string  $object_subtype
     * @return list<array{loc: string}>
     */
    public function get_url_list($page_num, $object_subtype = '')
    {
        $size = wp_sitemaps_get_max_urls($this->object_type);
        $entries = array_slice(self::entries(), ((int) $page_num - 1) * $size, $size);

        return array_map(fn (array $entry) => ['loc' => $entry['loc']], $entries);
    }

    /**
     * @param  string  $object_subtype
     * @return int
     */
    public function get_max_num_pages($object_subtype = '')
    {
        return (int) ceil(count(self::entries()) / wp_sitemaps_get_max_urls($this->object_type));
    }

    /**
     * Polylang serves WordPress's sitemaps once per language (/en/wp-sitemap-listings-1.xml),
     * in that language: each lists its language's views.
     *
     * @return list<array{loc: string, listing: string, language: string}>
     */
    private static function entries(): array
    {
        $entries = SitemapEntries::all();
        $language = Languages::current();

        if (! Languages::adapter() instanceof Polylang || $language === '') {
            return $entries;
        }

        return array_values(array_filter($entries, fn (array $entry) => $entry['language'] === $language));
    }
}
