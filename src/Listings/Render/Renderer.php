<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Render;

use Pollora\MeiliScout\Listings\Definition\FacetDefinition;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Query\ListingResult;
use Pollora\MeiliScout\Listings\Query\PlanTemplate;
use Pollora\MeiliScout\Listings\Seo\SeoPolicy;

/**
 * A listing's HTML: a form that works without JavaScript (GET), the
 * Interactivity API directives the client hydrates, and one router region
 * holding the results, which a fragment replaces.
 *
 * Made of parts a template may also place one by one (meiliscout_listing_part()):
 * intro, search, facet, facets, sort, total, active, apply, reset, results,
 * pagination, faq. The intro (the SEO rule's heading and text) and the faq
 * are router regions too: a fragment changes them with the results. Every field belongs to the listing's one form through the form
 * attribute, wherever it is printed, so that a page without JavaScript sends
 * them all. Stable hooks: data-meiliscout on the listing's elements (listing,
 * form, results, pagination, reset), data-meiliscout-part on a part's wrapper.
 *
 * Plain markup with stable, prefixed classes (meiliscout-*) and no styles:
 * themes style it (design §6.6).
 */
final class Renderer
{
    public const PARTS = ['intro', 'search', 'facet', 'facets', 'sort', 'total', 'active', 'apply', 'reset', 'results', 'pagination', 'faq'];

    /**
     * Listings whose form was printed on this page, by id.
     *
     * @var array<string, true>
     */
    private static array $forms = [];

    /**
     * The whole listing: its SEO rule's heading and text, its form (search,
     * facets, then total, active filters, sort, apply and reset), its results,
     * its pagination and its rule's questions.
     *
     * @param  array<string, mixed>  $args  search: false leaves the search field out
     */
    public static function listing(ListingResult $result, string $base, array $args = []): string
    {
        $definition = $result->definition;
        $id = self::domId($definition);
        self::$forms[$definition->id] = true;

        $fields = ($args['search'] ?? true) ? self::search($result) : '';
        foreach ($definition->facets as $facet) {
            $fields .= self::facet($facet, $result);
        }

        return sprintf(
            '<div id="%1$s" class="meiliscout-listing" data-meiliscout="listing" data-wp-interactive="%2$s" data-wp-context="%3$s">%7$s%4$s%5$s%6$s%8$s</div>',
            esc_attr($id),
            esc_attr(Store::NAMESPACE),
            esc_attr(self::context($definition)),
            self::form($result, $base, $fields.'<div class="meiliscout-listing__toolbar">'.self::total().self::active().self::sort($result).self::apply($result).self::resetLink($base).'</div>'),
            self::region($result),
            self::pagination($result),
            self::intro($result, $base),
            self::faq($result, $base)
        );
    }

    /**
     * One part of a listing, for a template that places them itself.
     *
     * @param  array<string, mixed>  $args  facet: the facet's key (part facet)
     *
     * @throws \InvalidArgumentException An unknown part, or facet
     */
    public static function part(ListingResult $result, string $base, string $part, array $args = []): string
    {
        $definition = $result->definition;

        $html = match ($part) {
            'intro' => self::intro($result, $base),
            'faq' => self::faq($result, $base),
            'search' => self::search($result),
            'facet' => self::facet($definition->facet((string) ($args['facet'] ?? '')) ?? throw new \InvalidArgumentException(sprintf('The listing "%s" has no facet "%s".', $definition->id, (string) ($args['facet'] ?? ''))), $result),
            'facets' => implode('', array_map(fn (FacetDefinition $facet) => self::facet($facet, $result), $definition->facets)),
            'sort' => self::sort($result),
            'total' => self::total(),
            'active' => self::active(),
            'apply' => self::apply($result),
            'reset' => self::resetLink($base),
            'results' => self::region($result),
            'pagination' => self::pagination($result),
            default => throw new \InvalidArgumentException(sprintf('Unknown listing part "%s": %s.', $part, implode(', ', self::PARTS))),
        };

        // The form the fields belong to, once per listing and page, empty: its fields are the parts'
        $form = '';
        if (! isset(self::$forms[$definition->id])) {
            self::$forms[$definition->id] = true;
            $form = self::form($result, $base, '');
        }

        return $form.sprintf(
            '<div class="meiliscout-part meiliscout-part--%1$s" data-meiliscout-part="%1$s" data-wp-interactive="%2$s" data-wp-context="%3$s" data-wp-on--change="actions.change" data-wp-on--input="actions.input">%4$s</div>',
            esc_attr($part),
            esc_attr(Store::NAMESPACE),
            esc_attr(self::context($definition)),
            $html
        );
    }

