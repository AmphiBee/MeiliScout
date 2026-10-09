<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\State;

use Pollora\MeiliScout\Listings\Definition\FacetDefinition;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;

/**
 * A listing's state to and from the URL: one canonical form per state.
 *
 *     /{base}/{prefix}-{a},{b}/page/N/?{facet}={a},{b}&{range}={min}..{max}&{boolean}=1&sort={key}&q={search}
 *
 * - Facets marked `path` as segments after the base, in the definition's
 *   order, values written as in the query string; the others as parameters.
 * - Facets in the order of the definition, then the sort (left out when it is
 *   the default), then the search.
 * - A list's values unique, sorted by bytes, each one encoded as rawurlencode()
 *   does and joined by a bare comma: a comma inside a value stays %2C. Terms
 *   by slug, written decoded (été rather than %c3%a9t%c3%a9).
 * - Range bounds rounded to the facet's decimals, without trailing zeros.
 * - The page in the path, as WordPress paginates (get_pagenum_link()).
 *
 * The query string is read raw: $_GET cannot tell a separating comma from an
 * encoded one, and a form without JavaScript repeats the name (type=a&type=b).
 * The client (resources/listings) writes the same form; shared cases keep both
 * equal (tests/fixtures/listings/url-cases.json).
 */
final class UrlCodec
{
    private const MAX_SEARCH = 200;

    public static function fromQueryString(ListingDefinition $definition, string $query, int $page = 1): ListingState
    {
        $raw = self::parse($query);
        $values = [];
        $ranges = [];

        foreach ($definition->facets as $facet) {
            $parts = $raw[$facet->param] ?? [];

            // A form without JavaScript sends a range's bounds apart
            if ($facet->type === FacetDefinition::RANGE && $parts === []) {
                $min = self::last($raw[$facet->param.'_min'] ?? []);
                $max = self::last($raw[$facet->param.'_max'] ?? []);
                $parts = $min === '' && $max === '' ? [] : [$min.'..'.$max];
            }

            if ($parts === []) {
                continue;
            }

            if ($facet->type === FacetDefinition::RANGE) {
                $range = self::range($facet, self::last($parts));
                if ($range !== []) {
                    $ranges[$facet->key] = $range;
                }

                continue;
            }

            if ($facet->type === FacetDefinition::BOOLEAN) {
                if (in_array('1', $parts, true)) {
                    $values[$facet->key] = ['1'];
                }

                continue;
            }

            $list = [];
            foreach ($parts as $value) {
                $value = $facet->isTaxonomy() ? sanitize_title($value) : $value;
                if ($value !== '') {
                    $list[] = $value;
                }
            }

            $list = self::sorted($list);
            if ($list !== []) {
                $values[$facet->key] = $list;
            }
        }

        $sort = self::last($raw[$definition->sortParam] ?? []);
        $sort = isset($definition->sorts[$sort]) && $sort !== $definition->defaultSort ? $sort : '';

        $search = trim(self::last($raw[$definition->searchParam] ?? []));
        $search = function_exists('mb_substr') ? mb_substr($search, 0, self::MAX_SEARCH) : substr($search, 0, self::MAX_SEARCH);

        return new ListingState($values, $ranges, $sort, max(1, $page), $search);
    }

    /**
     * The canonical query string of a state, without the leading ?.
     */
    public static function queryString(ListingDefinition $definition, ListingState $state): string
    {
        $pairs = [];

        foreach ($definition->facets as $facet) {
            if ($facet->inPath()) {
                continue;
            }

            if ($facet->type === FacetDefinition::RANGE) {
                $range = $state->rangeOf($facet->key);
                if ($range !== []) {
                    $pairs[] = $facet->param.'='.self::number($facet, $range['min'] ?? null).'..'.self::number($facet, $range['max'] ?? null);
                }

                continue;
            }

            $values = $state->valuesOf($facet->key);
            if ($values === []) {
                continue;
            }

            if ($facet->type === FacetDefinition::BOOLEAN) {
                $pairs[] = $facet->param.'=1';

                continue;
            }

            $pairs[] = $facet->param.'='.self::encodedValues($facet, $values);
        }

        if ($state->sort !== '' && $state->sort !== $definition->defaultSort) {
            $pairs[] = $definition->sortParam.'='.rawurlencode($state->sort);
        }

        if ($state->search !== '') {
            $pairs[] = $definition->searchParam.'='.rawurlencode($state->search);
        }

        return implode('&', $pairs);
    }

