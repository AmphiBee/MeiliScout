<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Fields\Providers;

use Pollora\MeiliScout\Fields\FieldProvider;
use Pollora\MeiliScout\Fields\MetaField;
use Pollora\MeiliScout\Services\MetaKeyCatalog;

/**
 * Fields of Advanced Custom Fields (and Secure Custom Fields), by name:
 * "Project › Budget", with the post types their group is shown on.
 *
 * A field's meta key is its name; a field inside a group is stored as
 * `group_field`. Repeaters and flexible content store one key per row, which
 * a single meta key cannot name: they are left out for now, as are layout
 * fields (tabs, messages) that store nothing.
 */
final class AcfProvider implements FieldProvider
{
    public const SOURCE = 'acf';

    /**
     * Field types that store no value of their own, or one a flat key cannot name.
     */
    private const SKIPPED = ['tab', 'accordion', 'message', 'clone', 'repeater', 'flexible_content', 'link'];

    /**
     * Location parameters that put a group somewhere other than on a post.
     */
    private const NOT_POSTS = ['options_page', 'taxonomy', 'user_form', 'user_role', 'current_user', 'current_user_role', 'nav_menu', 'nav_menu_item', 'widget', 'comment', 'attachment', 'block'];

    public function available(): bool
    {
        return function_exists('acf_get_field_groups') && function_exists('acf_get_fields');
    }

    /**
     * @return list<MetaField>
     */
    public function fields(): array
    {
        $fields = [];

        foreach ((array) acf_get_field_groups() as $group) {
            $postTypes = self::postTypes((array) ($group['location'] ?? []));

            // A group shown on options pages, users or terms only
            if ($postTypes === null) {
                continue;
            }

            $this->walk((array) (acf_get_fields($group) ?: []), (string) ($group['title'] ?? ''), '', [], $postTypes, $fields);
        }

        return $fields;
    }

    /**
     * @param  list<array<string, mixed>>  $fields  The fields of a group, or of a group field
     * @param  list<string>  $parents  Labels of the group fields above
     * @param  list<string>  $postTypes
     * @param  list<MetaField>  $into
     */
    private function walk(array $fields, string $title, string $prefix, array $parents, array $postTypes, array &$into): void
    {
        foreach ($fields as $field) {
            $type = (string) ($field['type'] ?? '');
            $name = (string) ($field['name'] ?? '');

            if ($name === '' || in_array($type, self::SKIPPED, true)) {
                continue;
            }

            $label = (string) ($field['label'] ?? $name);

            if ($type === 'group') {
                $this->walk((array) ($field['sub_fields'] ?? []), $title, $prefix.$name.'_', [...$parents, $label], $postTypes, $into);

                continue;
            }

            $key = $prefix.$name;

            $into[] = new MetaField(
                key: $key,
                label: implode(' › ', array_filter([$title, ...$parents, $label])),
                group: 'Advanced Custom Fields',
                source: self::SOURCE,
                type: self::typeOf($type, $field),
                postTypes: $postTypes,
                // ACF stores a reference to the field under "_<key>"
                companionKeys: ['_'.$key],
            );
        }
    }

    /**
     * The meta type an ACF field type stores.
     *
     * @param  array<string, mixed>  $field
     */
    public static function typeOf(string $type, array $field = []): ?string
    {
        return match ($type) {
            'number', 'range' => MetaKeyCatalog::TYPE_NUMBER,
            'date_picker', 'date_time_picker' => MetaKeyCatalog::TYPE_DATE,
            'true_false' => MetaKeyCatalog::TYPE_BOOLEAN,
            'checkbox', 'relationship', 'gallery', 'google_map' => MetaKeyCatalog::TYPE_LIST,
            'select', 'post_object', 'page_link', 'user', 'taxonomy' => ! empty($field['multiple']) || ($type === 'taxonomy' && ($field['field_type'] ?? '') === 'checkbox')
                ? MetaKeyCatalog::TYPE_LIST
                : MetaKeyCatalog::TYPE_TEXT,
            'text', 'textarea', 'wysiwyg', 'email', 'url', 'password', 'radio', 'button_group', 'color_picker', 'time_picker', 'oembed' => MetaKeyCatalog::TYPE_TEXT,
            default => null,
        };
    }

    /**
     * The post types a group is shown on: [] for any, null when it is on no post at all.
     *
     * Location rules are OR groups of AND rules: the group is on a post type
     * when an OR group says `post_type == x`. An OR group about something
     * else on a post (a template, a category) does not say which types, so
     * the group is then offered on all of them.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $location
     * @return list<string>|null
     */
    public static function postTypes(array $location): ?array
    {
        $types = [];
        $onPosts = false;

        foreach ($location as $rules) {
            $found = null;
            $elsewhere = false;

            foreach ((array) $rules as $rule) {
                $param = (string) ($rule['param'] ?? '');

                if ($param === 'post_type' && ($rule['operator'] ?? '==') === '==' && is_string($rule['value'] ?? null)) {
                    $found = (string) $rule['value'];
                } elseif (in_array($param, self::NOT_POSTS, true)) {
                    $elsewhere = true;
                }
            }

            if ($elsewhere) {
                continue;
            }

            $onPosts = true;

            if ($found === null) {
                return [];
            }

            $types[] = $found;
        }

        return $onPosts ? array_values(array_unique($types)) : null;
    }
}
