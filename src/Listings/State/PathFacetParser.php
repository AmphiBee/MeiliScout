<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\State;

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\Listings;

/**
 * Facets in the path (design §4.2, prototype P2): on do_parse_request, the
 * segments {prefix}-{values} after a listing's first page are taken out of
 * REQUEST_URI, WordPress resolves the rest with its own rules (the page, its
 * /page/N/), and REQUEST_URI is put back on parse_request. No rewrite rule.
 *
 * Only a path made of a listing's first page, then its facet segments and a
 * page, is touched; and not when it is the path of a post of its own (a child
 * page whose slug starts with a prefix).
 */
final class PathFacetParser
{
    private static ?string $original = null;

    /**
     * The listing whose facets the request's path holds.
     */
    private static ?string $listing = null;

    public static function boot(): void
    {
        add_filter('do_parse_request', [self::class, 'strip'], 1);
        add_action('parse_request', [self::class, 'restore'], 0);
    }

    public static function strip(bool $parse): bool
    {
        self::$listing = null;
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        [$path, $query] = array_pad(explode('?', $uri, 2), 2, null);

        foreach (self::candidates() as $definition) {
            $base = self::baseMatching($definition, $path);
            $state = $base === null ? null : UrlCodec::fromRequest($definition, $path, (string) $query, $base);

            if ($base === null || $state === null || ! self::hasSegment($definition, $path, $base) || self::isPost($path, $definition)) {
                continue;
            }

            $stripped = (string) parse_url($base, PHP_URL_PATH);
            if ($state->page > 1) {
                $stripped = trailingslashit($stripped).'page/'.$state->page.'/';
            }

            self::$original = $uri;
            self::$listing = $definition->id;
            $_SERVER['REQUEST_URI'] = $stripped.($query !== null ? '?'.$query : '');

            break;
        }

        return $parse;
    }

    public static function restore(): void
    {
        if (self::$original !== null) {
            $_SERVER['REQUEST_URI'] = self::$original;
            self::$original = null;
        }
    }

    /**
     * The listing whose facet segments this request's path holds, if any.
     */
    public static function listing(): ?string
    {
        return self::$listing;
    }

    /**
     * Forgets the request (tests).
     */
    public static function forget(): void
    {
        self::$listing = null;
        self::$original = null;
    }

    /**
     * The routed listings with facets in the path.
     *
     * @return list<ListingDefinition>
     */
    private static function candidates(): array
    {
        $candidates = [];

        foreach (DefinitionRegistry::ids() as $id) {
            try {
                $definition = DefinitionRegistry::get($id);
            } catch (InvalidListing) {
                continue;
            }

            if ($definition->route !== [] && $definition->pathFacets() !== []) {
                $candidates[] = $definition;
            }
        }

        return $candidates;
    }

    /**
     * The listing's first page, in the language whose path this one starts
     * with: the language is not known yet.
     */
    private static function baseMatching(ListingDefinition $definition, string $path): ?string
    {
        $adapter = Languages::adapter();

        foreach ($adapter->languages() ?: [''] as $language) {
            $base = $language === '' ? Listings::baseUrl($definition) : $adapter->in($language, fn () => Listings::baseUrl($definition));
            if ($base !== '' && UrlCodec::fromRequest($definition, $path, '', $base) !== null) {
                return $base;
            }
        }

        return null;
    }

    private static function hasSegment(ListingDefinition $definition, string $path, string $base): bool
    {
        $rest = substr(rtrim($path, '/'), strlen(rtrim((string) parse_url($base, PHP_URL_PATH), '/')));

        foreach (explode('/', $rest) as $segment) {
            if ($segment !== '' && UrlCodec::pathSegment($definition, rawurldecode($segment)) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * A post lives at this path: a child page, or a post of the archive's type.
     */
    private static function isPost(string $path, ListingDefinition $definition): bool
    {
        $home = (string) parse_url(home_url('/'), PHP_URL_PATH);
        $relative = trim(substr($path, strlen(rtrim($home, '/'))), '/');

        if (isset($definition->route['archive'])) {
            $slug = basename($relative);

            return get_page_by_path($slug, OBJECT, $definition->route['archive']) !== null;
        }

        return get_page_by_path($relative, OBJECT, ['page', 'post']) !== null;
    }
}
