<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Language;

use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\Query\ListingQuery;
use Pollora\MeiliScout\Listings\Query\ListingResult;
use Pollora\MeiliScout\Listings\Seo\SeoPolicy;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Listings\State\UrlCodec;

/**
 * The listing view of the request in other languages (design §9, P7): its
 * page's translation, its terms' translations, the prefixes of that
 * language. For the language switcher and hreflang.
 *
 * - The switcher goes to the translated view, page 1, when every term has a
 *   translation; otherwise the plugin's own link.
 * - hreflang (decision C): only on an indexable view, and only towards a
 *   translation that is indexable too (one count search per language).
 */
final class Translations
{
    /**
     * The views computed, by language.
     *
     * @var array<string, array{url: string, indexable: bool}|null>
     */
    private static array $views = [];

    /**
     * The request shows the listing on its route, with its SEO view.
     */
    public static function onListing(): bool
    {
        return SeoPolicy::currentResult() !== null;
    }

    /**
     * pll_translation_url, wpml_alternate_hreflang: the listing's view in a
     * language, or the plugin's URL when it has none.
     */
    public static function translationUrl(mixed $url, mixed $language): mixed
    {
        if (! self::onListing() || ! is_string($language) || $language === 'x-default') {
            return $url;
        }

        $view = self::view($language);

        return $view['url'] ?? $url;
    }

    /**
     * pll_rel_hreflang_attributes, wpml_hreflangs: none on a view that may not
     * be indexed, and only the indexable translations on one that may.
     *
     * @param  mixed  $hreflangs  [code => url, ...]
     */
    public static function hreflangs(mixed $hreflangs): mixed
    {
        if (! is_array($hreflangs) || ! self::onListing()) {
            return $hreflangs;
        }

        if (! (SeoPolicy::current()->indexable ?? false)) {
            return [];
        }

        $indexable = [];
        foreach (Languages::adapter()->languages() as $language) {
            $view = self::view($language);
            if ($view !== null && $view['indexable']) {
                $indexable[] = $view['url'];
            }
        }

        return array_filter($hreflangs, fn ($url) => in_array($url, $indexable, true));
    }

    /**
     * The request's listing view in a language: its URL, and whether it may be indexed.
     *
     * @return array{url: string, indexable: bool}|null Null when a page or a term has no translation
     */
    public static function view(string $language): ?array
    {
        if (array_key_exists($language, self::$views)) {
            return self::$views[$language];
        }

        $result = SeoPolicy::currentResult();

        return self::$views[$language] = $result === null ? null : self::of($result, $language);
    }

    /**
     * A result's view in a language.
     *
     * @return array{url: string, indexable: bool}|null
     */
    public static function of(ListingResult $result, string $language): ?array
    {
        $adapter = Languages::adapter();
        $definition = $result->definition;

        if ($language === $adapter->current()) {
            $view = SeoPolicy::viewOf($result, Listings::baseUrl($definition));

            return ['url' => $view->url, 'indexable' => $view->indexable];
        }

        $state = self::translatedState($definition, $result->state, $language);
        if ($state === null) {
            return null;
        }

        return $adapter->in($language, function () use ($definition, $state): ?array {
            $base = Listings::baseUrl($definition);
            if ($base === '') {
                return null;
            }

            // Indexable there too: only then is it counted
            $indexable = false;
            if (SeoPolicy::current()?->indexable && SeoPolicy::reason($definition, $state, max(1, $definition->seoMinResults)) === null) {
                $query = new \WP_Query(['posts_per_page' => 1, 'fields' => 'ids', 'paged' => 1] + ListingQuery::wpQueryArgs($definition, $state->onPage(1)));
                $total = (int) $query->found_posts;
                $indexable = SeoPolicy::reason($definition, $state, $total) === null && $state->page <= max(1, (int) ceil($total / max(1, $definition->perPage)));
            }

            // The same page for hreflang; the first one otherwise (a later page may not exist there)
            return ['url' => UrlCodec::url($definition, $indexable ? $state : $state->onPage(1), $base), 'indexable' => $indexable];
        });
    }

    /**
     * A state with its terms in another language, null when one has no translation.
     */
    public static function translatedState(ListingDefinition $definition, ListingState $state, string $language): ?ListingState
    {
        $adapter = Languages::adapter();
        $values = $state->values;

        foreach ($definition->facets as $facet) {
            if (! $facet->isTaxonomy() || $state->valuesOf($facet->key) === []) {
                continue;
            }

            $translated = [];
            foreach ($state->valuesOf($facet->key) as $slug) {
                $term = $adapter->findTerm($slug, $facet->name);
                $other = $term === null ? null : $adapter->translateTerm($term, $language);
                if ($other === null) {
                    return null;
                }
                $translated[] = $other->slug;
            }
            sort($translated, SORT_STRING);
            $values[$facet->key] = array_values(array_unique($translated));
        }

        return new ListingState($values, $state->ranges, $state->sort, $state->page, $state->search);
    }

    /**
     * Forgets the views (tests).
     */
    public static function forget(): void
    {
        self::$views = [];
    }
}
