<?php

declare(strict_types=1);

use Pollora\MeiliScout\Fields\FieldRegistry;

if (! function_exists('meiliscout_register_meta_field')) {
    /**
     * Gives a custom field a name: the admin shows it instead of the meta key
     * where fields are picked, indexed and weighted. Nothing about indexing
     * changes; the field stays indexed under its meta key.
     *
     * ```php
     * meiliscout_register_meta_field('budget', ['label' => 'Project › Budget', 'group' => 'Studio', 'type' => 'number', 'post_types' => ['realisation']]);
     * ```
     *
     * @param  array<string, mixed>  $args  label, group, source, type (number, date, boolean, list, text), post_types
     */
    function meiliscout_register_meta_field(string $key, array $args = []): void
    {
        FieldRegistry::declare($key, $args);
    }
}
