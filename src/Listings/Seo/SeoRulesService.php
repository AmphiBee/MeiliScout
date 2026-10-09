<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo;

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\Query\ListingQuery;
use Pollora\MeiliScout\Listings\Seo\Adapters\Adapters;
use Pollora\MeiliScout\Listings\State\UrlCodec;

/**
 * What the admin's screen, the CSV and the command do with SEO rules:
 * validate and save one, preview the view of a URL.
 */
final class SeoRulesService
{
    /**
     * Validates and saves a rule from an input (the admin's form, a CSV row).
     *
     * @param  array<string, mixed>  $input  id, listing, locale, key, title, description, h1, intro, faq
     * @return bool Whether it replaced a rule
     *
     * @throws \InvalidArgumentException An unknown listing, a wrong key or locale
     * @throws \RuntimeException When the database refuses it
     */
    public static function save(array $input, bool $dryRun = false): bool
    {
        $definition = self::definition((string) ($input['listing'] ?? ''));
        $key = RuleKey::normalize($definition, (string) ($input['key'] ?? ''));
        $locale = trim((string) ($input['locale'] ?? ''));

        if ($locale !== '' && ! preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]+)*$/', $locale)) {
            /* translators: %s: a locale */
            throw new \InvalidArgumentException(sprintf(__('"%s" is not a locale (fr_FR, en_US...), nor empty for every language.', 'meiliscout'), $locale));
        }

        $id = isset($input['id']) && (int) $input['id'] > 0 ? (int) $input['id'] : null;
        $rule = (new SeoRule($definition->id, $locale, $key, RuleKey::specificity($key), id: $id))->withFields($input);

        if ($rule->title === '' && $rule->description === '' && $rule->h1 === '' && $rule->intro === '' && $rule->faq === []) {
            throw new \InvalidArgumentException(__('A rule needs at least one field: title, description, heading, introduction or questions.', 'meiliscout'));
        }

        SeoRules::install();
        $replaces = $id !== null || self::exists($definition->id, $locale, $key);

        if (! $dryRun) {
            SeoRules::save($rule);
        }

        return $replaces;
    }

    /**
     * Copies a rule to the site's other languages (design §8.3): its key with
     * each term's translation, the language's locale, its text as it is, to
     * translate. A language whose rule exists already, or where a term has no
     * translation, is skipped.
     *
     * @return array{created: list<string>, skipped: array<string, string>} Locales created; skipped, with the reason
     *
     * @throws \InvalidArgumentException An unknown rule, or one for every language
     */
    public static function duplicate(int $id): array
    {
        $rule = SeoRules::get($id) ?? throw new \InvalidArgumentException(__('No such rule.', 'meiliscout'));
        $adapter = Languages::adapter();

        if ($rule->locale === '') {
            throw new \InvalidArgumentException(__('This rule is for every language already.', 'meiliscout'));
        }

        $definition = self::definition($rule->listing);
        $report = ['created' => [], 'skipped' => []];

        foreach ($adapter->languages() as $language) {
            $locale = $adapter->locale($language);
            if ($locale === $rule->locale) {
                continue;
            }

            $parts = [];
            foreach (RuleKey::parse($rule->key) as $facetKey => $value) {
                $facet = $definition->facet($facetKey);
                $term = $value === RuleKey::ANY || $facet === null ? null : get_term((int) $value, $facet->name);
                $translated = $term instanceof \WP_Term ? $adapter->translateTerm($term, $language) : null;

                if ($value !== RuleKey::ANY && $translated === null) {
                    /* translators: %s: a term's id */
                    $report['skipped'][$locale] = sprintf(__('The term %s has no translation.', 'meiliscout'), $value);

                    continue 2;
                }

                $parts[] = $facetKey.'='.($value === RuleKey::ANY ? RuleKey::ANY : $translated->term_id);
            }

            $key = implode('|', $parts);
            if (self::exists($rule->listing, $locale, $key)) {
                $report['skipped'][$locale] = __('A rule exists already.', 'meiliscout');

                continue;
            }

            SeoRules::save(new SeoRule($rule->listing, $locale, $key, RuleKey::specificity($key), $rule->title, $rule->description, $rule->h1, $rule->intro, $rule->faq));
            $report['created'][] = $locale;
        }

        return $report;
    }

    /**
     * What a URL of a listing's route tells search engines.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException A URL no listing is routed at
     */
    public static function preview(string $url): array
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);

        foreach (DefinitionRegistry::ids() as $id) {
            try {
                $definition = DefinitionRegistry::get($id);
            } catch (InvalidListing) {
                continue;
            }

            if ($definition->route === []) {
                continue;
            }

            $base = Listings::baseUrl($definition);
            // Its first page, then its facets in the path and a page
            $state = UrlCodec::fromRequest($definition, $path, $query, $base);
            if ($state === null) {
                continue;
            }

            $result = ListingQuery::run($definition, $state);
            $view = SeoPolicy::forResult($result, $base);

            return $view->toArray() + [
                'state_url' => UrlCodec::url($definition, $state, $base),
                'not_found' => $state->page > max(1, $result->pages()),
                'canonical_url' => UrlCodec::canonicalUrl($definition, $path, $query, $base),
                'seo' => $definition->seo,
                'rule_key' => $view->rule?->key,
                'rule_label' => $view->rule !== null ? RuleKey::label($definition, $view->rule->key) : null,
                'locale' => SeoPolicy::locale(),
                'adapter' => Adapters::current()->name(),
                'structured_data' => StructuredData::graph($view, $result, true),
            ];
        }

        throw new \InvalidArgumentException(__('No listing has this URL as its page (a listing needs a route).', 'meiliscout'));
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function definition(string $id): ListingDefinition
    {
        try {
            return DefinitionRegistry::get($id);
        } catch (InvalidListing|\OutOfBoundsException) {
            /* translators: %s: a listing's id */
            throw new \InvalidArgumentException(sprintf(__('No valid listing "%s".', 'meiliscout'), $id));
        }
    }

    private static function exists(string $listing, string $locale, string $key): bool
    {
        foreach (SeoRules::all($listing) as $rule) {
            if ($rule->locale === $locale && $rule->key === $key) {
                return true;
            }
        }

        return false;
    }
}
