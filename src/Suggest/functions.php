<?php

declare(strict_types=1);

use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;
use Pollora\MeiliScout\Suggest\Suggest;

if (! function_exists('meiliscout_register_suggest')) {
    /**
     * Declares a search field with suggestions: its groups of suggestions
     * (posts of some types, terms of some taxonomies), how many of each, and
     * where "all results" leads. See docs/AUTOSUGGEST.md for the arguments.
     *
     * @param  array<string, mixed>  $args
     */
    function meiliscout_register_suggest(string $id, array $args): void
    {
        SuggestRegistry::declare($id, $args);
    }
}

if (! function_exists('meiliscout_get_suggest')) {
    /**
     * The HTML of a search field with suggestions declared with
     * meiliscout_register_suggest().
     *
     * @param  array<string, mixed>  $args  placeholder, label, value, class
     */
    function meiliscout_get_suggest(string $id, array $args = []): string
    {
        return Suggest::render($id, $args);
    }
}

if (! function_exists('meiliscout_suggest')) {
    /**
     * Prints a search field with suggestions (meiliscout_get_suggest()).
     *
     * @param  array<string, mixed>  $args
     */
    function meiliscout_suggest(string $id, array $args = []): void
    {
        echo meiliscout_get_suggest($id, $args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by the renderer
    }
}
