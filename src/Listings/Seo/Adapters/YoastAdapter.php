<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo\Adapters;

/**
 * Yoast SEO (checked on 28.6). Its rel filters only run when it made a link
 * itself (on a page it counts `page`, not `paged`): the adjacent pages go on
 * its presentation instead.
 */
final class YoastAdapter extends Adapter
{
    public static function active(): bool
    {
        return defined('WPSEO_VERSION');
    }

    public function name(): string
    {
        return 'Yoast SEO';
    }

    public function hook(): void
    {
        add_filter('wpseo_robots', [$this, 'robots'], 20);
        add_filter('wpseo_canonical', [$this, 'canonical'], 20);
        add_filter('wpseo_frontend_presentation', [$this, 'presentation'], 20);
        add_filter('wpseo_opengraph_url', [$this, 'url'], 20);
        add_filter('wpseo_title', fn ($title) => $this->ruleOr($title, 'title'), 20);
        add_filter('wpseo_opengraph_title', fn ($title) => $this->ruleOr($title, 'title'), 20);
        add_filter('wpseo_metadesc', fn ($description) => $this->ruleOr($description, 'description'), 20);
        add_filter('wpseo_opengraph_desc', fn ($description) => $this->ruleOr($description, 'description'), 20);
        add_filter('wpseo_schema_graph', [$this, 'joinGraph'], 20);
    }

    /**
     * Yoast's robots, "index, follow, max-snippet:-1...".
     */
    public function robots(mixed $robots): mixed
    {
        $directives = $this->view()?->robots();

        if ($directives === null || ! is_string($robots)) {
            return $robots;
        }

        $robots = (string) preg_replace(['/\bno(index|follow)\b/', '/\b(index|follow)\b/'], ['$1', ''], $robots);
        $rest = array_filter(array_map('trim', explode(',', $robots)));

        return implode(', ', array_merge([$directives['index'] ? 'index' : 'noindex', 'follow'], $rest));
    }

    public function canonical(mixed $canonical): mixed
    {
        $view = $this->view();

        return $view === null ? $canonical : ($view->canonical ?? false);
    }

    public function presentation(object $presentation): object
    {
        $view = $this->view();

        if ($view !== null) {
            $presentation->rel_prev = (string) $view->prev;
            $presentation->rel_next = (string) $view->next;
        }

        return $presentation;
    }

    public function url(mixed $url): mixed
    {
        return $this->view()->url ?? $url;
    }
}
