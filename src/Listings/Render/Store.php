<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Render;

use Pollora\MeiliScout\Listings\Query\ListingResult;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\Seo\SeoPolicy;
use Pollora\MeiliScout\Listings\State\UrlCodec;
use Pollora\MeiliScout\Listings\Transport\TenantTokens;

/**
 * The Interactivity API state of the listings on a page, in the
 * meiliscout/listing store: state.listings[id]. Only text: labels decoded,
 * no HTML (data-wp-text would print entities as they are).
 */
final class Store
{
    public const NAMESPACE = 'meiliscout/listing';

    /**
     * Adds a listing to the store, and the derived values its directives read.
     */
    public static function add(ListingResult $result, string $base): void
    {
        $definition = $result->definition;
        $state = $result->state;
        $token = $result->template === null ? null : TenantTokens::forListing($definition);

        $facets = [];
        foreach ($definition->facets as $facet) {
            $range = $state->rangeOf($facet->key);
            $options = $result->facets[$facet->key]['options'] ?? [];

            // Design §8.5: a value leading to an indexable view is a link to it
            foreach ($options as $i => $option) {
                $target = SeoPolicy::linkTarget($definition, $state, $facet->key, $option['value'], $option['count']);
                $options[$i]['url'] = $target === null ? null : UrlCodec::url($definition, $target, $base);
            }

            $facets[$facet->key] = [
                'options' => $options,
                'stats' => $result->facets[$facet->key]['stats'] ?? null,
                // Values past the facet's limit, unfolded
                'expanded' => false,
                'min' => isset($range['min']) ? UrlCodec::number($facet, $range['min']) : '',
                'max' => isset($range['max']) ? UrlCodec::number($facet, $range['max']) : '',
            ];
        }

        wp_interactivity_state(self::NAMESPACE, [
            'listings' => [
                $definition->id => [
                    'template' => $result->template,
                    'values' => (object) $state->values,
                    'ranges' => (object) $state->ranges,
                    'sort' => $state->sort !== '' ? $state->sort : $definition->defaultSort,
                    'search' => $state->search,
                    'page' => $state->page,
                    'total' => $result->total(),
                    'pages' => $result->pages(),
                    'facets' => $facets,
                    'base' => $base,
                    'url' => UrlCodec::url($definition, $state, $base),
                    // The state the SEO rule's intro and questions were rendered for, and the one shown (client transport)
                    'renderedUrl' => UrlCodec::url($definition, $state, $base),
                    'shownUrl' => UrlCodec::url($definition, $state, $base),
                    'transport' => $result->template === null ? 'page' : $definition->transport,
                    'host' => $token === null ? null : TenantTokens::publicHost(),
                    'token' => $token,
                    // The client transport's cards (Hits), null in the others
                    'hits' => $result->hits,
                    'pageLinks' => self::pageLinks(Renderer::pageItems($state->page, $result->pages()), fn (int $page) => UrlCodec::url($definition, $state->onPage($page), $base)),
                    // In the page's language: its terms, its token's filter
                    'endpoints' => array_map(
                        fn (string $url) => Languages::current() === '' ? $url : add_query_arg('lang', Languages::current(), $url),
                        [
                            'fragment' => rest_url('meiliscout/v1/listings/'.$definition->id.'/fragment'),
                            'token' => rest_url('meiliscout/v1/listings/'.$definition->id.'/token'),
                        ]
                    ),
                    // Cards that depend on the visitor (decision E): the fragment is asked for with the visitor's session
                    'personalised' => $definition->personalised,
                    'nonce' => $definition->personalised && is_user_logged_in() ? wp_create_nonce('wp_rest') : null,
                    // Button mode: the total of the choices not applied yet
                    'pending' => null,
                    'busy' => false,
                ],
            ],
            'i18n' => [
                'total' => [
                    // Plural forms of this language for 0, 1 and more
                    'zero' => _n('%d result', '%d results', 0, 'meiliscout'),
                    'one' => _n('%d result', '%d results', 1, 'meiliscout'),
                    'many' => _n('%d result', '%d results', 2, 'meiliscout'),
                ],
                'remove' => __('Remove filter: %s', 'meiliscout'),
                /* translators: 1: a facet's label, 2: its value */
                'facetValue' => __('%1$s: %2$s', 'meiliscout'),
                'apply' => __('Apply', 'meiliscout'),
                'see' => [
                    'zero' => _n('See %d result', 'See %d results', 0, 'meiliscout'),
                    'one' => _n('See %d result', 'See %d results', 1, 'meiliscout'),
                    'many' => _n('See %d result', 'See %d results', 2, 'meiliscout'),
                ],
                'results' => __('Results', 'meiliscout'),
                /* translators: 1: a page number, 2: the number of pages */
                'resultsPage' => __('Results, page %1$d of %2$d', 'meiliscout'),
                'more' => __('Show more', 'meiliscout'),
                'less' => __('Show less', 'meiliscout'),
                'previous' => __('Previous', 'meiliscout'),
                'next' => __('Next', 'meiliscout'),
                'date' => Hits::dateNames(),
            ],
        ]);

        self::derived();
    }