    /**
     * The listing's form, empty, when no part printed it yet on this page.
     */
    public static function formOnce(ListingResult $result, string $base): string
    {
        if (isset(self::$forms[$result->definition->id])) {
            return '';
        }

        self::$forms[$result->definition->id] = true;

        return self::form($result, $base, '');
    }

    /**
     * The router region alone: the results (a fragment's body).
     */
    public static function region(ListingResult $result): string
    {
        $definition = $result->definition;
        $id = self::domId($definition);
        $results = $result->hits !== null ? self::clientResults($result) : self::results($result);

        return sprintf(
            '<div id="%1$s-results" class="meiliscout-listing__results" data-meiliscout="results" data-wp-interactive="%2$s" data-wp-router-region="%1$s" data-wp-context="%3$s" data-wp-bind--aria-busy="state.busy">%4$s</div>',
            esc_attr($id),
            esc_attr(Store::NAMESPACE),
            esc_attr(self::context($definition)),
            $results
        );
    }

    /**
     * Every router region of a listing: what a fragment's body holds. The
     * router ignores the ones the page has not printed.
     */
    public static function regions(ListingResult $result, string $base): string
    {
        return self::region($result).self::intro($result, $base).self::faq($result, $base);
    }

    /**
     * The SEO rule's heading and text for this state (a router region, empty
     * without them). The client transport hides it once the state changed:
     * the rule it shows is the first state's.
     */
    public static function intro(ListingResult $result, string $base): string
    {
        $view = SeoPolicy::viewOf($result, $base);
        $html = '';

        if ($view->h1 !== '') {
            $html .= sprintf('<h1 class="meiliscout-listing__heading">%s</h1>', esc_html($view->h1));
        }
        if ($view->intro !== '') {
            $html .= sprintf('<div class="meiliscout-listing__text">%s</div>', wpautop(wp_kses_post($view->intro)));
        }

        return self::seoRegion($result, 'intro', $html);
    }

    /**
     * The SEO rule's questions and answers for this state (a router region).
     */
    public static function faq(ListingResult $result, string $base): string
    {
        $html = '';

        foreach (SeoPolicy::viewOf($result, $base)->faq as $pair) {
            $html .= sprintf(
                '<details class="meiliscout-faq__item"><summary class="meiliscout-faq__question">%s</summary><div class="meiliscout-faq__answer">%s</div></details>',
                esc_html($pair['question']),
                wpautop(wp_kses_post($pair['answer']))
            );
        }

        return self::seoRegion($result, 'faq', $html);
    }

    private static function seoRegion(ListingResult $result, string $name, string $html): string
    {
        $id = self::domId($result->definition).'-'.$name;

        return sprintf(
            '<div id="%1$s" class="meiliscout-listing__%2$s" data-meiliscout="%2$s" data-wp-interactive="%3$s" data-wp-router-region="%1$s" data-wp-context="%4$s" data-wp-bind--hidden="state.ruleStale">%5$s</div>',
            esc_attr($id),
            esc_attr($name),
            esc_attr(Store::NAMESPACE),
            esc_attr(self::context($result->definition)),
            $html
        );
    }

    /**
     * Forgets the forms printed (a new page: tests, the fragment endpoint).
     */
    public static function forgetForms(): void
    {
        self::$forms = [];
    }

    private static function resetLink(string $base): string
    {
        return sprintf(
            '<a class="meiliscout-listing__reset" data-meiliscout="reset" href="%s" data-wp-bind--hidden="!state.hasFilters" data-wp-on--click="actions.reset">%s</a>',
            esc_url($base),
            esc_html__('Reset', 'meiliscout')
        );
    }

    public static function domId(ListingDefinition $definition): string
    {
        return 'meiliscout-listing-'.$definition->id;
    }

    /**
     * The id of the listing's form, which every field names (form="…").
     */
    public static function formId(ListingDefinition $definition): string
    {
        return self::domId($definition).'-form';
    }

