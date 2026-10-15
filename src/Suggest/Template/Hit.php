<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Template;

/**
 * What a suggestion's template binds: the attributes and elements that bind a
 * suggestion's data in the browser. A template is rendered once, when the
 * page is, and the client clones its markup for every result: a template
 * prints *bindings*, never the data.
 *
 *     <a <?= $hit->option() ?> <?= $hit->href() ?>>          PHP:   $hit
 *       <img <?= $hit->attr('src', 'image') ?> alt="">
 *       <?= $hit->title() ?>
 *       <span <?= $hit->text('meta') ?>></span>
 *     </a>
 *
 * Blade: `{{ $hit->text('meta') }}` (or `{!! !!}`). Twig: `{{ hit.text('meta') }}`.
 *
 * What a suggestion holds (the client's contract, resources/suggest/client.js):
 * `title`, `titleParts`, `url`, `type`, `meta` (the type's label), `image`,
 * `imageAlt`, `count`, `excerpt`, `date`, `metas.<key>` (a declared public meta
 * key) and `doc.<field>` (a field of the document, as Meilisearch returns it).
 * Everything is text: it is bound with data-wp-text, never as HTML.
 */
final class Hit
{
    /** @var list<string> */
    public const FIELDS = ['title', 'url', 'type', 'meta', 'image', 'imageAlt', 'count', 'excerpt', 'date'];

    /**
     * @param  \Closure(string): mixed|null  $wrap  How printed HTML is handed out (Twig's Markup); Attributes by default
     */
    public function __construct(private readonly ?\Closure $wrap = null) {}

    /**
     * The attributes of the suggestion's root element: an option of the list
     * box, active when the keyboard is on it. A template that
     * leaves them out is wrapped in an element that has them.
     */
    public function option(): mixed
    {
        return $this->out(' role="option" tabindex="-1" data-wp-bind--id="context.hit.optionId" data-wp-bind--aria-selected="state.selected" data-wp-class--is-active="state.active"');
    }

    /**
     * The suggestion's address as the `href` of a link.
     */
    public function href(string $field = 'url'): mixed
    {
        return $this->attr('href', $field);
    }

    /**
     * A field as the text of the element.
     */
    public function text(string $field): mixed
    {
        return $this->out(' data-wp-text="context.hit.'.self::path($field).'"');
    }

    /**
     * A field as an attribute (src, href, alt, datetime...).
     */
    public function attr(string $attribute, string $field): mixed
    {
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $attribute) || str_starts_with($attribute, 'on')) {
            throw new \InvalidArgumentException(sprintf('MeiliScout suggest: "%s" is not an attribute a template may bind.', $attribute));
        }

        return $this->out(' data-wp-bind--'.$attribute.'="context.hit.'.self::path($field).'"');
    }

    /**
     * Shows the element only when the field has a value (an image, a count).
     */
    public function show(string $field): mixed
    {
        return $this->out(' data-wp-bind--hidden="!context.hit.'.self::path($field).'"');
    }

    /**
     * Hides the element when the field has a value.
     */
    public function hide(string $field): mixed
    {
        return $this->out(' data-wp-bind--hidden="context.hit.'.self::path($field).'"');
    }

    /**
     * A class the element has while the field has a value.
     */
    public function toggle(string $class, string $field): mixed
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $class)) {
            throw new \InvalidArgumentException(sprintf('MeiliScout suggest: "%s" is not a class a template may toggle.', $class));
        }

        return $this->out(' data-wp-class--'.$class.'="context.hit.'.self::path($field).'"');
    }

    /**
     * The title, with what the visitor typed marked: an element with a span
     * for each part, the matching ones in a `meiliscout-suggest__match` span.
     */
    public function title(string $class = 'meiliscout-suggest__title'): mixed
    {
        return $this->out(sprintf(
            '<span class="%s"><template data-wp-each--part="context.hit.titleParts" data-wp-each-key="context.part.key">'
            .'<span data-wp-text="context.part.text" data-wp-class--meiliscout-suggest__match="context.part.match"></span></template></span>',
            esc_attr($class)
        ));
    }

    /**
     * A field's path in a suggestion, checked: a template cannot bind what a suggestion does not hold.
     */
    private static function path(string $field): string
    {
        if (in_array($field, self::FIELDS, true) || preg_match('/^(metas|doc)\.[A-Za-z0-9_.-]+$/', $field)) {
            return $field;
        }

        throw new \InvalidArgumentException(sprintf('MeiliScout suggest: a suggestion has no "%s" (it has %s, metas.<key> and doc.<field>).', $field, implode(', ', self::FIELDS)));
    }

    private function out(string $html): mixed
    {
        if ($this->wrap !== null) {
            return ($this->wrap)($html);
        }

        return interface_exists(\Illuminate\Contracts\Support\Htmlable::class) ? new HtmlableAttributes($html) : new Attributes($html);
    }
}
