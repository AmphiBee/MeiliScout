<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Render;

use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\Query\ListingQuery;
use Pollora\MeiliScout\Listings\Query\ListingResult;
use Pollora\MeiliScout\Listings\State\ListingState;

/**
 * The UI kit's specimen (docs/LISTINGS.md › UI kit): every component of the
 * listings in every state, rendered by the Renderer itself from listings
 * made up for the page, in the site's theme. /?meiliscout-ui-kit, for
 * administrators, while WP_DEBUG is on.
 *
 * What the client would show (a search in a facet, values folded, results
 * loading) is set on the server's markup: the page loads no script.
 */
final class UiKit
{
    public const QUERY_VAR = 'meiliscout-ui-kit';

    public static function boot(): void
    {
        add_action('template_redirect', [self::class, 'maybeRender'], 1);
    }

    public static function enabled(): bool
    {
        /**
         * Filters whether the UI kit's specimen answers (/?meiliscout-ui-kit).
         *
         * @param  bool  $enabled  Default: WP_DEBUG on, and an administrator.
         */
        return (bool) apply_filters('meiliscout/listings/ui_kit', defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options'));
    }

    public static function maybeRender(): void
    {
        if (! isset($_GET[self::QUERY_VAR]) || ! self::enabled()) {
            return;
        }

        status_header(200);
        nocache_headers();
        add_filter('pre_get_document_title', fn () => __('MeiliScout UI kit', 'meiliscout'));
        add_filter('wp_robots', 'wp_robots_no_robots');
        Listings::enqueueStyle();

        // The theme's dark style variation, or the system's, without a theme switch
        $scheme = isset($_GET['scheme']) && $_GET['scheme'] === 'dark' ? 'dark' : '';

        $sections = self::sections();

        ?><!doctype html>
<html <?php language_attributes(); ?><?php echo $scheme === 'dark' ? ' style="color-scheme: dark"' : ''; ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
<style>
.meiliscout-kit-page { box-sizing: border-box; max-width: 72rem; margin: 0 auto; padding: 2rem clamp(1rem, 4vw, 2.5rem) 4rem; }
.meiliscout-kit-page > section { margin-block: 3rem; }
.meiliscout-kit-page h2 { margin-block: 0 0.25rem; }
.meiliscout-kit-page .meiliscout-kit__note { margin-block: 0 1.25rem; opacity: 0.75; }
.meiliscout-kit__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 16rem), 1fr)); gap: 2rem; align-items: start; }
.meiliscout-kit__row { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; }
.meiliscout-kit__tokens { display: grid; grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); gap: 0.75rem; }
.meiliscout-kit__token { display: grid; gap: 0.25rem; font-size: 0.8125rem; }
.meiliscout-kit__swatch { height: 2.5rem; border: 1px solid var(--meiliscout-border); border-radius: var(--meiliscout-radius); }
.meiliscout-kit__token code { overflow-wrap: anywhere; }
<?php echo $scheme === 'dark' ? 'body { background: #111; color: #eee; }' : ''; ?>
</style>
</head>
<body <?php body_class('meiliscout-kit-body'); ?>>
<?php wp_body_open(); ?>
<main class="meiliscout-kit-page wp-site-blocks">
	<header>
		<h1><?php esc_html_e('MeiliScout UI kit', 'meiliscout'); ?></h1>
		<p class="meiliscout-kit__note"><?php esc_html_e('Every component of the listings in the site’s theme, rendered by the plugin from listings made up for this page. Set a token on any ancestor of a listing to change it; turn Default styles off to see the bare markup.', 'meiliscout'); ?>
			<a href="<?php echo esc_url(add_query_arg(['scheme' => $scheme === 'dark' ? false : 'dark'])); ?>"><?php echo esc_html($scheme === 'dark' ? __('Light', 'meiliscout') : __('Dark', 'meiliscout')); ?></a></p>
	</header>
	<?php foreach ($sections as [$title, $note, $html]) { ?>
	<section>
		<h2><?php echo esc_html($title); ?></h2>
		<?php if ($note !== '') { ?><p class="meiliscout-kit__note"><?php echo esc_html($note); ?></p><?php } ?>
		<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The Renderer's markup ?>
	</section>
	<?php } ?>