    /**
     * The form, where the client starts (callbacks.init) and the fields go.
     */
    private static function form(ListingResult $result, string $base, string $fields): string
    {
        $definition = $result->definition;

        return sprintf(
            '<form id="%1$s" class="meiliscout-listing__filters" data-meiliscout="form" method="get" action="%2$s" aria-controls="%3$s-results" data-meiliscout-contract="%4$d" data-wp-interactive="%5$s" data-wp-context="%6$s" data-wp-init="callbacks.init" data-wp-on--submit="actions.submit" data-wp-on--change="actions.change" data-wp-on--input="actions.input">%7$s</form>',
            esc_attr(self::formId($definition)),
            esc_url($base),
            esc_attr(self::domId($definition)),
            PlanTemplate::VERSION,
            esc_attr(Store::NAMESPACE),
            esc_attr(self::context($definition)),
            $fields
        );
    }

    private static function search(ListingResult $result): string
    {
        $definition = $result->definition;

        return sprintf(
            '<div class="meiliscout-search"><label class="meiliscout-search__label" for="%1$s-search">%2$s</label><input id="%1$s-search" class="meiliscout-search__input" type="search" name="%3$s" value="%4$s" form="%5$s" data-wp-bind--value="state.search"></div>',
            esc_attr(self::domId($definition)),
            esc_html__('Search', 'meiliscout'),
            esc_attr($definition->searchParam),
            esc_attr($result->state->search),
            esc_attr(self::formId($definition))
        );
    }

    private static function facet(FacetDefinition $facet, ListingResult $result): string
    {
        return $facet->type === FacetDefinition::RANGE ? self::range($facet, $result) : self::list($facet, $result);
    }

    private static function list(FacetDefinition $facet, ListingResult $result): string
    {
        return sprintf(
            '<fieldset class="meiliscout-facet meiliscout-facet--%1$s" data-facet="%2$s" data-wp-context="%3$s"><legend class="meiliscout-facet__title">%4$s</legend>'
            .'<ul class="meiliscout-facet__options" role="list"><template data-wp-each--option="state.options" data-wp-each-key="context.option.value">'
            .'<li class="meiliscout-facet__option" data-wp-bind--hidden="state.optionHidden" data-wp-bind--data-depth="context.option.depth">'
            .'<label class="meiliscout-facet__label"><input class="meiliscout-facet__input" type="checkbox" name="%5$s" form="%6$s" data-wp-bind--value="context.option.value" data-wp-bind--checked="context.option.selected"> '
            .'<a class="meiliscout-facet__text" data-wp-bind--href="context.option.url" data-wp-on--click="actions.follow" data-wp-text="context.option.label"></a> '
            .'<span class="meiliscout-facet__count" data-wp-text="context.option.count"></span></label></li>'
            .'</template></ul>'
            .'<button type="button" class="meiliscout-facet__more" hidden data-wp-bind--hidden="!state.hasOverflow" data-wp-bind--aria-expanded="state.expanded" data-wp-on--click="actions.toggleMore" data-wp-text="state.moreLabel"></button>'
            .'</fieldset>',
            esc_attr($facet->type),
            esc_attr($facet->key),
            esc_attr((string) wp_json_encode(['facet' => $facet->key])),
            esc_html($facet->label),
            esc_attr($facet->param),
            esc_attr(self::formId($result->definition))
        );
    }

