<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Fields;

/**
 * Where named fields come from: a plugin that declares custom fields (ACF,
 * Meta Box…), or a fixed list of known keys (WooCommerce).
 *
 * Add one with the `meiliscout/meta_field_providers` filter, or declare fields
 * one by one with `meiliscout_register_meta_field()` or the
 * `meiliscout/meta_fields` filter.
 */
interface FieldProvider
{
    /**
     * Whether what this provider reads is there (its plugin is active).
     */
    public function available(): bool;

    /**
     * Its fields, with a flat meta key each.
     *
     * @return list<MetaField>
     */
    public function fields(): array;
}
