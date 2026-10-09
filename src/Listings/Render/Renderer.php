<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Render;

use Pollora\MeiliScout\Listings\Definition\FacetDefinition;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Query\ListingResult;
use Pollora\MeiliScout\Listings\Query\PlanTemplate;
use Pollora\MeiliScout\Listings\State\UrlCodec;

/**
 * A listing's HTML: a form that works without JavaScript (GET), the
 * Interactivity API directives the client hydrates, and one router region
 * holding the results and the pagination, which a fragment replaces.
 *
 * Plain markup with stable, prefixed classes (meiliscout-*) and no styles of
 * its own beyond layout: themes style it (design §6.6).
 */
final class Renderer
{
    /**
     * @param  array<string, mixed>  $args  card: callable(WP_Post): string, or a template part name; search: bool
     */
    public static function listing(ListingResult $result, string $base, array $args = []): string
    {
        $definition = $result->definition;
        $id = self::domId($definition);
        $context = wp_json_encode(['listing' => $definition->id]);

        $html = sprintf(
            '<div id="%1$s" class="meiliscout-listing" data-wp-interactive="%2$s" data-wp-context="%3$s" data-meiliscout-contract="%4$d" data-wp-init="callbacks.init">',
            esc_attr($id),
            esc_attr(Store::NAMESPACE),
            esc_attr((string) $context),
            PlanTemplate::VERSION
        );

        $html .= self::form($result, $base, $args);
        $html .= self::region($result, $base, $args);

        return $html.'</div>';
    }

