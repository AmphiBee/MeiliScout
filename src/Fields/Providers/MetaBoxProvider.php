<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Fields\Providers;

use Pollora\MeiliScout\Fields\FieldProvider;
use Pollora\MeiliScout\Fields\MetaField;
use Pollora\MeiliScout\Services\MetaKeyCatalog;

/**
 * Fields of Meta Box, by name, with the post types of their meta box.
 *
 * A Meta Box field is stored under its id. Fields kept in a custom table
 * (not in the postmeta) cannot be indexed from a meta key and are left out,
 * as are fields that store nothing (headings, buttons) and groups, whose
 * sub fields live in one serialized value.
 */
final class MetaBoxProvider implements FieldProvider
{
    public const SOURCE = 'metabox';

    private const SKIPPED = ['heading', 'divider', 'custom_html', 'button', 'group', 'fieldset_text', 'key_value', 'background', 'osm', 'map'];

    public function available(): bool
    {
        return function_exists('rwmb_get_registry');
    }

    /**
     * @return list<MetaField>
     */
    public function fields(): array
    {
        $registry = rwmb_get_registry('field');
        $fields = [];

        foreach ((array) $registry->get_by_object_type('post') as $postType => $postFields) {
            foreach ((array) $postFields as $field) {
                $field = (array) $field;
                $id = (string) ($field['id'] ?? '');

                if ($id === '' || in_array((string) ($field['type'] ?? ''), self::SKIPPED, true) || self::inCustomTable($field)) {
                    continue;
                }

                $fields[$id] ??= new MetaField(
                    key: $id,
                    label: (string) (($field['name'] ?? '') !== '' ? $field['name'] : $id),
                    group: 'Meta Box',
                    source: self::SOURCE,
                    type: self::typeOf((string) ($field['type'] ?? ''), $field),
                    postTypes: [],
                );
                $fields[$id] = $this->withPostType($fields[$id], (string) $postType);
            }
        }

        return array_values($fields);
    }

    /**
     * The same field on one more post type (a meta box can be on several).
     */
    private function withPostType(MetaField $field, string $postType): MetaField
    {
        return new MetaField(
            $field->key,
            $field->label,
            $field->group,
            $field->source,
            $field->type,
            array_values(array_unique([...$field->postTypes, $postType])),
            $field->companionKeys,
        );
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private static function inCustomTable(array $field): bool
    {
        $storage = $field['storage'] ?? null;

        return is_object($storage) && ! str_contains(strtolower($storage::class), 'post_storage');
    }

    /**
     * The meta type a Meta Box field type stores.
     *
     * @param  array<string, mixed>  $field
     */
    public static function typeOf(string $type, array $field = []): ?string
    {
        return match ($type) {
            'number', 'range', 'slider' => MetaKeyCatalog::TYPE_NUMBER,
            'date', 'datetime' => ! empty($field['timestamp']) ? MetaKeyCatalog::TYPE_NUMBER : MetaKeyCatalog::TYPE_DATE,
            'checkbox', 'switch' => MetaKeyCatalog::TYPE_BOOLEAN,
            'checkbox_list', 'file', 'file_advanced', 'file_upload', 'image', 'image_advanced', 'image_upload', 'single_image', 'video' => MetaKeyCatalog::TYPE_LIST,
            'select', 'select_advanced', 'post', 'taxonomy', 'taxonomy_advanced', 'user' => ! empty($field['multiple']) ? MetaKeyCatalog::TYPE_LIST : MetaKeyCatalog::TYPE_TEXT,
            'text', 'textarea', 'wysiwyg', 'email', 'url', 'password', 'radio', 'button_group', 'color', 'time', 'oembed' => MetaKeyCatalog::TYPE_TEXT,
            default => null,
        };
    }
}