</main>
<script>
document.addEventListener( 'click', ( event ) => {
	const open = event.target.closest( '[data-meiliscout-panel]' );
	if ( open ) {
		document.getElementById( open.dataset.meiliscoutPanel )?.showModal();
	}
	if ( event.target.closest( '[data-meiliscout-panel-close]' ) || event.target.matches( 'dialog.meiliscout-panel' ) ) {
		event.target.closest( 'dialog' )?.close();
	}
} );
</script>
<?php wp_footer(); ?>
</body>
</html>
<?php
        exit;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> Title, note, HTML
     */
    private static function sections(): array
    {
        $base = home_url('/kit/');
        $render = fn (ListingResult $result, string $html = '') => wp_interactivity_process_directives($html !== '' ? $html : Renderer::listing($result, $base));
        $part = function (ListingResult $result, string $part, array $args = []) use ($base): string {
            return wp_interactivity_process_directives(Renderer::part($result, $base, $part, $args));
        };

        $full = self::result('kit', new ListingState(['color' => ['blue'], 'size' => ['m']], ['price' => ['min' => 100.0]], '', 2, 'chair'));
        $states = self::result('kit-states', new ListingState(['color' => ['blue', 'green']]));
        $chips = self::result('kit-chips', new ListingState(['size' => ['m'], 'featured' => ['1']]), ['display' => 'chips']);
        $empty = self::result('kit-empty', new ListingState(['color' => ['red'], 'material' => ['oak', 'steel']]), [], 0);
        $rtl = self::result('kit-rtl', new ListingState(['color' => ['blue']]));
        $panel = self::result('kit-panel', new ListingState(['color' => ['blue'], 'size' => ['m']]));

        return [
            [__('Tokens', 'meiliscout'), __('As they resolve here: the theme’s presets, then what the site sets (--meiliscout-color-*, --meiliscout-border-radius…).', 'meiliscout'), self::tokens()],
            [__('A whole listing', 'meiliscout'), __('meiliscout_listing(): a search, a tree, a list with a search field, an AND list, chips, a range, a yes/no; three filters active, page 2.', 'meiliscout'), $render($full)],
            [__('What the client shows', 'meiliscout'), __('A search in a facet\'s values, values folded behind Show more, a search leaving none, results loading.', 'meiliscout'), '<div class="meiliscout-kit__grid">'
                .self::searching($part($states, 'facet', ['facet' => 'color']))
                .self::folded($part($states, 'facet', ['facet' => 'category']))
                .self::noMatch($part($states, 'facet', ['facet' => 'material']))
                .'</div>'.self::busy($part($states, 'results'))],
            [__('Chips and yes/no', 'meiliscout'), __('display: chips on a list and on a yes/no; a selected chip reads as a tick.', 'meiliscout'), '<div class="meiliscout-kit__grid">'.$part($chips, 'facet', ['facet' => 'size']).$part($chips, 'facet', ['facet' => 'material']).$part($chips, 'facet', ['facet' => 'featured']).'</div>'],
            [__('Toolbar', 'meiliscout'), __('The total, the active filters, the sort, the buttons.', 'meiliscout'), '<div class="meiliscout-kit__row">'.$part($full, 'total').$part($full, 'sort').'</div>'.$part($full, 'active').self::buttons()],
            [__('Panel', 'meiliscout'), __('A <dialog> with the filters and its footer: open it.', 'meiliscout'), self::panel($panel, $part)],
            [__('Nothing found', 'meiliscout'), '', $render($empty)],
            [__('Right to left', 'meiliscout'), '', '<div dir="rtl" lang="ar">'.$render($rtl).'</div>'],
        ];
    }

    /**
     * A listing made up for the page: posts, facets of every kind, counts.
     *
     * @param  array<string, mixed>  $facetArgs  Merged into every list facet
     */
    private static function result(string $id, ListingState $state, array $facetArgs = [], int $total = 87): ListingResult
    {
        $indexed = fn () => ['post'];
        $metas = fn () => ['kit_color', 'kit_material', 'kit_size', 'kit_price', 'kit_featured'];
        add_filter('pre_option_meiliscout/indexed_post_types', $indexed);
        add_filter('pre_option_meiliscout/indexed_meta_keys', $metas);

        try {
            $definition = ListingDefinition::fromArray($id, [
                'post_types' => ['post'],
                'per_page' => 6,
                'route' => ['page' => 0],
                'facets' => [
                    'category' => ['source' => 'taxonomy:category', 'label' => __('Category', 'meiliscout'), 'path' => 'cat', 'limit' => 3],
                    'color' => ['source' => 'meta:kit_color', 'label' => __('Color', 'meiliscout'), 'search' => true] + $facetArgs,
                    'material' => ['source' => 'meta:kit_material', 'label' => __('Material', 'meiliscout'), 'logic' => 'and'] + $facetArgs,
                    'size' => ['source' => 'meta:kit_size', 'label' => __('Size', 'meiliscout'), 'display' => 'chips'],
                    'price' => ['source' => 'meta:kit_price', 'type' => 'range', 'label' => __('Price', 'meiliscout')],
                    'featured' => ['source' => 'meta:kit_featured', 'type' => 'boolean', 'label' => __('Featured only', 'meiliscout')] + $facetArgs,
                ],
                'sorts' => [
                    'recent' => ['label' => __('Newest first', 'meiliscout'), 'orderby' => 'date', 'order' => 'DESC'],
                    'title' => ['label' => __('By title', 'meiliscout'), 'orderby' => 'title', 'order' => 'ASC'],
                ],
            ]);
        } finally {
            remove_filter('pre_option_meiliscout/indexed_post_types', $indexed);
            remove_filter('pre_option_meiliscout/indexed_meta_keys', $metas);
        }

        $values = [
            'category' => [['news', 'News', 31, 0], ['events', 'Events', 12, 1], ['workshops', 'Workshops with a rather long name that wraps', 7, 2], ['talks', 'Talks', 5, 1], ['guides', 'Guides', 22, 0], ['archive', 'Archive', 0, 0]],
            'color' => [['blue', 'Blue', 18, 0], ['green', 'Green', 9, 0], ['grey', 'Grey', 14, 0], ['red', 'Red', 6, 0], ['yellow', 'Yellow', 3, 0], ['écru', 'Écru', 4, 0]],
            'material' => [['oak', 'Oak', 11, 0], ['steel', 'Steel', 8, 0], ['glass', 'Glass', 5, 0], ['linen', 'Linen', 2, 0]],
            'size' => [['s', 'S', 12, 0], ['m', 'M', 20, 0], ['l', 'L', 17, 0], ['xl', 'XL', 6, 0]],
            'featured' => [['1', __('Featured only', 'meiliscout'), 9, 0]],
        ];

        $facets = [];
        foreach ($definition->facets as $facet) {
            if (! isset($values[$facet->key])) {
                $facets[$facet->key] = ['options' => [], 'stats' => ['min' => 20.0, 'max' => 940.0]];

                continue;
            }

            $selection = $state->valuesOf($facet->key);
            $orSelection = $facet->logic === 'or' && $selection !== [];
            $options = array_map(fn (array $value) => ListingQuery::option(
                ['value' => $value[0], 'label' => $value[1], 'depth' => $value[3]],
                $total === 0 ? 0 : $value[2],
                in_array($value[0], $selection, true),
                // In an OR list with a selection, what a value adds
                $orSelection && ! in_array($value[0], $selection, true) ? max(1, intdiv($value[2], 2)) : null,
            ), $values[$facet->key]);
            $facets[$facet->key] = ['options' => ListingQuery::withOverflow($options, $facet->limit), 'stats' => null];
        }

        $date = Hits::dateNames();
        $titles = ['Oak dining chair', 'A steel lamp with a long, long name that wraps over two lines', 'Linen cushion', 'Glass vase', 'Grey sofa', 'Blue armchair'];
        $hits = $total === 0 ? [] : array_map(fn (int $n) => Hits::fromDocument([
            'ID' => $n + 1,
            'post_type' => 'post',
            'post_title' => $titles[$n],
            'post_excerpt' => 'A made-up post of the UI kit: its card shows a title, a date and an excerpt.',
            'url' => home_url('/kit/'.($n + 1).'/'),
            'post_date' => sprintf('2026-09-%02d 10:00:00', 28 - $n * 3),
        ], [], $date), range(0, 5));

        $result = new ListingResult($definition, $state, null, $facets, null, null, $hits, $total);
        Store::add($result, home_url('/kit/'));

        return $result;
    }

    private static function tokens(): string
    {
        $colors = ['accent', 'accent-contrast', 'text', 'muted', 'background', 'border', 'surface', 'tint'];
        $html = '<div class="meiliscout-kit meiliscout-kit__tokens">';

        foreach ($colors as $token) {
            $html .= sprintf(
                '<div class="meiliscout-kit__token"><span class="meiliscout-kit__swatch" style="background: var(--meiliscout-%1$s)"></span><code>--meiliscout-%1$s</code></div>',
                esc_attr($token)
            );
        }

        foreach (['radius' => 'border-radius', 'gap' => 'spacing', 'small' => 'font-size-small', 'control' => 'control-height'] as $token => $public) {
            $html .= sprintf(
                '<div class="meiliscout-kit__token"><span class="meiliscout-kit__swatch" style="background: none; width: var(--meiliscout-control); border-radius: var(--meiliscout-radius); border-width: 2px"></span><code>--meiliscout-%1$s</code><small>← --meiliscout-%2$s</small></div>',
                esc_attr($token),
                esc_attr($public)
            );
        }

        return $html.'</div>';
    }

    private static function buttons(): string
    {
        return '<div class="meiliscout-kit meiliscout-kit__row" style="margin-top: 1rem">'
            .sprintf('<button type="button" class="%s">%s</button>', esc_attr(Renderer::buttonClass('primary')), esc_html__('Apply', 'meiliscout'))
            .sprintf('<button type="button" class="%s">%s <span class="meiliscout-button__count">(3)</span></button>', esc_attr(Renderer::buttonClass('secondary')), esc_html__('Filters', 'meiliscout'))
            .sprintf('<button type="button" class="%s" disabled>%s</button>', esc_attr(Renderer::buttonClass('primary')), esc_html__('Disabled', 'meiliscout'))
            .sprintf('<a class="meiliscout-listing__reset" href="#">%s</a>', esc_html__('Reset', 'meiliscout'))
            .'</div>';
    }

    /**
     * @param  callable(ListingResult, string, array<string, mixed>): string  $part
     */
    private static function panel(ListingResult $result, callable $part): string
    {
        $body = $part($result, 'search', []).$part($result, 'facets', []);
        $footer = sprintf('<a class="meiliscout-listing__reset" href="#">%s</a>', esc_html__('Reset', 'meiliscout'))
            .sprintf('<button type="button" class="%s" data-meiliscout-panel-close>%s</button>', esc_attr(Renderer::buttonClass('primary')), esc_html(sprintf(_n('See %d result', 'See %d results', 42, 'meiliscout'), 42)));

        return '<div class="meiliscout-part">'.Renderer::panel('meiliscout-kit-panel', __('Filters', 'meiliscout'), $body, $footer, 2).'</div>';
    }

    /**
     * A facet's search field shown, with text in it, and the values it leaves.
     */
    private static function searching(string $html): string
    {
        $tags = new \WP_HTML_Tag_Processor($html);
        while ($tags->next_tag()) {
            if ($tags->has_class('meiliscout-facet__search')) {
                $tags->remove_attribute('hidden');
            }
            if ($tags->has_class('meiliscout-facet__search-input')) {
                $tags->set_attribute('value', 'gr');
            }
        }

        // The values whose label holds "gr", as the client keeps them
        return (string) preg_replace_callback('#<li class="meiliscout-facet__option"[^>]*>.*?</li>#s', fn (array $m) => preg_match('#>(Green|Grey)<#', $m[0]) ? $m[0] : '', $tags->get_updated_html());
    }

    /**
     * Values past the limit folded, Show more offered.
     */
    private static function folded(string $html): string
    {
        $tags = new \WP_HTML_Tag_Processor($html);
        $index = 0;
        while ($tags->next_tag()) {
            if ($tags->get_tag() === 'LI' && $tags->has_class('meiliscout-facet__option') && $index++ >= 3) {
                $tags->set_attribute('hidden', true);
            }
            if ($tags->has_class('meiliscout-facet__more')) {
                $tags->remove_attribute('hidden');
                $tags->set_attribute('aria-expanded', 'false');
            }
        }

        return $tags->get_updated_html();
    }

    /**
     * A search in a facet that leaves no value.
     */
    private static function noMatch(string $html): string
    {
        $tags = new \WP_HTML_Tag_Processor($html);
        while ($tags->next_tag()) {
            if ($tags->has_class('meiliscout-facet__search') || $tags->has_class('meiliscout-facet__none')) {
                $tags->remove_attribute('hidden');
            }
            if ($tags->has_class('meiliscout-facet__search-input')) {
                $tags->set_attribute('value', 'velvet');
            }
            if ($tags->get_tag() === 'LI' && $tags->has_class('meiliscout-facet__option')) {
                $tags->set_attribute('hidden', true);
            }
        }

        return $tags->get_updated_html();
    }

    /**
     * Results loading.
     */
    private static function busy(string $html): string
    {
        $tags = new \WP_HTML_Tag_Processor($html);
        if ($tags->next_tag(['class_name' => 'meiliscout-listing__results'])) {
            $tags->set_attribute('aria-busy', 'true');
        }

        return $tags->get_updated_html();
    }
}
