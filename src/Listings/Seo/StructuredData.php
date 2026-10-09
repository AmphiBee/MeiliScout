<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo;

use Pollora\MeiliScout\Listings\Query\ListingResult;

/**
 * The structured data of an indexable view (design §8.4): an ItemList of its
 * results, the questions of its rule (FAQPage) and, when the SEO plugin has
 * no graph of its own, a breadcrumb (the site, the listing's page and its
 * ancestors; facets in the path will add theirs). Joined to Yoast's, Rank
 * Math's and All in One SEO's graphs, printed alone otherwise.
 */
final class StructuredData
{
    /**
     * @param  bool  $breadcrumb  Whether to add a BreadcrumbList (no plugin graph has one)
     * @return list<array<string, mixed>>
     */
    public static function graph(SeoView $view, ListingResult $result, bool $breadcrumb): array
    {
        if (! $view->indexable || $view->canonical === null) {
            return [];
        }

        $graph = [self::itemList($view, $result)];

        if ($view->faq !== []) {
            $graph[] = self::faqPage($view);
        }

        if ($breadcrumb) {
            $graph[] = self::breadcrumb($view, $result);
        }

        /**
         * Filters the structured data of a listing's indexable view: [] prints none.
         *
         * @param  list<array<string, mixed>>  $graph  ItemList, FAQPage, BreadcrumbList (without an SEO plugin's graph)
         * @param  SeoView  $view
         * @param  ListingResult  $result
         */
        /** @var mixed $graph */
        $graph = apply_filters('meiliscout/listings/structured_data', $graph, $view, $result);

        return is_array($graph) ? array_values(array_filter($graph, 'is_array')) : [];
    }

    /**
     * The results of the page, at their position in the whole listing.
     *
     * @return array<string, mixed>
     */
    public static function itemList(SeoView $view, ListingResult $result): array
    {
        $offset = ($view->page - 1) * $result->definition->perPage;
        $items = [];

        foreach (self::urls($result) as $i => $url) {
            $items[] = ['@type' => 'ListItem', 'position' => $offset + $i + 1, 'url' => $url];
        }

        return [
            '@type' => 'ItemList',
            '@id' => $view->canonical.'#itemlist',
            'url' => $view->canonical,
            'numberOfItems' => $view->total,
            'itemListElement' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function faqPage(SeoView $view): array
    {
        return [
            '@type' => 'FAQPage',
            '@id' => $view->canonical.'#faq',
            'mainEntity' => array_map(fn (array $pair) => [
                '@type' => 'Question',
                'name' => $pair['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => wp_strip_all_tags($pair['answer'])],
            ], $view->faq),
        ];
    }

    /**
     * The site, the listing page's ancestors, the listing's page (its first page).
     *
     * @return array<string, mixed>
     */
    public static function breadcrumb(SeoView $view, ListingResult $result): array
    {
        $definition = $result->definition;
        $crumbs = [['name' => (string) get_bloginfo('name', 'display'), 'url' => home_url('/')]];
        $post = $definition->route['page'] ?? $definition->route['post'] ?? null;

        if ($post !== null) {
            foreach (array_reverse(get_post_ancestors($post)) as $ancestor) {
                $crumbs[] = ['name' => html_entity_decode(get_the_title($ancestor), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'url' => (string) get_permalink($ancestor)];
            }
        }

        $crumbs[] = ['name' => SeoPolicy::baseTitle($definition), 'url' => $view->base];

        $items = [];
        foreach ($crumbs as $i => $crumb) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['name'], 'item' => $crumb['url']];
        }

        return ['@type' => 'BreadcrumbList', '@id' => $view->canonical.'#breadcrumb', 'itemListElement' => $items];
    }

    /**
     * @return list<string>
     */
    private static function urls(ListingResult $result): array
    {
        if ($result->hits !== null) {
            return array_values(array_filter(array_map(fn (array $hit) => (string) ($hit['url'] ?? ''), $result->hits)));
        }

        $urls = [];
        foreach ($result->query->posts ?? [] as $post) {
            $url = get_permalink($post);
            if (is_string($url)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
