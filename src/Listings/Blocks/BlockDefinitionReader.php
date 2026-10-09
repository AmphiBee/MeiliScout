<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Blocks;

use Pollora\MeiliScout\Listings\State\ReservedParameters;

/**
 * A listing block's definition (meiliscout_register_listing()'s arguments)
 * from its parsed block: the listing block's attributes, and its facets from
 * the facet blocks it holds, in the document's order (the URL's order).
 */
final class BlockDefinitionReader
{
    public const LISTING = 'meiliscout/listing';

    public const FACET = 'meiliscout/facet';

    /**
     * The id a listing block's definition is registered under.
     */
    public static function id(string $listingId): string
    {
        return 'block-'.$listingId;
    }

    /**
     * @param  array<string, mixed>  $block  A parsed meiliscout/listing block
     * @return array<string, mixed>
     */
    public static function read(array $block): array
    {
        $attributes = (array) ($block['attrs'] ?? []);

        $sorts = [];
        foreach ((array) ($attributes['sorts'] ?? []) as $sort) {
            $key = sanitize_key((string) ($sort['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $sorts[$key] = array_filter([
                'label' => (string) ($sort['label'] ?? $key),
                'orderby' => (string) ($sort['orderby'] ?? 'date'),
                'order' => (string) ($sort['order'] ?? 'DESC'),
                'meta_key' => isset($sort['metaKey']) && $sort['metaKey'] !== '' ? (string) $sort['metaKey'] : null,
            ], fn ($value) => $value !== null);
        }

        $facets = [];
        foreach (self::facetBlocks((array) ($block['innerBlocks'] ?? [])) as $facet) {
            $facetAttributes = (array) ($facet['attrs'] ?? []);
            $source = (string) ($facetAttributes['source'] ?? '');
            $key = self::facetKey($facetAttributes);

            if ($source === '' || $key === '' || isset($facets[$key])) {
                continue;
            }

            $facets[$key] = array_filter([
                'source' => $source,
                'type' => $facetAttributes['type'] ?? null,
                'logic' => $facetAttributes['logic'] ?? null,
                'hierarchy' => $facetAttributes['hierarchy'] ?? null,
                'label' => isset($facetAttributes['label']) && $facetAttributes['label'] !== '' ? (string) $facetAttributes['label'] : null,
                'param' => self::param($facetAttributes, $key),
                'limit' => isset($facetAttributes['limit']) ? (int) $facetAttributes['limit'] : null,
                'decimals' => isset($facetAttributes['decimals']) ? (int) $facetAttributes['decimals'] : null,
            ], fn ($value) => $value !== null);
        }

        // A block's cards come from its Post Template, rendered by PHP: no client transport
        $transport = (string) ($attributes['transport'] ?? 'fragment');

        return array_filter([
            'post_types' => array_values(array_map('strval', (array) ($attributes['postTypes'] ?? ['post']))),
            'per_page' => (int) ($attributes['perPage'] ?? 12),
            'sorts' => $sorts === [] ? null : $sorts,
            'default_sort' => isset($attributes['defaultSort']) && $attributes['defaultSort'] !== '' ? (string) $attributes['defaultSort'] : null,
            'transport' => $transport === 'page' ? 'page' : 'fragment',
            'apply' => (string) ($attributes['apply'] ?? 'instant'),
            'facets' => $facets,
        ], fn ($value) => $value !== null);
    }

    /**
     * A facet's key: the one given, else its source's name (taxonomy, meta key).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function facetKey(array $attributes): string
    {
        $key = (string) ($attributes['key'] ?? '');

        if ($key === '') {
            $key = (string) preg_replace('/^(taxonomy|meta):/', '', (string) ($attributes['source'] ?? ''));
        }

        return trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($key)), '_');
    }

    /**
     * A facet's name in the URL: the one given, else its key, else (a taxonomy
     * is often a query var WordPress reads) its title as a slug.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function param(array $attributes, string $key): ?string
    {
        if (isset($attributes['param']) && $attributes['param'] !== '') {
            return (string) $attributes['param'];
        }

        if (! ReservedParameters::isReserved($key)) {
            return null;
        }

        $label = sanitize_title((string) ($attributes['label'] ?? ''));

        return $label !== '' && ! ReservedParameters::isReserved($label) ? $label : $key.'_';
    }

    /**
     * Every listing block of a list of parsed blocks, nested ones included.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function listingBlocks(array $blocks): array
    {
        return self::find($blocks, self::LISTING, true);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private static function facetBlocks(array $blocks): array
    {
        // A listing inside a listing keeps its own facets
        return self::find($blocks, self::FACET, false);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private static function find(array $blocks, string $name, bool $intoListings): array
    {
        $found = [];

        foreach ($blocks as $block) {
            if (($block['blockName'] ?? null) === $name) {
                $found[] = $block;
            }

            if ($intoListings || ($block['blockName'] ?? null) !== self::LISTING) {
                array_push($found, ...self::find((array) ($block['innerBlocks'] ?? []), $name, $intoListings));
            }
        }

        return $found;
    }
}
