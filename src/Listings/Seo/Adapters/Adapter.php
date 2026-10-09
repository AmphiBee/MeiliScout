<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo\Adapters;

use Pollora\MeiliScout\Listings\Seo\SeoPolicy;
use Pollora\MeiliScout\Listings\Seo\SeoView;
use Pollora\MeiliScout\Listings\Seo\StructuredData;

/**
 * Tells one SEO plugin (or WordPress alone) what the listing view is, through
 * its own filters (design §8.2, signatures checked in prototype P2): robots,
 * canonical, adjacent pages, title, description, Open Graph URL, structured data.
 *
 * Hooked once a request has a view (SeoPolicy::resolve()): every callback
 * reads it.
 */
abstract class Adapter
{
    /**
     * Whether its plugin runs.
     */
    abstract public static function active(): bool;

    /**
     * Registers its filters.
     */
    abstract public function hook(): void;

    /**
     * The name the admin's preview shows.
     */
    abstract public function name(): string;

    protected function view(): ?SeoView
    {
        return SeoPolicy::current();
    }

    /**
     * <link rel="prev"> and <link rel="next"> of the view.
     */
    protected function relLinks(): string
    {
        $view = $this->view();
        $html = '';

        foreach (['prev' => $view?->prev, 'next' => $view?->next] as $rel => $url) {
            if ($url !== null) {
                $html .= sprintf('<link rel="%s" href="%s" />'."\n", $rel, esc_url($url));
            }
        }

        return $html;
    }

    /**
     * Prints the structured data alone, for a plugin without a graph to join.
     */
    public function printStructuredData(): void
    {
        $view = $this->view();
        $result = SeoPolicy::currentResult();

        if ($view === null || $result === null) {
            return;
        }

        $graph = StructuredData::graph($view, $result, true);
        if ($graph !== []) {
            printf(
                '<script type="application/ld+json" class="meiliscout-schema">%s</script>'."\n",
                wp_json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
            );
        }
    }

    /**
     * A plugin's graph with the view's pieces (its own breadcrumb kept).
     *
     * @param  array<int|string, mixed>  $graph
     * @return array<int|string, mixed>
     */
    public function joinGraph(array $graph): array
    {
        $view = $this->view();
        $result = SeoPolicy::currentResult();

        if ($view === null || $result === null) {
            return $graph;
        }

        foreach (StructuredData::graph($view, $result, false) as $piece) {
            $graph[] = $piece;
        }

        return $graph;
    }

    /**
     * A plugin's value, or the view's when its rule sets one.
     */
    protected function ruleOr(mixed $value, string $field): mixed
    {
        $view = $this->view();
        $ours = $view === null ? '' : match ($field) {
            'title' => $view->title,
            'description' => $view->description,
            default => '',
        };

        return $ours !== '' ? $ours : $value;
    }
}
