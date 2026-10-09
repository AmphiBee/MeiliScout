<?php

declare(strict_types=1);

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\ListingsServiceProvider;

if (! function_exists('meiliscout_register_listing')) {
    /**
     * Declares a listing: the posts it shows, its facets, sorts and transport.
     * See docs/LISTINGS.md for the arguments.
     *
     * @param  array<string, mixed>  $args
     */
    function meiliscout_register_listing(string $id, array $args): void
    {
        DefinitionRegistry::declare($id, $args);
    }
}

if (! function_exists('meiliscout_get_listing')) {
    /**
     * The HTML of a listing in the state the URL asks for: facets, results,
     * pagination. Empty when the module is off.
     *
     * @param  array<string, mixed>  $args  search: false leaves the search field out
     */
    function meiliscout_get_listing(string $id, array $args = []): string
    {
        if (ListingsServiceProvider::unavailable() !== null) {
            return '';
        }

        return Listings::render($id, $args);
    }
}

if (! function_exists('meiliscout_listing')) {
    /**
     * Prints a listing (meiliscout_get_listing()).
     *
     * @param  array<string, mixed>  $args
     */
    function meiliscout_listing(string $id, array $args = []): void
    {
        echo meiliscout_get_listing($id, $args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by the renderer
    }
}

if (! function_exists('meiliscout_get_listing_part')) {
    /**
     * One part of a listing, for a template that places them itself: search,
     * facet (with ['facet' => key]), facets, sort, total, active, apply,
     * reset, results, pagination. Every part may be printed anywhere on the
     * page, in any order; their fields belong to the listing's one form.
     *
     * @param  array<string, mixed>  $args
     */
    function meiliscout_get_listing_part(string $id, string $part, array $args = []): string
    {
        if (ListingsServiceProvider::unavailable() !== null) {
            return '';
        }

        return Listings::part($id, $part, $args);
    }
}

if (! function_exists('meiliscout_listing_part')) {
    /**
     * Prints a part of a listing (meiliscout_get_listing_part()).
     *
     * @param  array<string, mixed>  $args
     */
    function meiliscout_listing_part(string $id, string $part, array $args = []): void
    {
        echo meiliscout_get_listing_part($id, $part, $args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by the renderer
    }
}

if (! function_exists('meiliscout_facet')) {
    /**
     * Prints one facet of a listing.
     */
    function meiliscout_facet(string $id, string $facet): void
    {
        meiliscout_listing_part($id, 'facet', ['facet' => $facet]);
    }
}

if (! function_exists('meiliscout_active_filters')) {
    /**
     * Prints a listing's active filters, each a button removing it.
     */
    function meiliscout_active_filters(string $id): void
    {
        meiliscout_listing_part($id, 'active');
    }
}

if (! function_exists('meiliscout_listing_results')) {
    /**
     * Prints a listing's results, the cards its definition gives (card, client_card).
     */
    function meiliscout_listing_results(string $id): void
    {
        meiliscout_listing_part($id, 'results');
    }
}

if (! function_exists('meiliscout_pagination')) {
    /**
     * Prints a listing's pagination.
     */
    function meiliscout_pagination(string $id): void
    {
        meiliscout_listing_part($id, 'pagination');
    }
}
