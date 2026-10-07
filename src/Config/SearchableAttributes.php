<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config;

/**
 * The fields of the posts index a search looks into, most important first.
 *
 * Until the admin sets them, every field is searched (`*`), the guid and the
 * block markup of `post_content` included. Meilisearch ranks a match in an
 * earlier field higher.
 */
final class SearchableAttributes
{
    /**
     * The order the admin suggests.
     */
    public const SUGGESTED = ['post_title', 'terms.name', 'post_excerpt', 'content_text'];

    /**
     * Fields of every post document that are worth searching.
     */
    private const DOCUMENT_FIELDS = ['post_title', 'terms.name', 'post_excerpt', 'content_text', 'post_content', 'post_name'];

    private const SETTING = 'searchable_attributes';

    /**
     * The fields set in the admin, or null when none were.
     *
     * @return list<string>|null
     */
    public static function configured(): ?array
    {
        $attributes = Settings::get(self::SETTING, null);

        if (! is_array($attributes) || $attributes === []) {
            return null;
        }

        return array_values(array_filter($attributes, 'is_string'));
    }

    /**
     * What the index's searchableAttributes setting gets.
     *
     * @return list<string>
     */
    public static function forIndex(): array
    {
        return self::configured() ?? ['*'];
    }

    /**
     * The fields the admin can choose from: the document's text fields, then the indexed meta keys.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $metaKeys = array_map(
            static fn (string $key) => "metas.{$key}",
            array_filter((array) Settings::get('indexed_meta_keys', []), 'is_string')
        );

        return array_values(array_unique([...self::DOCUMENT_FIELDS, ...$metaKeys]));
    }

    /**
     * Saves the fields, in order; an empty list searches every field again.
     *
     * Fields the admin cannot choose are dropped.
     *
     * @param  array<mixed>  $attributes
     */
    public static function save(array $attributes): void
    {
        $allowed = self::available();
        $attributes = array_values(array_unique(array_filter(
            $attributes,
            static fn ($attribute) => is_string($attribute) && in_array($attribute, $allowed, true)
        )));

        Settings::save(self::SETTING, $attributes);
    }
}