    private static function range(FacetDefinition $facet, ListingResult $result): string
    {
        $step = $facet->decimals > 0 ? '0.'.str_repeat('0', $facet->decimals - 1).'1' : '1';
        $input = fn (string $bound, string $label) => sprintf(
            '<label class="meiliscout-range__bound"><span class="meiliscout-range__label">%1$s</span><input class="meiliscout-range__input" type="number" inputmode="decimal" step="%2$s" name="%3$s_%4$s" form="%6$s" data-wp-bind--value="state.range%5$s" data-wp-bind--placeholder="state.range%5$sLimit"></label>',
            esc_html($label),
            esc_attr($step),
            esc_attr($facet->param),
            esc_attr($bound),
            ucfirst($bound),
            esc_attr(self::formId($result->definition))
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

    private static function total(): string
    {
        return '<p class="meiliscout-listing__total" aria-live="polite" data-wp-text="state.totalLabel"></p>';
    }

    private static function active(): string
    {
        return '<ul class="meiliscout-active" role="list" data-wp-bind--hidden="!state.hasFilters"><template data-wp-each--filter="state.activeFilters" data-wp-each-key="context.filter.id">'
            .'<li class="meiliscout-active__item"><button type="button" class="meiliscout-active__remove" data-wp-on--click="actions.removeFilter" data-wp-bind--aria-label="context.filter.removeLabel">'
            .'<span data-wp-text="context.filter.label"></span> <span aria-hidden="true">×</span></button></li>'
            .'</template></ul>';
    }

    private static function sort(ListingResult $result): string
    {
        $definition = $result->definition;

        if (count($definition->sorts) < 2) {
            return '';
        }

        $current = $result->state->sort !== '' ? $result->state->sort : $definition->defaultSort;
        $options = '';

        foreach ($definition->sorts as $key => $sort) {
            $options .= sprintf('<option value="%s"%s>%s</option>', esc_attr($key), selected($key, $current, false), esc_html($sort['label']));
        }

        return sprintf(
            '<label class="meiliscout-sort"><span class="meiliscout-sort__label">%s</span><select class="meiliscout-sort__select" name="%s" form="%s">%s</select></label>',
            esc_html__('Sort by', 'meiliscout'),
            esc_attr($definition->sortParam),
            esc_attr(self::formId($definition)),
            $options
        );
    }

    /**
     * Hidden by the client while changes apply at once; the button of a page
     * without JavaScript, and of the button mode.
     */
    private static function apply(ListingResult $result): string
    {
        return sprintf(
            '<button type="submit" form="%s" class="meiliscout-listing__apply" data-wp-init="callbacks.applyButton" data-wp-text="state.applyLabel">%s</button>',
            esc_attr(self::formId($result->definition)),
            esc_html__('Apply', 'meiliscout')
        );
    }

    private static function results(ListingResult $result): string
    {
        $query = $result->query;

        if ($query === null || ! $query->have_posts()) {
            return sprintf('<p class="meiliscout-results__empty">%s</p>', esc_html__('No results match these filters.', 'meiliscout'));
        }

        $html = '<ul class="meiliscout-results" role="list">';

        while ($query->have_posts()) {
            $query->the_post();
            $html .= '<li class="meiliscout-result">'.Cards::render(get_post(), $result->definition).'</li>';
        }
        wp_reset_postdata();

        return $html.'</ul>';
    }

    /**
     * The client transport's cards, from the store: rendered here for the
     * first page, then by the client.
     */
    private static function clientResults(ListingResult $result): string
    {
        return sprintf(
            '<p class="meiliscout-results__empty" data-wp-bind--hidden="state.hasHits">%s</p>'
            .'<ul class="meiliscout-results" role="list" data-wp-bind--hidden="!state.hasHits"><template data-wp-each--hit="state.hits" data-wp-each-key="context.hit.id"><li class="meiliscout-result">%s</li></template></ul>',
            esc_html__('No results match these filters.', 'meiliscout'),
            self::clientCard($result->definition)
        );
    }

    /**
     * The pagination, from the store (Store::pageLinks()): real links to each
     * page's canonical URL, which the client loads in place.
     */
    private static function pagination(ListingResult $result): string
    {
        return sprintf(
            '<nav class="meiliscout-pagination" data-meiliscout="pagination" aria-label="%s" data-wp-bind--hidden="!state.pageLinks.length"><template data-wp-each--link="state.pageLinks" data-wp-each-key="context.link.key">'
            .'<a data-wp-bind--class="context.link.className" data-wp-bind--href="context.link.url" data-wp-bind--aria-current="context.link.current" data-wp-bind--aria-hidden="context.link.hidden" data-wp-on--click="actions.navigate" data-wp-text="context.link.label"></a>'
            .'</template></nav>',
            esc_attr__('Pages', 'meiliscout')
        );
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
     * The card of the client transport: the definition's client_card, else
     * the theme's meiliscout/client-card.php, bound to context.hit with the
     * Interactivity API's directives; else a title, a date and an excerpt.
     */
    private static function clientCard(ListingDefinition $definition): string
    {
        if ($definition->clientCard !== null) {
            return $definition->clientCard;
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

    private static function context(ListingDefinition $definition): string
    {
        return (string) wp_json_encode(['listing' => $definition->id]);
    }
}
