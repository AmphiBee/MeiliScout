<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo;

use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\Query\ListingResult;
use Pollora\MeiliScout\Listings\Seo\Adapters\Adapters;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Listings\State\UrlCodec;

/**
 * The SEO of a listing's views (design §8): which are indexable, their
 * canonical URL, robots and adjacent pages, the SEO rule that names them.
 *
 * - Indexable: the listing without filters, search or sort, with results, at
 *   any page. Facets in the path (phase 5) will make more views indexable;
 *   parameters never do (decision 6, decision G for the search).
 * - Indexable: canonical to itself, index, follow (even where the SEO plugin
 *   puts paginated pages in noindex), prev and next. Otherwise noindex, follow,
 *   no canonical, no prev or next.
 * - Past the last page: a 404.
 *
 * The view is known on the `wp` action, before any SEO plugin reads it (Rank
 * Math computes its canonical there), for the listing on its route.
 */
final class SeoPolicy
{
    /**
     * The view of this request, and the result it was computed from.
     *
     * @var array{0: SeoView, 1: ListingResult}|null
     */
    private static ?array $current = null;

    public static function boot(): void
    {
        add_action('wp', [self::class, 'resolve'], 0);
    }

    public static function current(): ?SeoView
    {
        return self::$current[0] ?? null;
    }

    public static function currentResult(): ?ListingResult
    {
        return self::$current[1] ?? null;
    }

    /**
     * The view of the listing on this request's route, if any. Hooked on `wp`.
     */
    public static function resolve(): void
    {
        self::$current = null;

        if (is_admin() || is_feed() || is_404()) {
            return;
        }

        foreach (Listings::routed() as $definition) {
            if (! $definition->seo) {
                continue;
            }

            $run = Listings::result($definition->id);
            if ($run === null) {
                return;
            }

            [$result, $base] = $run;

            if ($result->state->page > max(1, $result->pages())) {
                Listings::notFound();

                return;
            }

            self::$current = [self::forResult($result, $base), $result];
            Adapters::hook();

            return;
        }
    }

    /**
     * Views computed for results other than the request's (a fragment, a part).
     *
     * @var \WeakMap<ListingResult, SeoView>|null
     */
    private static ?\WeakMap $views = null;

    /**
     * The view of a result: the request's, or computed once.
     */
    public static function viewOf(ListingResult $result, string $base): SeoView
    {
        if (self::$current !== null && self::$current[1] === $result) {
            return self::$current[0];
        }

        self::$views ??= new \WeakMap;

        return self::$views[$result] ??= self::forResult($result, $base);
    }

    /**
     * The view of a listing's result, with its rule, in this request.
     */
    public static function forResult(ListingResult $result, string $base): SeoView
    {
        $definition = $result->definition;
        $selection = RuleKey::selection($definition, $result->state);
        $rule = $selection === null ? null : SeoRules::find(
            $definition->id,
            self::locale(),
            RuleKey::candidates(array_map(fn (\WP_Term $term) => (int) $term->term_id, $selection))
        );

        $vars = [
            'site' => (string) get_bloginfo('name', 'display'),
            'title' => self::baseTitle($definition),
            'sep' => (string) apply_filters('document_title_separator', '-'),
        ];
        foreach ($selection ?? [] as $facet => $term) {
            $vars[$facet] = $term->name;
        }

        $view = self::evaluate($result, $base, $rule, $vars, (string) get_option('blog_public') !== '0', self::crumbs($result, $base));

        /**
         * Filters the SEO view of a listing (indexable, canonical, robots,
         * adjacent pages, the rule's fields).
         *
         * @param  SeoView  $view
         * @param  ListingResult  $result  The listing in the request's state
         */
        /** @var mixed $filtered */
        $filtered = apply_filters('meiliscout/listings/seo_view', $view, $result);

        return $filtered instanceof SeoView ? $filtered : $view;
    }

    /**
     * The view of a result: pure, the request's context given.
     *
     * @param  array<string, string>  $vars  The rule's variables: site, title (the listing's page), sep, and each selected facet's term
     */
    /**
     * @param  list<array{name: string, url: string}>  $crumbs
     */
    public static function evaluate(ListingResult $result, string $base, ?SeoRule $rule = null, array $vars = [], bool $blogPublic = true, array $crumbs = []): SeoView
    {
        $definition = $result->definition;
        $state = $result->state;
        $total = $result->total();
        $pages = max(1, $result->pages());
        $page = $state->page;
        $reason = self::reason($definition, $state, $total);
        $indexable = $reason === null;

        $url = fn (int $n): string => UrlCodec::url($definition, $state->onPage($n), $base);

        $vars += ['page' => (string) $page, 'pages' => (string) $pages, 'total' => (string) $total];
        $render = fn (string $template): string => self::render($template, $vars);
        $title = $rule !== null && $rule->title !== '' ? $render($rule->title) : '';

        // A rule's title names every page: the others say which they are
        if ($title !== '' && $page > 1 && ! str_contains($rule->title, '{page}')) {
            /* translators: %s: page number */
            $title .= ' '.($vars['sep'] ?? '-').' '.sprintf(__('Page %s'), $page);
        }

        return new SeoView(
            listing: $definition->id,
            base: strtok($base, '?') ?: $base,
            indexable: $indexable,
            reason: $reason,
            url: $url($page),
            canonical: $indexable ? $url($page) : null,
            prev: $indexable && $page > 1 ? $url($page - 1) : null,
            next: $indexable && $page < $pages ? $url($page + 1) : null,
            page: $page,
            pages: $pages,
            total: $total,
            blogPublic: $blogPublic,
            title: $title,
            description: $rule !== null ? $render($rule->description) : '',
            h1: $rule !== null ? $render($rule->h1) : '',
            // The text and the questions on the first page only: the others would repeat them
            intro: $rule !== null && $page === 1 ? $render($rule->intro) : '',
            faq: $rule !== null && $page === 1 ? array_map(fn (array $pair) => ['question' => $render($pair['question']), 'answer' => $render($pair['answer'])], $rule->faq) : [],
            rule: $rule,
            crumbs: $crumbs,
        );
    }

