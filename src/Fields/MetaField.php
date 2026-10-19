<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Fields;

use Pollora\MeiliScout\Services\MetaKeyCatalog;

/**
 * A custom field known by its name: the meta key it is stored under and what
 * the admin calls it.
 *
 * "_price" is stored under that key; the admin shows "WooCommerce › Price".
 * Nothing about indexing changes: a field is still indexed, filtered and
 * searched by its meta key (`metas.<key>` in the documents).
 */
final class MetaField
{
    private const TYPES = [
        MetaKeyCatalog::TYPE_NUMBER,
        MetaKeyCatalog::TYPE_DATE,
        MetaKeyCatalog::TYPE_BOOLEAN,
        MetaKeyCatalog::TYPE_LIST,
        MetaKeyCatalog::TYPE_TEXT,
    ];

    /**
     * @param  string  $key  The meta key, as stored
     * @param  string  $label  What the admin shows ("Project › Budget")
     * @param  string  $group  What declares it ("Advanced Custom Fields"): a heading in lists
     * @param  string  $source  A short id of the declarer ("acf", "metabox", "woocommerce", "custom")
     * @param  string|null  $type  A MetaKeyCatalog type when the declarer knows it; null to guess it from the values
     * @param  list<string>  $postTypes  Post types it belongs to; empty when any
     * @param  list<string>  $companionKeys  Meta keys stored with it that are not content ("_budget" for ACF), kept out of raw key lists
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $group,
        public readonly string $source,
        public readonly ?string $type = null,
        public readonly array $postTypes = [],
        public readonly array $companionKeys = [],
    ) {}

    /**
     * A field from what a plugin or a theme gave: an instance, or an array
     * (key, label, group, source, type, post_types).
     *
     * @param  self|array<string, mixed>  $field
     */
    public static function from(self|array $field, string $source = 'custom', string $group = ''): ?self
    {
        if ($field instanceof self) {
            return $field;
        }

        $key = $field['key'] ?? null;

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        $type = $field['type'] ?? null;
        $postTypes = $field['post_types'] ?? [];

        return new self(
            key: $key,
            label: is_string($field['label'] ?? null) && $field['label'] !== '' ? $field['label'] : $key,
            group: is_string($field['group'] ?? null) && $field['group'] !== '' ? $field['group'] : $group,
            source: is_string($field['source'] ?? null) && $field['source'] !== '' ? $field['source'] : $source,
            type: is_string($type) && in_array($type, self::TYPES, true) ? $type : null,
            postTypes: is_array($postTypes) ? array_values(array_filter($postTypes, 'is_string')) : [],
            companionKeys: [],
        );
    }

    /**
     * The name alone, without where it sits: "Budget" for "Project › Budget".
     */
    public function shortLabel(): string
    {
        $parts = explode(' › ', $this->label);

        return trim((string) end($parts)) !== '' ? trim((string) end($parts)) : $this->label;
    }

    /**
     * Whether it can be found on one of these post types (every field can, when none is given).
     *
     * @param  list<string>  $postTypes
     */
    public function appliesTo(array $postTypes): bool
    {
        return $postTypes === [] || $this->postTypes === [] || array_intersect($this->postTypes, $postTypes) !== [];
    }

    /**
     * @return array{key: string, label: string, group: string, source: string, type: string|null, post_types: list<string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'group' => $this->group,
            'source' => $this->source,
            'type' => $this->type,
            'post_types' => $this->postTypes,
        ];
    }
}
