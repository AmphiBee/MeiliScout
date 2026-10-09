<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Language;

use Pollora\MeiliScout\Integrations\Wpml as WpmlIndex;

/**
 * WPML (checked on 4.9, prototype P7). The posts' language is a field of
 * their documents, filtered by the core's WPML integration
 * (Integrations\Wpml): the base filter needs nothing more here. Terms are
 * read without WPML's adjustment to the current language.
 */
final class Wpml implements LanguageAdapter
{
    public static function active(): bool
    {
        return defined('ICL_SITEPRESS_VERSION');
    }

    public function current(): string
    {
        $language = (string) apply_filters('wpml_current_language', '');

        return $language === 'all' ? '' : $language;
    }

    public function default(): string
    {
        return (string) apply_filters('wpml_default_language', '');
    }

    public function languages(): array
    {
        return array_map('strval', array_keys((array) apply_filters('wpml_active_languages', [], ['skip_missing' => 0])));
    }

    public function locale(string $language): string
    {
        $languages = (array) apply_filters('wpml_active_languages', [], ['skip_missing' => 0]);

        return (string) ($languages[$language]['default_locale'] ?? get_locale());
    }

    public function post(int $postId, string $language): ?int
    {
        $translated = apply_filters('wpml_object_id', $postId, (string) get_post_type($postId), false, $language);

        return $translated ? (int) $translated : null;
    }

    public function postLanguage(int $postId): string
    {
        $details = apply_filters('wpml_post_language_details', null, $postId);

        return is_array($details) ? (string) ($details['language_code'] ?? '') : '';
    }

    public function termLanguage(\WP_Term $term): string
    {
        $details = apply_filters('wpml_element_language_details', null, ['element_id' => $term->term_taxonomy_id, 'element_type' => $term->taxonomy]);

        return (string) ($details->language_code ?? '');
    }

    public function translateTerm(\WP_Term $term, string $language): ?\WP_Term
    {
        $translated = apply_filters('wpml_object_id', $term->term_id, $term->taxonomy, false, $language);
        $translated = $translated ? WpmlIndex::withoutTermAdjustment(fn () => get_term((int) $translated, $term->taxonomy)) : null;

        return $translated instanceof \WP_Term ? $translated : null;
    }

    public function findTerm(string $slug, string $taxonomy): ?\WP_Term
    {
        $terms = WpmlIndex::withoutTermAdjustment(fn () => get_terms(['taxonomy' => $taxonomy, 'slug' => $slug, 'hide_empty' => false, 'number' => 1]));

        return is_array($terms) && ($terms[0] ?? null) instanceof \WP_Term ? $terms[0] : null;
    }

    public function in(string $language, callable $callback): mixed
    {
        $previous = $this->current();
        if ($language === '' || $language === $previous) {
            return $callback();
        }

        do_action('wpml_switch_language', $language);

        try {
            return $callback();
        } finally {
            do_action('wpml_switch_language', $previous);
        }
    }

    public function baseArgs(array $args): array
    {
        return $args;
    }

    public function boot(): void
    {
        add_filter('wpml_alternate_hreflang', [Translations::class, 'translationUrl'], 10, 2);
        add_filter('wpml_hreflangs', [Translations::class, 'hreflangs']);
        add_filter('icl_ls_languages', [self::class, 'switcher']);
    }

    /**
     * The language switcher's links: the listing's view in each language.
     *
     * @param  mixed  $languages  [code => ['url' => ...], ...]
     */
    public static function switcher(mixed $languages): mixed
    {
        if (! is_array($languages) || ! Translations::onListing()) {
            return $languages;
        }

        foreach ($languages as $code => $language) {
            $url = Translations::translationUrl((string) ($language['url'] ?? ''), (string) $code);
            if ($url !== '') {
                $languages[$code]['url'] = $url;
            }
        }

        return $languages;
    }
}
