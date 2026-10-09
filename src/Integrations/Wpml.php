<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Integrations;

use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Query\QueryVars;

/**
 * WPML (checked on 4.9, design §12.4, prototype P7). Without it, WPML's SQL
 * filters send every query to MySQL (sql_filter:posts_where).
 *
 * - Documents carry their post's language (`language`, filterable).
 * - Terms are read without WPML's adjustment to the current language: an
 *   English post indexed from French would get French terms.
 * - WPML's three SQL callbacks are declared harmless, and their condition
 *   written for Meilisearch: (language = current AND post_type IN
 *   translatable) OR post_type NOT IN translatable.
 * - Until a full indexation wrote the language, and for the post types WPML
 *   shows in their original language when untranslated (a subquery per
 *   post), queries run on MySQL: wpml_not_indexed, wpml_display_as_translated.
 */
final class Wpml
{
    /**
     * The option recording that the active posts index has the language.
     */
    private const INDEXED = 'meiliscout_wpml_language_indexed';

    public const FIELD = 'language';

    private const CALLBACKS = [
        'WPML_Query_Filter::posts_join_filter',
        'WPML_Query_Filter::posts_where_filter',
        'WPML_Display_As_Translated_Tax_Query::posts_where_filter',
    ];

    public static function active(): bool
    {
        return defined('ICL_SITEPRESS_VERSION');
    }

    public static function boot(): void
    {
        // WPML loads after MeiliScout
        add_action('plugins_loaded', [self::class, 'register'], 20);
    }

    public static function register(): void
    {
        if (! self::active()) {
            return;
        }

        add_filter('meiliscout/post/document', [self::class, 'document'], 10, 2);
        add_filter('meiliscout/post/term_reader', fn () => [self::class, 'withoutTermAdjustment']);
        add_filter('meiliscout/post/filterable_attributes', fn (array $attributes) => [...$attributes, self::FIELD]);
        add_filter('meiliscout/ignored_sql_filters', [self::class, 'ignored']);
        add_filter('meiliscout/search_params', [self::class, 'searchParams'], 10, 2);
        add_filter('meiliscout/fallback_reason', [self::class, 'fallback'], 10, 2);
        add_action('meiliscout/indexes_activated', [self::class, 'indexed']);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function document(array $document, mixed $post): array
    {
        $id = is_object($post) ? (int) $post->ID : (int) $post;
        $details = apply_filters('wpml_post_language_details', null, $id);
        $document[self::FIELD] = is_array($details) ? (string) ($details['language_code'] ?? '') : '';

        return $document;
    }

    /**
     * Runs a callback without WPML's adjustment of terms to the current
     * language, as WPML does itself (DeleteTranslatedContentOfLanguages).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutTermAdjustment(callable $callback): mixed
    {
        global $sitepress;

        if (! is_object($sitepress)) {
            return $callback();
        }

        $filters = [
            ['get_terms_args', [$sitepress, 'get_terms_args_filter'], 10, 2],
            ['get_term', [$sitepress, 'get_term_adjust_id'], 1, 1],
            ['terms_clauses', [$sitepress, 'terms_clauses'], 10, 3],
        ];
        $removed = array_filter($filters, fn (array $filter) => remove_filter($filter[0], $filter[1], $filter[2]));

        try {
            return $callback();
        } finally {
            foreach ($removed as $filter) {
                add_filter(...$filter);
            }
        }
    }

    /**
     * WPML's SQL callbacks, once the index has the language and no post type
     * shows its original when untranslated.
     *
     * @param  array<int, mixed>  $ignored
     * @return array<int, mixed>
     */
    public static function ignored(array $ignored): array
    {
        return self::translatable() && self::displayedAsTranslated() === [] ? [...$ignored, ...self::CALLBACKS] : $ignored;
    }

    /**
     * WPML's WHERE, for Meilisearch.
     *
     * @param  array<string, mixed>  $params
     */
    public static function searchParams(array $params, object $query): array
    {
        $language = (string) apply_filters('wpml_current_language', '');

        if (! self::translatable() || $language === '' || $language === 'all' || (method_exists($query, 'get') && $query->get('suppress_filters'))) {
            return $params;
        }

        $types = self::translatableTypes();
        if ($types === []) {
            return $params;
        }

        $list = implode(', ', array_map(fn (string $type) => "'".addslashes($type)."'", $types));
        $clause = sprintf("((%s = '%s' AND post_type IN [%s]) OR post_type NOT IN [%s])", self::FIELD, addslashes($language), $list, $list);
        $params['filter'] = isset($params['filter']) && $params['filter'] !== '' ? '('.$params['filter'].') AND '.$clause : $clause;

        return $params;
    }

    /**
     * Why a query stays on MySQL.
     */
    public static function fallback(mixed $reason, QueryInterface $query): mixed
    {
        if ($reason !== null || $query->get('suppress_filters')) {
            return $reason;
        }

        if (! self::translatable()) {
            return 'wpml_not_indexed';
        }

        $shown = self::displayedAsTranslated();
        $types = QueryVars::postTypes($query);
        $types = is_array($types) ? $types : [];

        return $shown !== [] && ($types === [] || array_intersect($types, $shown) !== []) ? 'wpml_display_as_translated' : null;
    }

    /**
     * A full indexation wrote the language in the posts index.
     *
     * @param  list<string>  $bases
     */
    public static function indexed(array $bases): void
    {
        if (in_array('posts', $bases, true)) {
            update_option(self::INDEXED, 1, true);
        }
    }

    /**
     * The active posts index has the language.
     */
    public static function translatable(): bool
    {
        return (bool) get_option(self::INDEXED, false);
    }

    /**
     * @return list<string>
     */
    private static function translatableTypes(): array
    {
        global $sitepress;

        return is_object($sitepress) ? array_values(array_filter(array_keys((array) $sitepress->get_translatable_documents()), 'is_string')) : [];
    }

    /**
     * The post types shown in their original language when untranslated.
     *
     * @return list<string>
     */
    private static function displayedAsTranslated(): array
    {
        global $sitepress;

        return is_object($sitepress) && method_exists($sitepress, 'get_display_as_translated_documents')
            ? array_values(array_filter(array_keys((array) $sitepress->get_display_as_translated_documents()), 'is_string'))
            : [];
    }
}
