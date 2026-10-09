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
     * @param  array<string, mixed>  $args  card: a callable(WP_Post): string, or a template part name
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
