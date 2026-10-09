<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Template;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The listings' Twig functions, the PHP API's:
 *
 *     {{ meiliscout_listing('projects') }}
 *     {{ meiliscout_facet('projects', 'type') }}
 *     {{ meiliscout_listing_part('projects', 'sort') }}
 *     {{ meiliscout_active_filters('projects') }}
 *     {{ meiliscout_listing_results('projects') }}
 *     {{ meiliscout_pagination('projects') }}
 *
 * Added to Timber's environment (timber/twig); any other with
 * $twig->addExtension(new TwigExtension).
 */
final class TwigExtension extends AbstractExtension
{
    public static function boot(): void
    {
        add_filter('timber/twig', [self::class, 'addTo']);
    }

    public static function addTo(mixed $twig): mixed
    {
        if ($twig instanceof \Twig\Environment && ! $twig->hasExtension(self::class)) {
            $twig->addExtension(new self);
        }

        return $twig;
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        $html = ['is_safe' => ['html']];

        return [
            new TwigFunction('meiliscout_listing', fn (string $id, array $args = []) => meiliscout_get_listing($id, $args), $html),
            new TwigFunction('meiliscout_listing_part', fn (string $id, string $part, array $args = []) => meiliscout_get_listing_part($id, $part, $args), $html),
            new TwigFunction('meiliscout_facet', fn (string $id, string $facet) => meiliscout_get_listing_part($id, 'facet', ['facet' => $facet]), $html),
            new TwigFunction('meiliscout_active_filters', fn (string $id) => meiliscout_get_listing_part($id, 'active'), $html),
            new TwigFunction('meiliscout_listing_results', fn (string $id) => meiliscout_get_listing_part($id, 'results'), $html),
            new TwigFunction('meiliscout_pagination', fn (string $id) => meiliscout_get_listing_part($id, 'pagination'), $html),
        ];
    }
}
