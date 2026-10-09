<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo\Sitemap;

/**
 * The listings' indexable views in the sitemap of the SEO plugin in use
 * (design §8.6): a provider of its own for WordPress's sitemaps, Yoast SEO
 * and Rank Math; for SEOPress and All in One SEO, which take no provider, a
 * sitemap of MeiliScout's own (/meiliscout-listings-sitemap.xml) named in
 * their index. That sitemap answers whatever the plugin.
 */
final class Sitemaps
{
    /**
     * The path of MeiliScout's own sitemap.
     */
    public const PATH = '/meiliscout-listings-sitemap.xml';

    /**
     * Their name in WordPress's, Yoast's and Rank Math's sitemaps.
     */
    public const TYPE = 'meiliscout-listing';

    public static function boot(): void
    {
        add_action('init', [self::class, 'registerCore']);
        add_filter('wpseo_sitemaps_providers', [self::class, 'yoast']);
        add_filter('rank_math/sitemap/providers', [self::class, 'rankMath']);
        add_filter('seopress_sitemaps_external_link', [self::class, 'seoPress']);
        add_filter('aioseo_sitemap_indexes', [self::class, 'aioseo']);
        add_action('parse_request', [self::class, 'serve'], 0);
    }

    public static function registerCore(): void
    {
        if (function_exists('wp_register_sitemap_provider')) {
            wp_register_sitemap_provider(CoreProvider::NAME, new CoreProvider);
        }
    }

    /**
     * @param  array<int, object>  $providers
     * @return array<int, object>
     */
    public static function yoast(array $providers): array
    {
        if (interface_exists(\WPSEO_Sitemap_Provider::class)) {
            $providers[] = new YoastProvider;
        }

        return $providers;
    }

    /**
     * @param  array<int, object>  $providers
     * @return array<int, object>
     */
    public static function rankMath(array $providers): array
    {
        if (interface_exists(\RankMath\Sitemap\Providers\Provider::class)) {
            $providers[] = new RankMathProvider;
        }

        return $providers;
    }

    /**
     * @param  mixed  $sitemaps
     * @return list<array{sitemap_url: string}>
     */
    public static function seoPress(mixed $sitemaps): array
    {
        $sitemaps = is_array($sitemaps) ? array_values($sitemaps) : [];

        if (SitemapEntries::all() !== []) {
            $sitemaps[] = ['sitemap_url' => self::url()];
        }

        return $sitemaps;
    }

    /**
     * @param  array<int, array<string, mixed>>  $indexes
     * @return array<int, array<string, mixed>>
     */
    public static function aioseo(array $indexes): array
    {
        $count = count(SitemapEntries::all());

        if ($count > 0) {
            $indexes[] = ['loc' => self::url(), 'lastmod' => gmdate(DATE_W3C), 'count' => $count];
        }

        return $indexes;
    }

    public static function url(): string
    {
        return home_url(self::PATH);
    }

    /**
     * MeiliScout's own sitemap, before WordPress resolves the URL.
     */
    public static function serve(\WP $wp): void
    {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');

        if ($path !== $home.self::PATH) {
            return;
        }

        status_header(200);
        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow');
        echo self::xml(SitemapEntries::all()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by xml()
        exit;
    }

    /**
     * @param  list<array{loc: string, listing: string, language: string}>  $entries
     */
    public static function xml(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($entries as $entry) {
            $xml .= "\t<url><loc>".esc_xml($entry['loc'])."</loc></url>\n";
        }

        return $xml.'</urlset>'."\n";
    }
}
