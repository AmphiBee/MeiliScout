<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Render;

use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Template\Engines;

/**
 * A listing's cards for the fragment and page transports, as its definition
 * gives them (card), so that the page and the fragments render the same:
 *
 * - a callable(WP_Post, ListingDefinition): string;
 * - 'blade:<view>': a Blade view, with $post and $listing;
 * - 'twig:<template>': a Twig template, with post (Timber's when Timber is active) and listing;
 * - a template part name, looked up in the theme (<name>.php, with $args['post']);
 * - a CardRenderer;
 * - none: the theme's meiliscout/card.php, else a title, a date and an excerpt.
 */
final class Cards
{
    /**
     * Whether a definition's card is one of the forms above.
     */
    public static function isValid(mixed $card): bool
    {
        return $card === null || is_callable($card) || $card instanceof CardRenderer || (is_string($card) && $card !== '');
    }

    public static function render(\WP_Post $post, ListingDefinition $definition): string
    {
        $card = $definition->card;
        $html = match (true) {
            $card instanceof CardRenderer => $card->render($post, $definition),
            is_callable($card) => (string) $card($post, $definition),
            is_string($card) && str_starts_with($card, 'blade:') => self::blade(substr($card, 6), $post, $definition),
            is_string($card) && str_starts_with($card, 'twig:') => self::twig(substr($card, 5), $post, $definition),
            is_string($card) => self::part($card, $post),
            default => self::part('meiliscout/card', $post),
        };

        /**
         * Filters a card's HTML.
         *
         * @param  string  $html
         * @param  \WP_Post  $post
         * @param  ListingDefinition  $definition
         */
        return (string) apply_filters('meiliscout/listings/card', $html ?? self::fallback($post), $post, $definition);
    }

    private static function blade(string $view, \WP_Post $post, ListingDefinition $definition): ?string
    {
        $factory = Engines::blade();

        if ($factory === null) {
            self::missing('Blade', $definition);

            return null;
        }

        return $factory->make($view, ['post' => $post, 'listing' => $definition])->render();
    }

    private static function twig(string $template, \WP_Post $post, ListingDefinition $definition): ?string
    {
        $html = Engines::twig($template, ['post' => $post, 'listing' => $definition]);

        if ($html === null) {
            self::missing('Twig', $definition);
        }

        return $html;
    }

    /**
     * A template part of the theme, null when it has none.
     */
    private static function part(string $name, \WP_Post $post): ?string
    {
        $template = locate_template($name.'.php');

        if ($template === '') {
            return null;
        }

        ob_start();
        load_template($template, false, ['post' => $post]);

        return (string) ob_get_clean();
    }

    private static function fallback(\WP_Post $post): string
    {
        return sprintf(
            '<article class="meiliscout-card"><h3 class="meiliscout-card__title"><a href="%s">%s</a></h3><p class="meiliscout-card__meta"><time datetime="%s">%s</time></p><div class="meiliscout-card__excerpt">%s</div></article>',
            esc_url((string) get_permalink($post)),
            esc_html(get_the_title($post)),
            esc_attr((string) get_the_date('c', $post)),
            esc_html((string) get_the_date('', $post)),
            wp_kses_post(wpautop(get_the_excerpt($post)))
        );
    }

    private static function missing(string $engine, ListingDefinition $definition): void
    {
        static $said = [];

        if (! isset($said[$definition->id])) {
            $said[$definition->id] = true;
            error_log(sprintf('MeiliScout: the listing "%s" renders its cards with %s, which this site does not have: default cards.', $definition->id, $engine));
        }
    }
}
