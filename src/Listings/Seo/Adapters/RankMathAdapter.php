<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo\Adapters;

/**
 * Rank Math (checked on 1.0.280). It reads the canonical on `wp`, when the
 * view is known. On a page, its adjacent links only follow <!--nextpage-->,
 * unfiltered: they are switched off and the view's printed on rank_math/head.
 */
final class RankMathAdapter extends Adapter
{
    public static function active(): bool
    {
        return defined('RANK_MATH_VERSION') || class_exists('RankMath', false);
    }

    public function name(): string
    {
        return 'Rank Math';
    }

    public function hook(): void
    {
        add_filter('rank_math/frontend/robots', [$this, 'robots'], 20);
        add_filter('rank_math/frontend/canonical', [$this, 'canonical'], 20);
        add_filter('rank_math/frontend/disable_adjacent_rel_links', '__return_true', 20);
        add_action('rank_math/head', [$this, 'printRelLinks'], 30);
        add_filter('rank_math/frontend/title', fn ($title) => $this->ruleOr($title, 'title'), 20);
        add_filter('rank_math/frontend/description', fn ($description) => $this->ruleOr($description, 'description'), 20);
        add_filter('rank_math/opengraph/facebook/og_url', [$this, 'url'], 20);
        add_filter('rank_math/opengraph/facebook/og_title', fn ($title) => $this->ruleOr($title, 'title'), 20);
        add_filter('rank_math/opengraph/facebook/og_description', fn ($description) => $this->ruleOr($description, 'description'), 20);
        add_filter('rank_math/json_ld', [$this, 'jsonLd'], 100);
        add_filter('rank_math/frontend/breadcrumb/items', [$this, 'breadcrumb'], 20);
    }

    /**
     * @param  mixed  $robots  ['index' => 'noindex', 'follow' => 'follow', ...]
     */
    public function robots(mixed $robots): mixed
    {
        $directives = $this->view()?->robots();

        if ($directives === null || ! is_array($robots)) {
            return $robots;
        }

        $robots['index'] = $directives['index'] ? 'index' : 'noindex';
        $robots['follow'] = 'follow';

        return $robots;
    }

    public function canonical(mixed $canonical): mixed
    {
        $view = $this->view();

        return $view === null ? $canonical : ($view->canonical ?? false);
    }

    /**
     * Its breadcrumb (when it is on): the page links to the listing's first
     * page, the facets of the path follow.
     *
     * @param  mixed  $crumbs  [[name, link, 'hide_in_schema' => bool], ...]
     */
    public function breadcrumb(mixed $crumbs): mixed
    {
        $view = $this->view();

        if (! is_array($crumbs) || $view === null || $view->crumbs === [] || $crumbs === []) {
            return $crumbs;
        }

        $last = array_key_last($crumbs);
        if (empty($crumbs[$last][1])) {
            $crumbs[$last][1] = $view->base;
        }

        foreach ($view->crumbs as $crumb) {
            $crumbs[] = [$crumb['name'], $crumb['url'], 'hide_in_schema' => false];
        }

        return $crumbs;
    }

    public function url(mixed $url): mixed
    {
        return $this->view()->url ?? $url;
    }

    public function printRelLinks(): void
    {
        echo $this->relLinks(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by relLinks()
    }

    /**
     * Rank Math's pieces are keyed; it prints their values as a graph.
     */
    public function jsonLd(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        foreach ($this->joinGraph([]) as $i => $piece) {
            $data['meiliscout_'.$i] = $piece;
        }

        return $data;
    }
}