    /**
     * The crumbs of the facets in the path: each one's terms, at the view
     * made of it and the facets before it.
     *
     * @return list<array{name: string, url: string}>
     */
    public static function crumbs(ListingResult $result, string $base): array
    {
        $definition = $result->definition;
        $values = [];
        $crumbs = [];

        foreach ($definition->pathFacets() as $facet) {
            $slugs = $result->state->valuesOf($facet->key);
            if ($slugs === []) {
                continue;
            }

            $values[$facet->key] = $slugs;
            $names = array_map(function (string $slug) use ($facet): string {
                $term = get_term_by('slug', $slug, $facet->name);

                return $term instanceof \WP_Term ? html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $slug;
            }, $slugs);

            $crumbs[] = ['name' => implode(', ', $names), 'url' => UrlCodec::url($definition, new ListingState($values), $base)];
        }

        return $crumbs;
    }

    /**
     * Why a view is not indexable, null when it is (decision 6): only facets
     * of the path, one value each, at most the listing's depth of them, with
     * enough results; nothing in the query string.
     *
     * @return 'search'|'filters'|'sort'|'values'|'depth'|'empty'|'few'|null
     */
    public static function reason(ListingDefinition $definition, ListingState $state, int $total): ?string
    {
        $inPath = [];
        $elsewhere = array_filter($state->ranges) !== [];

        foreach ($definition->facets as $facet) {
            $values = $state->valuesOf($facet->key);
            if ($values === []) {
                continue;
            }
            if ($facet->inPath()) {
                $inPath[$facet->key] = count($values);
            } else {
                $elsewhere = true;
            }
        }

        return match (true) {
            $state->search !== '' => 'search',
            $elsewhere => 'filters',
            $state->sort !== '' && $state->sort !== $definition->defaultSort => 'sort',
            max([0, ...$inPath]) > 1 => 'values',
            count($inPath) > $definition->seoMaxDepth => 'depth',
            $total === 0 => 'empty',
            $inPath !== [] && $total < $definition->seoMinResults => 'few',
            default => null,
        };
    }

    /**
     * The state a facet's value leads to, when that view is indexable-shaped
     * (decision 6, design §8.5): its value is then a link, the others are not.
     * The value toggled; null when the view it leads to may not be indexed.
     *
     * @param  int  $count  The value's count: the results of the view it adds
     */
    public static function linkTarget(ListingDefinition $definition, ListingState $state, string $facetKey, string $value, int $count): ?ListingState
    {
        $facet = $definition->facet($facetKey);

        if ($facet === null || ! $facet->inPath() || ! $definition->seo) {
            return null;
        }

        $values = $state->values;
        $selected = in_array($value, $state->valuesOf($facetKey), true);
        $values[$facetKey] = $selected ? array_values(array_diff($values[$facetKey], [$value])) : [...($values[$facetKey] ?? []), $value];
        sort($values[$facetKey], SORT_STRING);
        $values = array_filter($values);

        $target = new ListingState($values, $state->ranges, $state->sort, 1, $state->search);
        $reason = self::reason($definition, $target, $selected ? max(1, $count) : $count);

        // Removing a value gives fewer filters: its results are not known, but more
        return $reason === null || ($selected && $reason === 'few') ? $target : null;
    }

    /**
     * A rule's text with its variables: {site}, {title}, {page}, {pages},
     * {total} and each selected facet's term ({type}). Unknown ones stay.
     *
     * @param  array<string, string>  $vars
     */
    public static function render(string $template, array $vars): string
    {
        if ($template === '') {
            return '';
        }

        $text = (string) preg_replace_callback(
            '/\{([a-z0-9_]+)\}/',
            fn (array $m) => array_key_exists($m[1], $vars) && $m[1] !== 'sep' ? $vars[$m[1]] : $m[0],
            $template
        );

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', $text));
    }

    /**
     * The language rules are looked up in: the site's, until listings know
     * languages (phase 6).
     */
    public static function locale(): string
    {
        return (string) apply_filters('meiliscout/listings/seo_locale', get_locale());
    }

    /**
     * The title of a listing's page, for {title}.
     */
    public static function baseTitle(ListingDefinition $definition): string
    {
        $post = $definition->route['page'] ?? $definition->route['post'] ?? null;
        $title = $post !== null
            ? get_the_title($post)
            : (string) (get_post_type_object((string) ($definition->route['archive'] ?? ''))?->labels->name ?? '');

        return html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Forgets the view (tests, the fragment endpoint).
     */
    public static function forget(): void
    {
        self::$current = null;
        self::$views = null;
    }
}
