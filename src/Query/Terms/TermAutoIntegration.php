<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Terms;

use Pollora\MeiliScout\Config\Settings;
use WP_Term_Query;

use function apply_filters;

/**
 * Which term queries Meilisearch serves without asking: the editor's term searches, REST searches, admin term lists.
 *
 * Off by default, set in Settings > Queries. A query's own use_meilisearch
 * wins either way: true asks for Meilisearch, false keeps it on MySQL.
 */
final class TermAutoIntegration
{
    public const SETTING = 'term_query_integration';

    /**
     * What can be turned on, all off by default.
     */
    public const DEFAULTS = ['search' => false, 'rest_search' => false, 'admin' => false];

    /**
     * @return array{search: bool, rest_search: bool, admin: bool}
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
     * Whether Meilisearch serves a term query.
     */
    public static function wants(WP_Term_Query $query): bool
    {
        if (array_key_exists('use_meilisearch', $query->query_vars)) {
            return (bool) $query->query_vars['use_meilisearch'];
        }

        /**
         * Filters whether a term query that did not ask stays on MySQL.
         *
         * @param  bool  $skip  Default false.
         * @param  WP_Term_Query  $query
         */
        if (apply_filters('meiliscout/skip_term_query_integration', false, $query)) {
            return false;
        }

        /**
         * Filters whether Meilisearch serves a term query that did not ask: the last word.
         *
         * @param  bool  $integrate  Whether the settings cover it.
         * @param  WP_Term_Query  $query
         */
        return (bool) apply_filters('meiliscout/integrate_term_query', self::covers($query), $query);
    }

    /**
     * Whether the settings cover a term query that did not ask.
     *
     * The classic editor's tag box searches with ajax-tag-search (name__like);
     * the block editor's panels through the REST API, which asks with
     * restQuery(); the admin's term lists are edit-tags.php.
     */
    public static function covers(WP_Term_Query $query): bool
    {
        $settings = self::settings();

        if (wp_doing_ajax()) {
            return $settings['search'] && ($_REQUEST['action'] ?? null) === 'ajax-tag-search';
        }

        return $settings['admin'] && is_admin() && ($GLOBALS['pagenow'] ?? null) === 'edit-tags.php';
    }

    /**
     * Asks for Meilisearch on the REST API's term searches, when the settings say so. Hooked on rest_{$taxonomy}_query.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function restQuery(array $args): array
    {
        if (! empty($args['search']) && ! array_key_exists('use_meilisearch', $args) && self::settings()['rest_search']) {
            $args['use_meilisearch'] = true;
        }

        return $args;
    }
}
