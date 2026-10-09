<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo\Adapters;

/**
 * WordPress alone: wp_robots, the canonical of a singular page
 * (get_canonical_url), and what it does not print (an archive's canonical,
 * prev and next, the description, structured data) on wp_head.
 */
final class CoreAdapter extends Adapter
{
    public static function active(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'WordPress';
    }

    public function hook(): void
    {
        add_filter('wp_robots', [$this, 'robots'], 20);
        add_filter('get_canonical_url', [$this, 'canonical'], 20);
        add_filter('pre_get_document_title', [$this, 'title'], 20);
        // On a post holding a listing, WordPress links the adjacent posts: not its pages
        remove_action('wp_head', 'adjacent_posts_rel_link_wp_head');
        add_action('wp_head', [$this, 'head'], 2);
    }

    /**
     * @param  array<string, bool|string>  $robots
     * @return array<string, bool|string>
     */
    public function robots(array $robots): array
    {
        $directives = $this->view()?->robots();

        if ($directives === null) {
            return $robots;
        }

        unset($robots['index'], $robots['noindex'], $robots['follow'], $robots['nofollow']);

        return [$directives['index'] ? 'index' : 'noindex' => true, 'follow' => true] + $robots;
    }

    public function canonical(string $canonical): string
    {
        $view = $this->view();

        return $view === null ? $canonical : (string) $view->canonical;
    }

    public function title(string $title): string
    {
        return (string) $this->ruleOr($title, 'title');
    }

    public function head(): void
    {
        $view = $this->view();
        if ($view === null) {
            return;
        }

        // rel_canonical() covers singular pages only
        if (! is_singular() && $view->canonical !== null) {
            printf('<link rel="canonical" href="%s" />'."\n", esc_url($view->canonical));
        }

        echo $this->relLinks(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by relLinks()

        if ($view->description !== '') {
            printf('<meta name="description" content="%s" />'."\n", esc_attr($view->description));
        }

        $this->printStructuredData();
    }
}
