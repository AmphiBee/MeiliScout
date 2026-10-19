<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Fields;

use Pollora\MeiliScout\Fields\Providers\AcfProvider;
use Pollora\MeiliScout\Fields\Providers\MetaBoxProvider;
use Pollora\MeiliScout\Fields\Providers\WooCommerceProvider;

/**
 * Every custom field the site knows by name, from its providers, from the
 * fields a theme or a plugin declared, and from the `meiliscout/meta_fields`
 * filter. Read from the admin to show names and to pick fields; never walked
 * by a visitor's request.
 *
 * The registry is built once per request. A provider that throws is skipped
 * (and logged): a broken plugin must not take the admin down.
 */
final class FieldRegistry
{
    /**
     * @var array<string, MetaField>|null
     */
    private static ?array $all = null;

    /**
     * @var array<string, MetaField>
     */
    private static array $declared = [];

    /**
     * Declares a field (meiliscout_register_meta_field()). It wins over a provider's.
     *
     * @param  array<string, mixed>  $args  label, group, source, type, post_types
     */
    public static function declare(string $key, array $args = []): void
    {
        $field = MetaField::from([...$args, 'key' => $key]);

        if ($field !== null) {
            self::$declared[$field->key] = $field;
            self::$all = null;
        }
    }

    /**
     * Every field, by meta key.
     *
     * @return array<string, MetaField>
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $fields = [];

        foreach (self::providers() as $provider) {
            try {
                if (! $provider->available()) {
                    continue;
                }

                foreach ($provider->fields() as $field) {
                    $fields[$field->key] ??= $field;
                }
            } catch (\Throwable $e) {
                error_log('MeiliScout: the fields of '.$provider::class.' could not be read: '.$e->getMessage());
            }
        }

        $fields = [...$fields, ...self::$declared];

        /** @var array<mixed> $named */
        $named = array_values($fields);

        /**
         * Filters the named custom fields: a list of MetaField instances or of
         * arrays (key, label, group, source, type, post_types).
         */
        $filtered = apply_filters('meiliscout/meta_fields', $named);

        self::$all = [];

        foreach ((array) $filtered as $field) {
            $field = is_array($field) || $field instanceof MetaField ? MetaField::from($field) : null;

            if ($field !== null) {
                self::$all[$field->key] = $field;
            }
        }

        return self::$all;
    }

    /**
     * @return list<FieldProvider>
     */
    public static function providers(): array
    {
        /** @var array<mixed> $defaults */
        $defaults = [new AcfProvider, new MetaBoxProvider, new WooCommerceProvider];

        /**
         * Filters the providers of named fields (FieldProvider instances).
         */
        $providers = apply_filters('meiliscout/meta_field_providers', $defaults);

        return array_values(array_filter((array) $providers, static fn ($provider) => $provider instanceof FieldProvider));
    }

    public static function get(string $key): ?MetaField
    {
        return self::all()[$key] ?? null;
    }

    /**
     * What the admin calls a meta key: its name, or the key itself.
     */
    public static function label(string $key): string
    {
        return self::get($key)->label ?? $key;
    }

    /**
     * Fields matching a search on their name, their key or their group.
     *
     * @param  list<string>  $postTypes  Only the fields found on one of these types; any when empty
     * @param  list<string>  $exclude  Meta keys to leave out
     * @param  string  $source  Only the fields of this source ("acf"); any when empty
     * @return list<MetaField>
     */
    public static function search(string $term = '', array $postTypes = [], array $exclude = [], string $source = ''): array
    {
        $term = mb_strtolower(trim($term));
        $found = [];

        foreach (self::all() as $field) {
            if (in_array($field->key, $exclude, true)
                || ! $field->appliesTo($postTypes)
                || ($source !== '' && $field->source !== $source)) {
                continue;
            }

            if ($term === '' || str_contains(mb_strtolower($field->key.' '.$field->label.' '.$field->group), $term)) {
                $found[] = $field;
            }
        }

        return $found;
    }

    /**
     * The sources that have fields on these post types, and how many.
     *
     * @param  list<string>  $postTypes
     * @return list<array{source: string, group: string, count: int}>
     */
    public static function sources(array $postTypes = []): array
    {
        $sources = [];

        foreach (self::all() as $field) {
            if (! $field->appliesTo($postTypes)) {
                continue;
            }

            $sources[$field->source] ??= ['source' => $field->source, 'group' => $field->group, 'count' => 0];
            $sources[$field->source]['count']++;
        }

        return array_values($sources);
    }

    /**
     * Meta keys stored next to a field that are not content (ACF's "_budget" references): kept out of raw key lists.
     *
     * @return array<string, true>
     */
    public static function companionKeys(): array
    {
        $keys = [];

        foreach (self::all() as $field) {
            foreach ($field->companionKeys as $key) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * Forgets what was read (a test, or fields that changed in the request).
     */
    public static function flush(bool $declared = false): void
    {
        self::$all = null;

        if ($declared) {
            self::$declared = [];
        }
    }
}