    /**
     * The URL of a state on a listing whose first page is $base.
     *
     * @param  string  $extra  Other parameters, kept after the listing's (a redirect keeps utm_*)
     */
    public static function url(ListingDefinition $definition, ListingState $state, string $base, string $extra = ''): string
    {
        $base = strtok($base, '?') ?: $base;

        $segments = self::pathSegments($definition, $state);
        if ($segments !== []) {
            $base = user_trailingslashit(trailingslashit($base).implode('/', $segments));
        }

        if ($state->page > 1) {
            global $wp_rewrite;

            $base = $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks()
                ? trailingslashit($base).user_trailingslashit($wp_rewrite->pagination_base.'/'.$state->page, 'paged')
                : add_query_arg('paged', $state->page, $base);
        }

        $query = implode('&', array_filter([self::queryString($definition, $state), $extra]));

        if ($query === '') {
            return $base;
        }

        return $base.(str_contains($base, '?') ? '&' : '?').$query;
    }

    /**
     * The path segments of a state's facets in the path: {prefix}-{a},{b}.
     *
     * @return list<string>
     */
    public static function pathSegments(ListingDefinition $definition, ListingState $state): array
    {
        $segments = [];

        foreach ($definition->pathFacets() as $facet) {
            $values = $state->valuesOf($facet->key);
            if ($values !== []) {
                $segments[] = $facet->prefix().'-'.self::encodedValues($facet, $values);
            }
        }

        return $segments;
    }

    /**
     * The state a request asks for, on a listing whose first page is $base:
     * the facets of the path after it, then the query string. Null when the
     * path is not the base followed by facet segments and a page.
     */
    public static function fromRequest(ListingDefinition $definition, string $path, string $query, string $base): ?ListingState
    {
        $basePath = rtrim((string) parse_url($base, PHP_URL_PATH), '/');
        $path = rtrim($path, '/');

        if ($path !== $basePath && ! str_starts_with($path, $basePath.'/')) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', substr($path, strlen($basePath))), fn ($segment) => $segment !== ''));
        $page = 1;
        $fromPath = [];

        for ($i = 0; $i < count($segments); $i++) {
            if ($segments[$i] === 'page' && isset($segments[$i + 1]) && ctype_digit($segments[$i + 1])) {
                $page = max(1, (int) $segments[++$i]);

                continue;
            }

            $matched = self::pathSegment($definition, rawurldecode($segments[$i]));
            if ($matched === null) {
                return null;
            }

            [$key, $values] = $matched;
            $fromPath[$key] = array_merge($fromPath[$key] ?? [], $values);
        }

        $state = self::fromQueryString($definition, $query, $page);

        if ($fromPath === []) {
            return $state;
        }

        $values = $state->values;
        foreach ($definition->pathFacets() as $facet) {
            if (isset($fromPath[$facet->key])) {
                $values[$facet->key] = self::sorted([...($values[$facet->key] ?? []), ...$fromPath[$facet->key]]);
            }
        }

        // In the definition's order, as fromQueryString() writes them
        $ordered = [];
        foreach ($definition->facets as $facet) {
            if (isset($values[$facet->key])) {
                $ordered[$facet->key] = $values[$facet->key];
            }
        }

        return new ListingState($ordered, $state->ranges, $state->sort, $state->page, $state->search);
    }

