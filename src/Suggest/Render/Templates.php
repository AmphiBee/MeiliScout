<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Render;

use Pollora\MeiliScout\Listings\Template\Engines;
use Pollora\MeiliScout\Suggest\Definition\SuggestDefinition;
use Pollora\MeiliScout\Suggest\Definition\SuggestSource;
use Pollora\MeiliScout\Suggest\Template\Hit;
use Pollora\MeiliScout\Suggest\Template\SuggestTemplate;

/**
 * How a suggestion is drawn: the markup of one hit, bound to `context.hit`
 * with the Interactivity API's directives and cloned by the client for each
 * result. Compiled once, when the page is rendered: no PHP runs while the
 * visitor types.
 *
 * A source's `template` is one of:
 *
 * - nothing: the default (an image, the title with the match in bold, the type);
 * - a string of HTML, starting with `<`;
 * - `blade:<view>`, `twig:<template>`: rendered with `$hit` (`hit` in Twig),
 *   `$source` and `$suggest`;
 * - a theme template part (`meiliscout/suggest-hit`, looked up as
 *   `<name>.php`), with the same variables in `$args`;
 * - a callable `(SuggestSource, SuggestDefinition, Hit): string|array`, or a
 *   {@see SuggestTemplate};
 * - `js:<name>`: drawn in the browser by a function the theme registers
 *   (`window.MeiliScout.suggestTemplates[name] = (hit, { html }) => html`...``).
 *
 * What a template binds is {@see Hit}'s: everything is text, bound with
 * `data-wp-text`, never as HTML.
 */
final class Templates
{
    /**
     * A source's template, compiled.
     *
     * @return array{markup: string, fields: list<string>, js: string|null}
     */
    public static function for(SuggestSource $source, SuggestDefinition $definition): array
    {
        $fields = self::defaultFields($source);
        $js = null;
        $markup = null;
        $template = $source->template;

        if (is_string($template) && str_starts_with($template, 'js:')) {
            $js = substr($template, 3);
            if (! preg_match('/^[A-Za-z0-9_-]+$/', $js)) {
                error_log(sprintf('MeiliScout: the suggest "%s", source "%s": "%s" is not a name for a JavaScript template.', $definition->id, $source->key, $js));
                $js = null;
            }
        } else {
            $markup = self::markup($source, $definition);
        }

        array_push($fields, ...$source->fields);

        if (is_array($markup)) {
            array_push($fields, ...$markup['fields']);
            $markup = $markup['markup'];
        }

        $compiled = [
            'markup' => $js !== null ? '' : self::asOption($markup ?? self::default()),
            'fields' => array_values(array_unique($fields)),
            'js' => $js,
        ];

        /**
         * Filters a source's compiled template: its markup (one root element,
         * bound to context.hit), the document fields it reads, and the name
         * of its JavaScript template (then without markup).
         *
         * @param  array{markup: string, fields: list<string>, js: string|null}  $compiled
         * @param  SuggestSource  $source  The source.
         * @param  SuggestDefinition  $definition  The field.
         */
        $filtered = apply_filters('meiliscout/suggest/template', $compiled, $source, $definition);

        /** @var mixed $filtered */
        return is_array($filtered) && is_string($filtered['markup'] ?? null) && is_array($filtered['fields'] ?? null)
            ? ['markup' => $filtered['markup'], 'fields' => array_values(array_map('strval', $filtered['fields'])), 'js' => isset($filtered['js']) && is_string($filtered['js']) ? $filtered['js'] : null]
            : $compiled;
    }

