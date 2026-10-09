<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Language;

/**
 * Polylang (checked on 3.8, prototype P7). Its languages are a taxonomy,
 * `language`, that posts are indexed with: the base filter is a tax_query on
 * it, translated by the builders like any other.
 */
final class Polylang implements LanguageAdapter
{
    public static function active(): bool
    {
        return function_exists('pll_current_language') && function_exists('PLL');
    }

    public function current(): string
    {
        return (string) pll_current_language();
    }

    public function default(): string
    {
        return (string) pll_default_language();
    }

    public function languages(): array
    {
        return array_values(array_map('strval', (array) pll_languages_list(['fields' => 'slug'])));
    }

    public function locale(string $language): string
    {
        $object = PLL()->model->get_language($language);

        return $object ? (string) $object->locale : get_locale();
    }

    public function post(int $postId, string $language): ?int
    {
        // An untranslatable post (a type Polylang leaves alone) is every language's
        if (! pll_is_translated_post_type((string) get_post_type($postId))) {
            return $postId;
        }

        $translated = pll_get_post($postId, $language);

        return $translated ? (int) $translated : null;
    }

    public function postLanguage(int $postId): string
    {
        return (string) pll_get_post_language($postId);
    }

    public function termLanguage(\WP_Term $term): string
    {
        return (string) pll_get_term_language($term->term_id);
    }

    public function translateTerm(\WP_Term $term, string $language): ?\WP_Term
    {
        if (! pll_is_translated_taxonomy($term->taxonomy)) {
            return $term;
        }

        $translated = pll_get_term($term->term_id, $language);
        $translated = $translated ? get_term((int) $translated, $term->taxonomy) : null;

        return $translated instanceof \WP_Term ? $translated : null;
    }

    public function findTerm(string $slug, string $taxonomy): ?\WP_Term
    {
        // get_terms() is filtered to the current language: lang '' reads them all
        $terms = get_terms(['taxonomy' => $taxonomy, 'slug' => $slug, 'hide_empty' => false, 'lang' => '', 'number' => 1]);

        return is_array($terms) && ($terms[0] ?? null) instanceof \WP_Term ? $terms[0] : null;
    }

    public function in(string $language, callable $callback): mixed
    {
        $object = PLL()->model->get_language($language);
        if (! $object || $language === $this->current()) {
            return $callback();
        }

        $previous = PLL()->curlang ?? null;
        PLL()->curlang = $object;
        $switched = switch_to_locale($object->locale);

        try {
            return $callback();
        } finally {
            PLL()->curlang = $previous;
            if ($switched) {
                restore_previous_locale();
            }
        }
    }

    public function baseArgs(array $args): array
    {
        $language = $this->current();
        $translated = array_filter((array) ($args['post_type'] ?? []), fn ($type) => pll_is_translated_post_type((string) $type));

        if ($language === '' || $translated === []) {
            return $args;
        }

        $args['tax_query'] = [
            'relation' => 'AND',
            ['taxonomy' => 'language', 'field' => 'slug', 'terms' => [$language]],
            ...(($args['tax_query'] ?? []) !== [] ? [$args['tax_query']] : []),
        ];

        return $args;
    }

    public function boot(): void
    {
        add_filter('pll_translation_url', [Translations::class, 'translationUrl'], 10, 2);
        add_filter('pll_rel_hreflang_attributes', [Translations::class, 'hreflangs']);
        // UrlCodec has the last word on a listing's URLs (P7: Polylang redirects a value of another language, and wrongly)
        add_action('template_redirect', [self::class, 'keepCanonical'], 3);
    }

    public static function keepCanonical(): void
    {
        if (Translations::onListing() && isset(PLL()->canonical)) {
            remove_action('template_redirect', [PLL()->canonical, 'check_canonical_url'], 4);
        }
    }
}