    /**
     * The values the directives read, computed the same way here (first
     * render) and in the client (resources/listings/view.js).
     */
    private static function derived(): void
    {
        static $added = false;

        if ($added) {
            return;
        }
        $added = true;

        $listing = function (): array {
            $context = wp_interactivity_get_context();
            $state = wp_interactivity_state(self::NAMESPACE);

            return $state['listings'][$context['listing'] ?? ''] ?? [];
        };

        wp_interactivity_state(self::NAMESPACE, [
            'options' => function () use ($listing): array {
                $context = wp_interactivity_get_context();

                return $listing()['facets'][$context['facet'] ?? '']['options'] ?? [];
            },
            'optionHidden' => function (): bool {
                $option = wp_interactivity_get_context()['option'] ?? [];

                return (int) ($option['count'] ?? 0) === 0 && empty($option['selected']);
            },
            // The client folds the values past a limit; the server shows them all (no JavaScript)
            'hasOverflow' => fn (): bool => false,
            'expanded' => fn (): bool => false,
            'moreLabel' => fn (): string => wp_interactivity_state(self::NAMESPACE)['i18n']['more'],
            'applyLabel' => fn (): string => wp_interactivity_state(self::NAMESPACE)['i18n']['apply'],
            'rangeMin' => function () use ($listing): string {
                return (string) ($listing()['facets'][wp_interactivity_get_context()['facet'] ?? '']['min'] ?? '');
            },
            'rangeMax' => function () use ($listing): string {
                return (string) ($listing()['facets'][wp_interactivity_get_context()['facet'] ?? '']['max'] ?? '');
            },
            'rangeMinLimit' => function () use ($listing): string {
                $stats = $listing()['facets'][wp_interactivity_get_context()['facet'] ?? '']['stats'] ?? null;

                return $stats === null ? '' : (string) $stats['min'];
            },
            'rangeMaxLimit' => function () use ($listing): string {
                $stats = $listing()['facets'][wp_interactivity_get_context()['facet'] ?? '']['stats'] ?? null;

                return $stats === null ? '' : (string) $stats['max'];
            },
            'activeFilters' => function () use ($listing): array {
                $current = $listing();
                $remove = wp_interactivity_state(self::NAMESPACE)['i18n']['remove'];
                $filters = [];

                foreach ((array) ($current['template']['facets'] ?? []) as $facet) {
                    $key = $facet['key'];

                    foreach ((array) (((array) ($current['values'] ?? []))[$key] ?? []) as $value) {
                        $label = ((array) $facet['values'])[$value]['label'] ?? $value;
                        $filters[] = ['id' => $key.':'.$value, 'facet' => $key, 'value' => $value, 'label' => $label, 'removeLabel' => sprintf($remove, $label)];
                    }

                    $range = ((array) ($current['ranges'] ?? []))[$key] ?? null;
                    if ($range) {
                        $label = sprintf(wp_interactivity_state(self::NAMESPACE)['i18n']['facetValue'], $facet['label'] ?? $key, self::rangeLabel((string) ($current['facets'][$key]['min'] ?? ''), (string) ($current['facets'][$key]['max'] ?? '')));
                        $filters[] = ['id' => $key.':range', 'facet' => $key, 'value' => '', 'label' => $label, 'removeLabel' => sprintf($remove, $label)];
                    }
                }

                if (($current['search'] ?? '') !== '') {
                    $label = '« '.$current['search'].' »';
                    $filters[] = ['id' => 'search', 'facet' => '', 'value' => '', 'label' => $label, 'removeLabel' => sprintf($remove, $label)];
                }

                return $filters;
            },
            'totalLabel' => function () use ($listing): string {
                $total = (int) ($listing()['total'] ?? 0);
                $labels = wp_interactivity_state(self::NAMESPACE)['i18n']['total'];

                return sprintf($total === 0 ? $labels['zero'] : ($total === 1 ? $labels['one'] : $labels['many']), $total);
            },
            // The results' region, named after its page: what a screen reader says when it gets the focus
            'resultsLabel' => function () use ($listing): string {
                $current = $listing();
                $i18n = wp_interactivity_state(self::NAMESPACE)['i18n'];
                $pages = (int) ($current['pages'] ?? 1);

                return $pages > 1 ? sprintf($i18n['resultsPage'], (int) ($current['page'] ?? 1), $pages) : $i18n['results'];
            },
            // A facet's search field works in the browser only
            'facetSearchHidden' => fn (): bool => true,
            'facetQuery' => fn (): string => '',
            'noMatch' => fn (): bool => false,
            // A value's link is for search engines and pages without JavaScript; the box is the control
            'linkTabindex' => fn (): ?int => null,
            'hits' => function () use ($listing): array {
                return (array) ($listing()['hits'] ?? []);
            },
            'hasHits' => function () use ($listing): bool {
                return ($listing()['hits'] ?? []) !== [];
            },
            'pageLinks' => function () use ($listing): array {
                return (array) ($listing()['pageLinks'] ?? []);
            },
            'hasFilters' => function () use ($listing): bool {
                $current = $listing();

                return array_filter((array) ($current['values'] ?? [])) !== [] || array_filter((array) ($current['ranges'] ?? [])) !== [] || ($current['search'] ?? '') !== '';
            },
        ]);
    }

    /**
     * The pagination's links as the client transport binds them.
     *
     * @param  list<array{kind: string, page: int, current: bool}>  $items
     * @param  callable(int): string  $url
     * @return list<array{key: string, label: string, url: string|null, current: string|null, hidden: string|null, className: string}>
     */
    public static function pageLinks(array $items, callable $url): array
    {
        return array_map(fn (array $item, int $i) => [
            'key' => $item['kind'].'-'.($item['kind'] === 'dots' ? $i : $item['page']),
            'label' => Renderer::pageLabel($item),
            'url' => $item['kind'] === 'dots' || $item['current'] ? null : $url($item['page']),
            'current' => $item['current'] ? 'page' : null,
            'hidden' => $item['kind'] === 'dots' ? 'true' : null,
            'className' => 'meiliscout-pagination__link meiliscout-pagination__'.$item['kind'],
        ], $items, array_keys($items));
    }

    /**
     * 1000 – 5000, ≥ 1000, ≤ 5000.
     */
    public static function rangeLabel(string $min, string $max): string
    {
        return match (true) {
            $min !== '' && $max !== '' => $min.' – '.$max,
            $min !== '' => '≥ '.$min,
            default => '≤ '.$max,
        };
    }
}
