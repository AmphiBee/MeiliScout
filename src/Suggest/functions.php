<?php

declare(strict_types=1);

use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;

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
