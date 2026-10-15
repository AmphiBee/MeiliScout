<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Render;

use Pollora\MeiliScout\Suggest\Definition\SuggestSource;

/**
 * How a suggestion is drawn: the markup of one hit, bound to `context.hit`
 * with the Interactivity API's directives and cloned by the client for each
 * result. Compiled once, when the page is rendered: no PHP runs while the
 * visitor types.
 *
 * A suggestion's data (what a template may bind), the same for every source:
 *
 * | `context.hit.`    | |
 * |---|---|
 * | `title`           | The title, as text |
 * | `titleParts`      | The title cut in `{text, match}`: `match` is true for what the visitor typed |
 * | `url`             | Its address |
 * | `meta`            | The type's label (a post type, a taxonomy) |
 * | `image`, `imageAlt` | The featured image (empty without one) |
 * | `count`           | A term's number of posts |
 * | `excerpt`         | When the template asked for it (`fields`) |
 * | `metas.<key>`     | A declared public meta key |
 *
 * Everything is text: bind it with `data-wp-text`, never as HTML.
 */
final class Templates
{
    /**
     * What a source's suggestion is made of, and what to ask Meilisearch for.
     *
     * @return array{markup: string, fields: list<string>}
     */
    public static function for(SuggestSource $source): array
    {
        $template = ['markup' => self::default(), 'fields' => self::defaultFields($source)];

        /**
         * Filters the template of a source: its markup (one root element,
         * bound to context.hit) and the document fields it reads.
         *
         * @param  array{markup: string, fields: list<string>}  $template
         * @param  SuggestSource  $source  The source.
         */
        /** @var mixed $filtered */
        $filtered = apply_filters('meiliscout/suggest/template', $template, $source);

        return is_array($filtered) && is_string($filtered['markup'] ?? null) && is_array($filtered['fields'] ?? null)
            ? ['markup' => $filtered['markup'], 'fields' => array_values(array_map('strval', $filtered['fields']))]
            : $template;
    }

    /**
     * The document fields the default suggestion reads.
     *
     * @return list<string>
     */
    public static function defaultFields(SuggestSource $source): array
    {
        return $source->isPosts()
            ? ['ID', 'post_title', 'url', 'post_type', 'thumbnail']
            : ['term_id', 'name', 'url', 'taxonomy', 'count'];
    }

    /**
     * An image, the title with what was typed marked, and the type's label.
     */
    public static function default(): string
    {
        return '<a class="meiliscout-suggest__option" role="option" tabindex="-1"'
            .' data-wp-bind--id="context.hit.optionId" data-wp-bind--href="context.hit.url"'
            .' data-wp-bind--aria-selected="state.selected" data-wp-class--is-active="state.active">'
            .'<span class="meiliscout-suggest__thumb" aria-hidden="true" data-wp-bind--hidden="!context.hit.image">'
            .'<img alt="" loading="lazy" decoding="async" data-wp-bind--src="context.hit.image"></span>'
            .'<span class="meiliscout-suggest__body">'
            .'<span class="meiliscout-suggest__title"><template data-wp-each--part="context.hit.titleParts" data-wp-each-key="context.part.key">'
            .'<span data-wp-text="context.part.text" data-wp-class--meiliscout-suggest__match="context.part.match"></span></template></span>'
            .'<span class="meiliscout-suggest__meta" data-wp-text="context.hit.meta"></span>'
            .'</span></a>';
    }
}