    /**
     * The router region alone: the results and the pagination (a fragment's body).
     *
     * @param  array<string, mixed>  $args
     */
    public static function region(ListingResult $result, string $base, array $args = []): string
    {
        $definition = $result->definition;
        $id = self::domId($definition);

        if ($result->hits !== null) {
            return self::clientRegion($result, $args);
        }

        return sprintf(
            '<div id="%1$s-results" class="meiliscout-listing__results" data-wp-interactive="%2$s" data-wp-router-region="%1$s" data-wp-context="%3$s" data-wp-bind--aria-busy="state.busy">%4$s%5$s</div>',
            esc_attr($id),
            esc_attr(Store::NAMESPACE),
            esc_attr((string) wp_json_encode(['listing' => $definition->id])),
            self::results($result, $args),
            self::pagination($result, $base)
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private static function form(ListingResult $result, string $base, array $args): string
    {
        $definition = $result->definition;
        $id = self::domId($definition);
        $html = sprintf(
            '<form class="meiliscout-listing__filters" method="get" action="%s" aria-controls="%s-results" data-wp-on--submit="actions.submit" data-wp-on--change="actions.change" data-wp-on--input="actions.input">',
            esc_url($base),
            esc_attr($id)
        );

        if ($args['search'] ?? true) {
            $html .= sprintf(
                '<div class="meiliscout-search"><label class="meiliscout-search__label" for="%1$s-search">%2$s</label><input id="%1$s-search" class="meiliscout-search__input" type="search" name="%3$s" value="%4$s" data-wp-bind--value="state.search"></div>',
                esc_attr($id),
                esc_html__('Search', 'meiliscout'),
                esc_attr($definition->searchParam),
                esc_attr($result->state->search)
            );
        }

        foreach ($definition->facets as $facet) {
            $html .= $facet->type === FacetDefinition::RANGE ? self::range($facet, $result) : self::list($facet);
        }

        $html .= '<div class="meiliscout-listing__toolbar">';
        $html .= '<p class="meiliscout-listing__total" aria-live="polite" data-wp-text="state.totalLabel"></p>';
        $html .= self::active();
        $html .= self::sort($definition, $result);
        $html .= sprintf(
            '<button type="submit" class="meiliscout-listing__apply" data-wp-init="callbacks.applyButton">%s</button>',
            esc_html__('Apply', 'meiliscout')
        );
        $html .= sprintf(
            '<a class="meiliscout-listing__reset" href="%s" data-wp-bind--hidden="!state.hasFilters" data-wp-on--click="actions.reset">%s</a>',
            esc_url($base),
            esc_html__('Reset', 'meiliscout')
        );

        return $html.'</div></form>';
    }

    private static function list(FacetDefinition $facet): string
    {
        return sprintf(
            '<fieldset class="meiliscout-facet meiliscout-facet--%1$s" data-facet="%2$s" data-wp-context="%3$s"><legend class="meiliscout-facet__title">%4$s</legend>'
            .'<ul class="meiliscout-facet__options" role="list"><template data-wp-each--option="state.options" data-wp-each-key="context.option.value">'
            .'<li class="meiliscout-facet__option" data-wp-bind--hidden="state.optionHidden" data-wp-bind--data-depth="context.option.depth">'
            .'<label class="meiliscout-facet__label"><input class="meiliscout-facet__input" type="checkbox" name="%5$s" data-wp-bind--value="context.option.value" data-wp-bind--checked="context.option.selected"> '
            .'<span class="meiliscout-facet__text" data-wp-text="context.option.label"></span> '
            .'<span class="meiliscout-facet__count" data-wp-text="context.option.count"></span></label></li>'
            .'</template></ul></fieldset>',
            esc_attr($facet->type),
            esc_attr($facet->key),
            esc_attr((string) wp_json_encode(['facet' => $facet->key])),
            esc_html($facet->label),
            esc_attr($facet->param)
        );
    }

    private static function range(FacetDefinition $facet, ListingResult $result): string
    {
        $step = $facet->decimals > 0 ? '0.'.str_repeat('0', $facet->decimals - 1).'1' : '1';
        $input = fn (string $bound, string $label) => sprintf(
            '<label class="meiliscout-range__bound"><span class="meiliscout-range__label">%1$s</span><input class="meiliscout-range__input" type="number" inputmode="decimal" step="%2$s" name="%3$s_%4$s" data-wp-bind--value="state.range%5$s" data-wp-bind--placeholder="state.range%5$sLimit"></label>',
            esc_html($label),
            esc_attr($step),
            esc_attr($facet->param),
            esc_attr($bound),
            ucfirst($bound)
        );

        return sprintf(
            '<fieldset class="meiliscout-facet meiliscout-facet--range" data-facet="%1$s" data-wp-context="%2$s"><legend class="meiliscout-facet__title">%3$s</legend><div class="meiliscout-range">%4$s%5$s</div></fieldset>',
            esc_attr($facet->key),
            esc_attr((string) wp_json_encode(['facet' => $facet->key])),
            esc_html($facet->label),
            $input('min', __('Min', 'meiliscout')),
            $input('max', __('Max', 'meiliscout'))
        );
    }

    private static function active(): string
    {
        return '<ul class="meiliscout-active" role="list" data-wp-bind--hidden="!state.hasFilters"><template data-wp-each--filter="state.activeFilters" data-wp-each-key="context.filter.id">'
            .'<li class="meiliscout-active__item"><button type="button" class="meiliscout-active__remove" data-wp-on--click="actions.removeFilter" data-wp-bind--aria-label="context.filter.removeLabel">'
            .'<span data-wp-text="context.filter.label"></span> <span aria-hidden="true">×</span></button></li>'
            .'</template></ul>';
    }

    private static function sort(ListingDefinition $definition, ListingResult $result): string
    {
        if (count($definition->sorts) < 2) {
            return '';
        }

        $current = $result->state->sort !== '' ? $result->state->sort : $definition->defaultSort;
        $options = '';

        foreach ($definition->sorts as $key => $sort) {
            $options .= sprintf('<option value="%s"%s>%s</option>', esc_attr($key), selected($key, $current, false), esc_html($sort['label']));
        }

        return sprintf(
            '<label class="meiliscout-sort"><span class="meiliscout-sort__label">%s</span><select class="meiliscout-sort__select" name="%s">%s</select></label>',
            esc_html__('Sort by', 'meiliscout'),
            esc_attr($definition->sortParam),
            $options
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private static function results(ListingResult $result, array $args): string
    {
        $query = $result->query;

        if (! $query->have_posts()) {
            return sprintf('<p class="meiliscout-results__empty">%s</p>', esc_html__('No results match these filters.', 'meiliscout'));
        }

        $html = '<ul class="meiliscout-results" role="list">';

        while ($query->have_posts()) {
            $query->the_post();
            $html .= '<li class="meiliscout-result">'.self::card(get_post(), $args['card'] ?? null).'</li>';
        }
        wp_reset_postdata();

        return $html.'</ul>';
    }

    /**
     * A card: the site's callable, its template part (meiliscout/card.php in
     * the theme, or the name given), or a title, a date and an excerpt.
     */
    private static function card(\WP_Post $post, mixed $card): string
    {
        if (is_callable($card)) {
            return (string) $card($post);
        }

        $template = locate_template(is_string($card) && $card !== '' ? $card.'.php' : 'meiliscout/card.php');

        if ($template !== '') {
            ob_start();
            load_template($template, false, ['post' => $post]);

            return (string) ob_get_clean();
        }

        return sprintf(
            '<article class="meiliscout-card"><h3 class="meiliscout-card__title"><a href="%s">%s</a></h3><p class="meiliscout-card__meta"><time datetime="%s">%s</time></p><div class="meiliscout-card__excerpt">%s</div></article>',
            esc_url((string) get_permalink($post)),
            esc_html(get_the_title($post)),
            esc_attr((string) get_the_date('c', $post)),
            esc_html((string) get_the_date('', $post)),
            wp_kses_post(wpautop(get_the_excerpt($post)))
        );
    }

    /**
     * Previous, numbers (first, last, two around the current one), next: real
     * links to each page's canonical URL, which the client loads in place.
     */
    private static function pagination(ListingResult $result, string $base): string
    {
        $items = self::pageItems($result->state->page, $result->pages());

        if ($items === []) {
            return '';
        }

        $html = sprintf('<nav class="meiliscout-pagination" aria-label="%s">', esc_attr__('Pages', 'meiliscout'));

        foreach ($items as $item) {
            $label = self::pageLabel($item);
            $class = 'meiliscout-pagination__link meiliscout-pagination__'.$item['kind'];

            $html .= match (true) {
                $item['kind'] === 'dots' => '<span class="meiliscout-pagination__dots" aria-hidden="true">…</span>',
                $item['current'] => sprintf('<span class="%s" aria-current="page">%s</span>', esc_attr($class), esc_html($label)),
                default => sprintf('<a class="%s" href="%s" data-wp-on--click="actions.navigate">%s</a>', esc_attr($class), esc_url(UrlCodec::url($result->definition, $result->state->onPage($item['page']), $base)), esc_html($label)),
            };
        }

        return $html.'</nav>';
    }

    /**
     * The pagination's items: previous, the first and last pages and two
     * around the current one with dots between, next. The client builds the
     * same (resources/listings/hits.js, tests/fixtures/listings/card-cases.json).
     *
     * @return list<array{kind: 'previous'|'number'|'dots'|'next', page: int, current: bool}>
     */
    public static function pageItems(int $page, int $pages): array
    {
        if ($pages < 2) {
            return [];
        }

        $current = min(max(1, $page), $pages);
        $items = [];

        if ($current > 1) {
            $items[] = ['kind' => 'previous', 'page' => $current - 1, 'current' => false];
        }

        $previous = 0;
        for ($n = 1; $n <= $pages; $n++) {
            if ($n !== 1 && $n !== $pages && abs($n - $current) > 2) {
                continue;
            }
            if ($n - $previous > 1) {
                $items[] = ['kind' => 'dots', 'page' => 0, 'current' => false];
            }
            $items[] = ['kind' => 'number', 'page' => $n, 'current' => $n === $current];
            $previous = $n;
        }

        if ($current < $pages) {
            $items[] = ['kind' => 'next', 'page' => $current + 1, 'current' => false];
        }

        return $items;
    }

    /**
     * @param  array{kind: string, page: int}  $item
     */
    public static function pageLabel(array $item): string
    {
        return match ($item['kind']) {
            'previous' => __('Previous', 'meiliscout'),
            'next' => __('Next', 'meiliscout'),
            'dots' => '…',
            default => (string) $item['page'],
        };
    }

    /**
     * The client transport's region: cards and pagination from the store,
     * rendered here for the first page, then by the client.
     *
     * @param  array<string, mixed>  $args  client_card: the card's markup, bound to context.hit
     */
    private static function clientRegion(ListingResult $result, array $args): string
    {
        $definition = $result->definition;
        $id = self::domId($definition);

        return sprintf(
            '<div id="%1$s-results" class="meiliscout-listing__results" data-wp-interactive="%2$s" data-wp-router-region="%1$s" data-wp-context="%3$s" data-wp-bind--aria-busy="state.busy">'
            .'<p class="meiliscout-results__empty" data-wp-bind--hidden="state.hasHits">%4$s</p>'
            .'<ul class="meiliscout-results" role="list" data-wp-bind--hidden="!state.hasHits"><template data-wp-each--hit="state.hits" data-wp-each-key="context.hit.id"><li class="meiliscout-result">%5$s</li></template></ul>'
            .'<nav class="meiliscout-pagination" aria-label="%6$s" data-wp-bind--hidden="!state.pageLinks.length"><template data-wp-each--link="state.pageLinks" data-wp-each-key="context.link.key">'
            .'<a data-wp-bind--class="context.link.className" data-wp-bind--href="context.link.url" data-wp-bind--aria-current="context.link.current" data-wp-bind--aria-hidden="context.link.hidden" data-wp-on--click="actions.navigate" data-wp-text="context.link.label"></a>'
            .'</template></nav></div>',
            esc_attr($id),
            esc_attr(Store::NAMESPACE),
            esc_attr((string) wp_json_encode(['listing' => $definition->id])),
            esc_html__('No results match these filters.', 'meiliscout'),
            self::clientCard($args),
            esc_attr__('Pages', 'meiliscout')
        );
    }

    /**
     * The card of the client transport: the site's markup (client_card, or the
     * theme's meiliscout/client-card.php), bound to context.hit with the
     * Interactivity API's directives; else a title, a date and an excerpt.
     *
     * @param  array<string, mixed>  $args
     */
    private static function clientCard(array $args): string
    {
        if (is_string($args['client_card'] ?? null) && $args['client_card'] !== '') {
            return $args['client_card'];
        }

        $template = locate_template('meiliscout/client-card.php');

        if ($template !== '') {
            ob_start();
            load_template($template, false);

            return (string) ob_get_clean();
        }

        return '<article class="meiliscout-card"><h3 class="meiliscout-card__title"><a data-wp-bind--href="context.hit.url" data-wp-text="context.hit.title"></a></h3>'
            .'<p class="meiliscout-card__meta"><time data-wp-bind--datetime="context.hit.date" data-wp-text="context.hit.dateLabel"></time></p>'
            .'<div class="meiliscout-card__excerpt"><p data-wp-text="context.hit.excerpt"></p></div></article>';
    }

    public static function domId(ListingDefinition $definition): string
    {
        return 'meiliscout-listing-'.$definition->id;
    }
}