    /**
     * Every source's template, by source key.
     *
     * @return array<string, array{markup: string, fields: list<string>, js: string|null}>
     */
    public static function forDefinition(SuggestDefinition $definition): array
    {
        $templates = [];

        foreach ($definition->sources as $key => $source) {
            $templates[$key] = self::for($source, $definition);
        }

        return $templates;
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
        $hit = new Hit;

        return '<a class="meiliscout-suggest__option"'.$hit->option().$hit->href().'>'
            .'<span class="meiliscout-suggest__thumb" aria-hidden="true"'.$hit->show('image').'>'
            .'<img alt="" loading="lazy" decoding="async"'.$hit->attr('src', 'image').'></span>'
            .'<span class="meiliscout-suggest__body">'.$hit->title()
            .'<span class="meiliscout-suggest__meta"'.$hit->text('meta').'></span>'
            .'</span></a>';
    }

    /**
     * The markup a template gives, null for the default.
     *
     * @return string|array{markup: string, fields: list<string>}|null
     */
    private static function markup(SuggestSource $source, SuggestDefinition $definition): string|array|null
    {
        $template = $source->template;

        $text = is_string($template) ? $template : null;

        try {
            $html = match (true) {
                $template === null => null,
                $template instanceof SuggestTemplate => $template->markup($source, $definition, new Hit),
                $text === null => call_user_func($template, $source, $definition, new Hit),
                str_starts_with(ltrim($text), '<') => $text,
                str_starts_with($text, 'blade:') => self::blade(substr($text, 6), $source, $definition),
                str_starts_with($text, 'twig:') => self::twig(substr($text, 5), $source, $definition),
                default => self::part($text, $source, $definition),
            };
        } catch (\Throwable $e) {
            error_log(sprintf('MeiliScout: the suggest "%s", source "%s": the template failed (%s): default suggestions.', $definition->id, $source->key, $e->getMessage()));

            return null;
        }

        if (is_array($html)) {
            return is_string($html['markup'] ?? null) && trim($html['markup']) !== ''
                ? ['markup' => $html['markup'], 'fields' => array_values(array_map('strval', (array) ($html['fields'] ?? [])))]
                : null;
        }

        return is_string($html) && trim($html) !== '' ? $html : null;
    }

    private static function blade(string $view, SuggestSource $source, SuggestDefinition $definition): ?string
    {
        $factory = Engines::blade();

        if ($factory === null) {
            error_log(sprintf('MeiliScout: the suggest "%s", source "%s" draws its suggestions with Blade, which this site does not have: default suggestions.', $definition->id, $source->key));

            return null;
        }

        return $factory->make($view, ['hit' => new Hit, 'source' => $source, 'suggest' => $definition])->render();
    }

    private static function twig(string $template, SuggestSource $source, SuggestDefinition $definition): ?string
    {
        // Twig escapes what it prints: its Markup is printed as is
        $hit = new Hit(class_exists(\Twig\Markup::class) ? static fn (string $html): \Twig\Markup => new \Twig\Markup($html, 'UTF-8') : null);
        $html = Engines::twig($template, ['hit' => $hit, 'source' => $source, 'suggest' => $definition]);

        if ($html === null) {
            error_log(sprintf('MeiliScout: the suggest "%s", source "%s" draws its suggestions with Twig, which this site does not have: default suggestions.', $definition->id, $source->key));
        }

        return $html;
    }

    /**
     * A template part of the theme, null when it has none.
     */
    private static function part(string $name, SuggestSource $source, SuggestDefinition $definition): ?string
    {
        $file = locate_template($name.'.php');

        if ($file === '') {
            error_log(sprintf('MeiliScout: the suggest "%s", source "%s": the template part "%s" is not in the theme: default suggestions.', $definition->id, $source->key, $name));

            return null;
        }

        ob_start();
        load_template($file, false, ['hit' => new Hit, 'source' => $source, 'suggest' => $definition]);

        return (string) ob_get_clean();
    }

    /**
     * A template without the option's bindings is wrapped in an element that has them.
     */
    private static function asOption(string $markup): string
    {
        $markup = trim($markup);

        if (str_contains($markup, 'data-wp-bind--id="context.hit.optionId"')) {
            return $markup;
        }

        $hit = new Hit;

        return '<div class="meiliscout-suggest__option"'.$hit->option().'>'.$markup.'</div>';
    }
}
