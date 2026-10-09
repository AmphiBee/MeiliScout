<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo;

use Pollora\MeiliScout\Listings\Definition\FacetDefinition;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\State\ListingState;

/**
 * Which views of a listing an SEO rule describes:
 *
 *     type=12|level=*
 *
 * Taxonomy facets in the definition's order, each with a term id or * (any
 * value), at most MAX_DEPTH of them; '' is the listing without filters. A view
 * is described by a key when each of its filters is one term of a taxonomy
 * facet: the most specific key wins (an id before *, the first facet first).
 */
final class RuleKey
{
    public const MAX_DEPTH = 2;

    public const ANY = '*';

    /**
     * The facets a key may name: a listing's taxonomy lists.
     *
     * @return list<FacetDefinition>
     */
    public static function facets(ListingDefinition $definition): array
    {
        return array_values(array_filter(
            $definition->facets,
            fn (FacetDefinition $facet) => $facet->isTaxonomy() && $facet->type === FacetDefinition::LIST
        ));
    }

    /**
     * A key's values by facet, as written.
     *
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException A malformed key
     */
    public static function parse(string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            return [];
        }

        $parts = [];
        foreach (explode('|', $key) as $part) {
            [$facet, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $facet = trim($facet);
            $value = trim($value);

            if ($facet === '' || $value === '') {
                /* translators: %s: a part of a rule's key */
                throw new \InvalidArgumentException(sprintf(__('"%s" is not a facet=value pair.', 'meiliscout'), $part));
            }
            if (isset($parts[$facet])) {
                /* translators: %s: a facet's key */
                throw new \InvalidArgumentException(sprintf(__('The facet "%s" is named twice.', 'meiliscout'), $facet));
            }

            $parts[$facet] = $value;
        }

        return $parts;
    }

    /**
     * A key in its canonical form: facets in the definition's order, terms by
     * id. Accepts terms by slug (a CSV written by hand).
     *
     * @param  (callable(string $taxonomy, string $value): ?int)|null  $termId  A term's id from its id or slug, null when it does not exist
     *
     * @throws \InvalidArgumentException A facet the listing has not, a term its taxonomy has not, too many facets
     */
    public static function normalize(ListingDefinition $definition, string $key, ?callable $termId = null): string
    {
        $parts = self::parse($key);
        $termId ??= self::termId(...);
        $facets = [];

        foreach (self::facets($definition) as $facet) {
            $facets[$facet->key] = $facet;
        }

        foreach (array_keys($parts) as $name) {
            if (! isset($facets[$name])) {
                throw new \InvalidArgumentException(sprintf(
                    /* translators: 1: a facet's key, 2: a listing's id, 3: facet keys */
                    __('The listing "%2$s" has no taxonomy facet "%1$s" (%3$s).', 'meiliscout'),
                    $name,
                    $definition->id,
                    implode(', ', array_keys($facets)) ?: '-'
                ));
            }
        }

        if (count($parts) > self::MAX_DEPTH) {
            /* translators: %d: a number of facets */
            throw new \InvalidArgumentException(sprintf(__('A rule describes at most %d facets.', 'meiliscout'), self::MAX_DEPTH));
        }

        $normalized = [];
        foreach ($facets as $name => $facet) {
            if (! isset($parts[$name])) {
                continue;
            }

            $value = $parts[$name];
            if ($value !== self::ANY) {
                $id = $termId($facet->name, $value);
                if ($id === null) {
                    throw new \InvalidArgumentException(sprintf(
                        /* translators: 1: a term's id or slug, 2: a taxonomy */
                        __('No term "%1$s" in the taxonomy %2$s.', 'meiliscout'),
                        $value,
                        $facet->name
                    ));
                }
                $value = (string) $id;
            }

            $normalized[] = $name.'='.$value;
        }

        return implode('|', $normalized);
    }

    /**
     * How specific a canonical key is: among the keys of one view, the
     * higher wins. Each term (rather than *) counts, the first facet most.
     */
    public static function specificity(string $key): int
    {
        $specificity = 0;
        $position = 0;

        foreach (self::parse($key) as $value) {
            if ($value !== self::ANY) {
                $specificity += 2 ** (self::MAX_DEPTH - 1 - $position);
            }
            $position++;
        }

        return $specificity;
    }

    /**
     * The keys that describe a selection, most specific first: each term
     * or *. At most 2^MAX_DEPTH of them.
     *
     * @param  array<string, int>  $selection  One term id by facet key, in the definition's order
     * @return list<string>
     */
    public static function candidates(array $selection): array
    {
        $keys = [''];

        foreach ($selection as $facet => $termId) {
            $next = [];
            foreach ($keys as $key) {
                foreach ([(string) $termId, self::ANY] as $value) {
                    $next[] = ltrim($key.'|'.$facet.'='.$value, '|');
                }
            }
            $keys = $next;
        }

        usort($keys, fn (string $a, string $b) => self::specificity($b) <=> self::specificity($a));

        return $keys;
    }

    /**
     * The terms a state selects, one by facet, when rules can describe it:
     * no search, no range, no meta, one term per facet, at most MAX_DEPTH
     * facets. Null otherwise.
     *
     * @return array<string, \WP_Term>|null By facet key, in the definition's order
     */
    public static function selection(ListingDefinition $definition, ListingState $state): ?array
    {
        if ($state->search !== '' || array_filter($state->ranges) !== []) {
            return null;
        }

        $describable = [];
        foreach (self::facets($definition) as $facet) {
            $describable[$facet->key] = $facet;
        }

        $selection = [];
        foreach ($definition->facets as $facet) {
            $values = $state->valuesOf($facet->key);
            if ($values === []) {
                continue;
            }
            if (! isset($describable[$facet->key]) || count($values) !== 1) {
                return null;
            }

            $term = get_term_by('slug', $values[0], $facet->name);
            if (! $term instanceof \WP_Term) {
                return null;
            }
            $selection[$facet->key] = $term;
        }

        return count($selection) > self::MAX_DEPTH ? null : $selection;
    }

    /**
     * A key as people read it: "Type: Refonte, Level: any".
     *
     * @param  (callable(string $taxonomy, int $id): ?string)|null  $termName
     */
    public static function label(ListingDefinition $definition, string $key, ?callable $termName = null): string
    {
        $parts = self::parse($key);
        if ($parts === []) {
            return __('Without filters', 'meiliscout');
        }

        $termName ??= static function (string $taxonomy, int $id): ?string {
            $term = get_term($id, $taxonomy);

            return $term instanceof \WP_Term ? $term->name : null;
        };

        $labels = [];
        foreach ($parts as $name => $value) {
            $facet = $definition->facet($name);
            $valueLabel = $value === self::ANY ? __('any', 'meiliscout') : ($facet !== null ? $termName($facet->name, (int) $value) : null) ?? '#'.$value;
            $labels[] = ($facet->label ?? $name).': '.$valueLabel;
        }

        return implode(', ', $labels);
    }

    /**
     * A term's id from its id or slug.
     */
    private static function termId(string $taxonomy, string $value): ?int
    {
        $term = ctype_digit($value) ? get_term((int) $value, $taxonomy) : null;

        if (! $term instanceof \WP_Term) {
            $term = get_term_by('slug', sanitize_title($value), $taxonomy);
        }

        return $term instanceof \WP_Term ? (int) $term->term_id : null;
    }
}
