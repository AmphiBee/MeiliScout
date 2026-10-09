<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\State;

/**
 * Query string names a listing's parameters must not take: WordPress would read
 * them as its own query vars, another plugin would, or a page cache drops them.
 */
final class ReservedParameters
{
    /**
     * Read by WordPress, WooCommerce, or stripped by page caches (Varnish, CDNs).
     */
    private const NAMES = [
        // WordPress, besides its public query vars
        'p', 'page', 'paged', 'page_id', 'preview', 'preview_id', 'preview_nonce', 'replytocom', 'rest_route', 'customize_changeset_uuid', 'doing_wp_cron', '_wpnonce', 'lang',
        // WooCommerce
        'add-to-cart', 'orderby', 'min_price', 'max_price', 'rating_filter', 'product_cat', 'product_tag', 'wc-ajax', 'filter_',
        // Campaign parameters page caches ignore
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', '_ga',
    ];

    public static function isReserved(string $name): bool
    {
        global $wp;

        if (in_array($name, self::NAMES, true) || str_starts_with($name, 'utm_') || str_starts_with($name, 'filter_') || str_starts_with($name, 'query-')) {
            return true;
        }

        $public = $wp instanceof \WP ? $wp->public_query_vars : [];

        return in_array($name, $public, true);
    }
}
