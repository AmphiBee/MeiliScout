<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo;

/**
 * What search engines are told about the listing view of a request: whether
 * it is indexable, its canonical URL, its adjacent pages, and what its SEO
 * rule says (title, description, heading, introduction, questions).
 *
 * Computed once per request by SeoPolicy, read by the adapter of the SEO
 * plugin in use.
 */
final class SeoView
{
    /**
     * @param  string  $base  The listing's first page
     * @param  'search'|'filters'|'sort'|'empty'|null  $reason  Why it is not indexable
     * @param  string|null  $canonical  Its own URL when indexable, none otherwise
     * @param  list<array{question: string, answer: string}>  $faq
     * @param  list<array{name: string, url: string}>  $crumbs  The facets of the path, one crumb each, after the listing's page
     */
    public function __construct(
        public readonly string $listing,
        public readonly string $base,
        public readonly bool $indexable,
        public readonly ?string $reason,
        public readonly string $url,
        public readonly ?string $canonical,
        public readonly ?string $prev,
        public readonly ?string $next,
        public readonly int $page,
        public readonly int $pages,
        public readonly int $total,
        public readonly bool $blogPublic = true,
        public readonly string $title = '',
        public readonly string $description = '',
        public readonly string $h1 = '',
        public readonly string $intro = '',
        public readonly array $faq = [],
        public readonly ?SeoRule $rule = null,
        public readonly array $crumbs = [],
    ) {}

    /**
     * An indexable view is index, follow at every page, whatever the SEO
     * plugin does with paginated pages; not on a site closed to search engines.
     */
    public function forcesIndex(): bool
    {
        return $this->indexable && $this->blogPublic;
    }

    /**
     * The robots directives the view sets, null when it leaves the plugin's.
     *
     * @return array{index: bool, follow: true}|null
     */
    public function robots(): ?array
    {
        // A site closed to search engines keeps WordPress's noindex, nofollow
        if (! $this->blogPublic) {
            return null;
        }

        if ($this->forcesIndex()) {
            return ['index' => true, 'follow' => true];
        }

        return $this->indexable ? null : ['index' => false, 'follow' => true];
    }

    /**
     * The view as the admin's preview and the command show it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $robots = $this->robots();

        return [
            'listing' => $this->listing,
            'indexable' => $this->indexable,
            'reason' => $this->reason,
            'robots' => $robots === null ? null : ($robots['index'] ? 'index' : 'noindex').', follow',
            'url' => $this->url,
            'canonical' => $this->canonical,
            'prev' => $this->prev,
            'next' => $this->next,
            'page' => $this->page,
            'pages' => $this->pages,
            'total' => $this->total,
            'title' => $this->title,
            'description' => $this->description,
            'h1' => $this->h1,
            'intro' => $this->intro,
            'faq' => $this->faq,
            'rule' => $this->rule?->id,
            'crumbs' => $this->crumbs,
        ];
    }
}
