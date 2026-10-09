<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo\Adapters;

/**
 * All in One SEO (checked on 5.0.3). By default it puts every paginated page
 * in noindex, nofollow: an indexable view takes that back. It turns
 * WordPress's robots and canonical off.
 */
final class AioseoAdapter extends Adapter
{
    public static function active(): bool
    {
        return defined('AIOSEO_VERSION') || function_exists('aioseo');
    }

    public function name(): string
    {
        return 'All in One SEO';
    }

    public function hook(): void
    {
        add_filter('aioseo_robots_meta', [$this, 'robots'], 20);
        add_filter('aioseo_canonical_url', [$this, 'canonical'], 20);
        add_filter('aioseo_prev_link', fn ($url) => $this->view() === null ? $url : (string) $this->view()->prev, 20);
        add_filter('aioseo_next_link', fn ($url) => $this->view() === null ? $url : (string) $this->view()->next, 20);
        add_filter('aioseo_title', fn ($title) => $this->ruleOr($title, 'title'), 20);
        add_filter('aioseo_description', fn ($description) => $this->ruleOr($description, 'description'), 20);
        add_filter('aioseo_facebook_tags', [$this, 'facebook'], 20);
        add_filter('aioseo_schema_output', [$this, 'joinGraph'], 20);
        // Its breadcrumb, HTML and BreadcrumbList
        add_filter('aioseo_breadcrumbs_trail', [$this, 'breadcrumb'], 20);
    }

    /**
     * @param  mixed  $attributes  ['noindex' => 'noindex', 'nofollow' => '', ...]
     */
    public function robots(mixed $attributes): mixed
    {
        $directives = $this->view()?->robots();

        if ($directives === null || ! is_array($attributes)) {
            return $attributes;
        }

        $attributes['noindex'] = $directives['index'] ? '' : 'noindex';
        $attributes['nofollow'] = '';

        return $attributes;
    }

    /**
     * @param  mixed  $trail  [['label' => ..., 'link' => ...], ...]
     */
    public function breadcrumb(mixed $trail): mixed
    {
        if (! is_array($trail)) {
            return $trail;
        }

        foreach ($this->view()->crumbs ?? [] as $crumb) {
            $trail[] = ['label' => $crumb['name'], 'link' => $crumb['url'], 'type' => 'meiliscout_facet', 'subType' => '', 'reference' => null];
        }

        return $trail;
    }

    public function canonical(mixed $canonical): mixed
    {
        $view = $this->view();

        return $view === null ? $canonical : (string) $view->canonical;
    }

    /**
     * @param  mixed  $tags  ['og:url' => ..., 'og:title' => ...]
     */
    public function facebook(mixed $tags): mixed
    {
        $view = $this->view();

        if ($view === null || ! is_array($tags)) {
            return $tags;
        }

        $tags['og:url'] = $view->url;
        if ($view->title !== '') {
            $tags['og:title'] = $view->title;
        }
        if ($view->description !== '') {
            $tags['og:description'] = $view->description;
        }

        return $tags;
    }
}
