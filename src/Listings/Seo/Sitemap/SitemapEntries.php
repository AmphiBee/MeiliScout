<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo\Sitemap;

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\FacetDefinition;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\Query\FacetClauses;
use Pollora\MeiliScout\Listings\Query\FacetPlan;
use Pollora\MeiliScout\Listings\Query\ListingQuery;
use Pollora\MeiliScout\Listings\Seo\RuleKey;
use Pollora\MeiliScout\Listings\Seo\SeoRules;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Listings\State\UrlCodec;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * The views of the listings a sitemap lists (design §8.6): the indexable
 * ones beyond a listing's first page (which is a page of the site, in its
 * own sitemap), as SeoPolicy decides them:
 *
 * - each term of a facet of the path with enough results;
 * - the views of two facets of the path an SEO rule names with a term (type=12|level=7,
 *   type=12|level=*), with enough results.
 *
 * In every language, as many searches as rules of two facets plus one per
 * listing and language; kept for an hour, and forgotten when a rule changes.
 */
final class SitemapEntries
{
    private const CACHE = 'meiliscout_listings_sitemap';

    /**
     * @return list<array{loc: string, listing: string, language: string}>
     */
    public static function all(): array
    {
        // Listings are declared on init: earlier, there is nothing to list, and nothing to keep
        if (! did_action('init')) {
            return [];
        }

        $cached = get_transient(self::CACHE);
        $entries = is_array($cached) ? $cached : self::build();

        if (! is_array($cached)) {
            set_transient(self::CACHE, $entries, HOUR_IN_SECONDS);
        }

        /**
         * Filters the views of the listings the sitemap lists.
         *
         * @param  array<int, array{loc: string, listing: string, language: string}>  $entries
         */
        return array_values((array) apply_filters('meiliscout/listings/sitemap_entries', $entries));
    }

    public static function forget(): void
    {
        delete_transient(self::CACHE);
    }

    /**
     * @return list<array{loc: string, listing: string, language: string}>
     */
    public static function build(): array
    {
        if (! get_option('blog_public')) {
            return [];
        }

        $adapter = Languages::adapter();
        $entries = [];

        foreach (DefinitionRegistry::ids() as $id) {
            try {
                $definition = DefinitionRegistry::get($id);
            } catch (InvalidListing) {
                continue;
            }

            if ($definition->route === [] || ! $definition->seo || $definition->seoMaxDepth < 1 || $definition->pathFacets() === []) {
                continue;
            }

            foreach ($adapter->languages() ?: [''] as $language) {
                array_push($entries, ...$adapter->in($language, fn (): array => self::listing($definition, $language)));
            }
        }

        return $entries;
    }

    /**
     * A listing's views in the current language.
     *
     * @return list<array{loc: string, listing: string, language: string}>
     */
    private static function listing(ListingDefinition $definition, string $language): array
    {
        $base = Listings::baseUrl($definition);
        $client = ClientFactory::getReadClient();

        if ($base === '' || $client === null) {
            return [];
        }

        try {
            $views = [...self::single($definition), ...self::ruled($definition, $language)];
        } catch (\Throwable $e) {
            error_log('MeiliScout: could not list the views of the listing '.$definition->id.' for the sitemap: '.$e->getMessage());

            return [];
        }

        $entries = [];
        foreach ($views as $values) {
            $loc = UrlCodec::url($definition, new ListingState($values), $base);
            $entries[$loc] = ['loc' => $loc, 'listing' => $definition->id, 'language' => $language];
        }

        return array_values($entries);
    }

    /**
     * Each term of a facet of the path with enough results.
     *
     * @return list<array<string, list<string>>>
     */
    private static function single(ListingDefinition $definition): array
    {
        $facets = $definition->pathFacets();
        $result = self::search($definition, [], array_map(fn (FacetDefinition $facet) => $facet->countField(), $facets));
        $views = [];

        foreach ($facets as $facet) {
            foreach (self::termsWithEnough($definition, $facet, $result) as $slug) {
                $views[] = [$facet->key => [$slug]];
            }
        }

        return $views;
    }

    /**
     * The views of two facets of the path the rules of this language name
     * with a term of one of them at least.
     *
     * @return list<array<string, list<string>>>
     */
    private static function ruled(ListingDefinition $definition, string $language): array
    {
        if ($definition->seoMaxDepth < 2) {
            return [];
        }

        $locale = $language === '' ? get_locale() : Languages::adapter()->locale($language);
        $views = [];

        foreach (SeoRules::all($definition->id) as $rule) {
            if ($rule->locale !== '' && $rule->locale !== $locale) {
                continue;
            }

            try {
                $parts = RuleKey::parse($rule->key);
            } catch (\InvalidArgumentException) {
                continue;
            }

            if (count($parts) !== 2) {
                continue;
            }

            $fixed = [];
            $wildcard = null;
            foreach ($parts as $key => $value) {
                $facet = $definition->facet($key);
                if ($facet === null || ! $facet->inPath()) {
                    continue 2;
                }
                if ($value === RuleKey::ANY) {
                    $wildcard = $facet;

                    continue;
                }
                $term = get_term((int) $value, $facet->name);
                if (! $term instanceof \WP_Term) {
                    continue 2;
                }
                $fixed[$key] = [$term->slug];
            }

            if ($fixed === []) {
                continue;
            }

            if ($wildcard === null) {
                $result = self::search($definition, $fixed, []);
                if ((int) ($result['estimatedTotalHits'] ?? $result['totalHits'] ?? 0) >= $definition->seoMinResults) {
                    $views[] = $fixed;
                }

                continue;
            }

            $result = self::search($definition, $fixed, [$wildcard->countField()]);
            foreach (self::termsWithEnough($definition, $wildcard, $result) as $slug) {
                $views[] = $fixed + [$wildcard->key => [$slug]];
            }
        }

        return $views;
    }

    /**
     * The slugs of a facet's terms counting enough results in a search's distribution.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private static function termsWithEnough(ListingDefinition $definition, FacetDefinition $facet, array $result): array
    {
        $slugs = [];

        foreach ((array) ($result['facetDistribution'][$facet->countField()] ?? []) as $id => $count) {
            if ((int) $count < $definition->seoMinResults) {
                continue;
            }
            $term = get_term((int) $id, $facet->name);
            if ($term instanceof \WP_Term) {
                $slugs[] = $term->slug;
            }
        }

        return $slugs;
    }

    /**
     * The listing's posts with these values, counted on these fields.
     *
     * @param  array<string, list<string>>  $values
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private static function search(ListingDefinition $definition, array $values, array $fields): array
    {
        $filters = ['('.FacetPlan::baseFilter($definition).')'];
        foreach ($values as $key => $slugs) {
            $facet = $definition->facet($key);
            if ($facet !== null) {
                $filters[] = FacetClauses::value($facet, $slugs[0]);
            }
        }

        $client = ClientFactory::getReadClient();
        $search = ['indexUid' => IndexNames::active('posts'), 'filter' => implode(' AND ', $filters), 'facets' => $fields, 'limit' => 0];
        $results = $client?->multiSearch([ListingQuery::searchQuery($search)])['results'] ?? [];

        return (array) ($results[0] ?? []);
    }
}
