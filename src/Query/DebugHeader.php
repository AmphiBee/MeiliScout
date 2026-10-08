<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use function apply_filters;

/**
 * Tells in a response header whether Meilisearch served the page's main query: X-MeiliScout: served, or fallback:<reason>.
 *
 * Sent with WP_DEBUG on, or to administrators; the meiliscout/debug_header filter decides otherwise.
 */
final class DebugHeader
{
    /**
     * Hooked on wp, once the main query ran and before the page is sent.
     */
    public static function send(): void
    {
        global $wp_query;

        $info = $wp_query->meiliscout ?? null;

        if (! is_array($info) || headers_sent()) {
            return;
        }

        $enabled = (defined('WP_DEBUG') && WP_DEBUG) || current_user_can('manage_options');

        /**
         * Filters whether the X-MeiliScout header is sent.
         *
         * @param  bool  $enabled  WP_DEBUG on, or an administrator.
         */
        if (! apply_filters('meiliscout/debug_header', $enabled)) {
            return;
        }

        header('X-MeiliScout: '.self::value($info));
    }

    /**
     * @param  array<string, mixed>  $info
     */
    public static function value(array $info): string
    {
        if (! empty($info['served'])) {
            return 'served';
        }

        // A header holds no line break, and keeps to ASCII
        return 'fallback:'.preg_replace('/[^\x20-\x7E]/', '', (string) ($info['reason'] ?? 'unknown'));
    }
}