    /**
     * Whether a path segment (decoded) is one of the listing's facets: its key and values.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public static function pathSegment(ListingDefinition $definition, string $segment): ?array
    {
        foreach ($definition->pathFacets() as $facet) {
            // Any language's prefix: the canonical URL has the request's
            $prefix = null;
            foreach ($facet->prefixes() as $candidate) {
                if (strncasecmp($segment, $candidate.'-', strlen($candidate) + 1) === 0) {
                    $prefix = $candidate.'-';
                    break;
                }
            }
            if ($prefix === null) {
                continue;
            }

            $values = array_values(array_filter(array_map(
                static fn (string $value) => sanitize_title($value),
                explode(',', substr($segment, strlen($prefix)))
            ), static fn (string $value) => $value !== ''));

            return $values === [] ? null : [$facet->key, $values];
        }

        return null;
    }

    /**
     * The canonical URL of a request on a listing whose first page is $base,
     * its other parameters kept after the listing's; null when the request
     * already is canonical, or not one of the listing's URLs.
     */
    public static function canonicalUrl(ListingDefinition $definition, string $path, string $query, string $base): ?string
    {
        $state = self::fromRequest($definition, $path, $query, $base);

        if ($state === null) {
            return null;
        }

        [$mine, $others] = self::split($definition, $query);
        $canonical = self::url($definition, $state, $base, implode('&', $others));

        $canonicalPath = (string) parse_url($canonical, PHP_URL_PATH);
        $canonicalOwn = self::queryString($definition, $state);

        return $canonicalPath === $path && $canonicalOwn === implode('&', $mine) ? null : $canonical;
    }

    /**
     * The request's query string with the listing's parameters in canonical
     * form, the others left as they came; null when it already is.
     */
    public static function canonicalQuery(ListingDefinition $definition, string $query): ?string
    {
        [$mine, $others] = self::split($definition, $query);

        $state = self::fromQueryString($definition, $query);
        $canonical = self::queryString($definition, $state);

        if ($canonical === implode('&', $mine)) {
            return null;
        }

        return implode('&', array_filter([$canonical, implode('&', $others)]));
    }

    /**
     * A query string's pairs: the listing's, and the others, in the order they came.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function split(ListingDefinition $definition, string $query): array
    {
        $own = [$definition->sortParam, $definition->searchParam];
        foreach ($definition->facets as $facet) {
            array_push($own, $facet->param, ...($facet->type === FacetDefinition::RANGE ? [$facet->param.'_min', $facet->param.'_max'] : []));
        }
        $mine = [];
        $others = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = urldecode((string) strtok($pair, '='));
            in_array($name, $own, true) ? $mine[] = $pair : $others[] = $pair;
        }

        return [$mine, $others];
    }

    /**
     * A list's values as the URL writes them: each one encoded, joined by bare
     * commas; terms decoded first (a slug is stored encoded).
     *
     * @param  list<string>  $values
     */
    private static function encodedValues(FacetDefinition $facet, array $values): string
    {
        return implode(',', array_map(static fn (string $value) => rawurlencode($facet->isTaxonomy() ? rawurldecode($value) : $value), $values));
    }

    /**
     * Each parameter's values, split on bare commas, decoded, in the order they came.
     *
     * @return array<string, list<string>>
     */
    private static function parse(string $query): array
    {
        $raw = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);

            foreach (explode(',', $value) as $part) {
                $raw[$name][] = urldecode($part);
            }
        }

        return $raw;
    }

    /**
     * The last value a parameter was given ('' for none): a repeated sort or search keeps the last.
     *
     * @param  list<string>  $values
     */
    private static function last(array $values): string
    {
        return $values === [] ? '' : $values[array_key_last($values)];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @return array{min?: float, max?: float}
     */
    private static function range(FacetDefinition $facet, string $value): array
    {
        if (! preg_match('/^(-?\d+(?:\.\d+)?)?\.\.(-?\d+(?:\.\d+)?)?$/', $value, $m)) {
            return [];
        }

        $range = [];
        if (($m[1] ?? '') !== '') {
            $range['min'] = round((float) $m[1], $facet->decimals);
        }
        if (($m[2] ?? '') !== '') {
            $range['max'] = round((float) $m[2], $facet->decimals);
        }

        if (isset($range['min'], $range['max']) && $range['min'] > $range['max']) {
            [$range['min'], $range['max']] = [$range['max'], $range['min']];
        }

        return $range;
    }

    /**
     * A bound as JavaScript prints Number(n.toFixed(decimals)): 1500, 1500.5.
     */
    public static function number(FacetDefinition $facet, ?float $value): string
    {
        if ($value === null) {
            return '';
        }

        $formatted = number_format($value, $facet->decimals, '.', '');

        // Trailing zeros of the decimals only: 1500.50 is 1500.5, 1500 stays 1500
        if ($facet->decimals > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '-0' ? '0' : $formatted;
    }
}
