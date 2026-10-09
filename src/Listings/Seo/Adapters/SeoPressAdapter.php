<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo\Adapters;

/**
 * SEOPress (checked on 10.3). Its filters carry whole tags. It prints its own
 * robots tag (WordPress's would contradict it), its adjacent links only when
 * an option is on: the view's are printed on wp_head instead, with the
 * structured data (no graph to join in the free version).
 */
final class SeoPressAdapter extends Adapter
{
    public static function active(): bool
    {
        return defined('SEOPRESS_VERSION');
    }

    public function name(): string
    {
        return 'SEOPress';
    }

    public function hook(): void
    {
        add_filter('seopress_titles_noindex_bypass', [$this, 'noindex'], 20);
        add_filter('seopress_titles_nofollow', [$this, 'nofollow'], 20);
        add_filter('seopress_titles_canonical', [$this, 'canonical'], 20);
        add_filter('seopress_titles_paged_rel', '__return_empty_string', 20);
        add_filter('seopress_titles_title', fn ($title) => $this->ruleOr($title, 'title'), 20);
        add_filter('seopress_titles_desc', fn ($description) => $this->ruleOr($description, 'description'), 20);
        add_filter('seopress_social_og_url', [$this, 'ogUrl'], 20);
        add_filter('seopress_social_og_title', fn ($tag) => $this->ogTag($tag, 'og:title', $this->view()->title ?? ''), 20);
        add_filter('seopress_social_og_desc', fn ($tag) => $this->ogTag($tag, 'og:description', $this->view()->description ?? ''), 20);
        add_action('wp_head', [$this, 'head'], 2);
    }

    /**
     * 'yes' puts the page in noindex (and takes its canonical away).
     */
    public function noindex(mixed $noindex): mixed
    {
        $directives = $this->view()?->robots();

        return $directives === null ? $noindex : ($directives['index'] ? '' : 'yes');
    }

    public function nofollow(mixed $nofollow): mixed
    {
        return $this->view()?->robots() === null ? $nofollow : '';
    }

    public function canonical(mixed $tag): mixed
    {
        $view = $this->view();

        if ($view === null) {
            return $tag;
        }

        return $view->canonical === null ? '' : sprintf('<link rel="canonical" href="%s" />', esc_url($view->canonical));
    }

    public function ogUrl(mixed $tag): mixed
    {
        $view = $this->view();

        return $view === null ? $tag : sprintf('<meta property="og:url" content="%s">', esc_url($view->url))."\n";
    }

    private function ogTag(mixed $tag, string $property, string $value): mixed
    {
        return $value === '' ? $tag : sprintf('<meta property="%s" content="%s">', esc_attr($property), esc_attr($value))."\n";
    }

    public function head(): void
    {
        echo $this->relLinks(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by relLinks()
        $this->printStructuredData();
    }
}
