<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Pollora\MeiliScout\Config\Settings;
use WP_Query;

use function apply_filters;

/**
 * Which queries Meilisearch serves without asking: the site's search, archives, REST searches, admin lists.
 *
 * Off by default, set in Settings > Queries. A query's own use_meilisearch
 * wins either way: true asks for Meilisearch, false keeps it on MySQL.
 * AJAX queries are served only when they ask.
 */
final class AutoIntegration
{
    public const SETTING = 'query_integration';

    /**
     * What can be turned on, all off by default.
     */
    public const DEFAULTS = ['search' => false, 'archives' => false, 'rest_search' => false, 'admin' => false];

    /**
     * @return array{search: bool, archives: bool, rest_search: bool, admin: bool}
     */
    public static function settings(): array
    {
        $saved = Settings::get(self::SETTING, []);
        $settings = self::DEFAULTS;

        foreach (self::DEFAULTS as $key => $default) {
            $settings[$key] = (bool) (is_array($saved) ? ($saved[$key] ?? $default) : $default);
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function save(array $settings): void
    {
        $clean = [];

        foreach (self::DEFAULTS as $key => $default) {
            $clean[$key] = (bool) ($settings[$key] ?? $default);
        }

        Settings::save(self::SETTING, $clean);
    }

    /**
     * Whether Meilisearch serves a query.
     */
    public static function wants(WP_Query $query): bool
    {
        if (array_key_exists('use_meilisearch', $query->query_vars)) {
            return (bool) $query->query_vars['use_meilisearch'];
        }

        /**
         * Filters whether a query that did not ask stays on MySQL.
         *
         * @param  bool  $skip  Default false.
         * @param  WP_Query  $query
         */
        if (apply_filters('meiliscout/skip_query_integration', false, $query)) {
            return false;
        }

        /**
         * Filters whether Meilisearch serves a query that did not ask: the last word.
         *
         * @param  bool  $integrate  Whether the settings cover it.
         * @param  WP_Query  $query
         */
        return (bool) apply_filters('meiliscout/integrate_query', self::covers($query), $query);
    }

    /**
     * Whether the settings cover a query that did not ask.
     */
    public static function covers(WP_Query $query): bool
    {
        if (wp_doing_ajax() || ! $query->is_main_query()) {
            return false;
        }

        $settings = self::settings();

        if (is_admin()) {
            return $settings['admin'];
        }

        if ($query->is_search()) {
            return $settings['search'];
        }

        return $settings['archives']
            && ($query->is_post_type_archive() || $query->is_category() || $query->is_tag() || $query->is_tax());
    }

    /**
     * Asks for Meilisearch on the REST API's searches, when the settings say so. Hooked on rest_{$type}_query.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function restQuery(array $args): array
    {
        if (! empty($args['s']) && ! array_key_exists('use_meilisearch', $args) && self::settings()['rest_search']) {
            $args['use_meilisearch'] = true;
        }

        return $args;
    }
}
