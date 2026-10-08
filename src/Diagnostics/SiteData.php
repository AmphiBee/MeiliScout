<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Diagnostics;

use Pollora\MeiliScout\Config\Settings;

/**
 * The posts, terms, authors and meta keys of the site the parity cases point at.
 *
 * Every value is picked in the site's own data, so that the cases run on any
 * site; a value the site cannot provide is null (or 0), and the cases that
 * need it are skipped.
 */
final class SiteData
{
    /**
     * @return array<string, mixed>
     */
    public static function collect(): array
    {
        global $wpdb;

        $ids = static fn (string $sql): array => array_map('intval', $wpdb->get_col($sql));
        $posts = $ids("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID");

        $childPage = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_parent > 0 ORDER BY ID LIMIT 1");
        $parentPage = $childPage > 0 ? (int) get_post_field('post_parent', $childPage) : 0;
        $otherParent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_parent FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_parent NOT IN (0, %d) LIMIT 1",
            $parentPage
        ));

        $authors = $wpdb->get_results("SELECT post_author AS id, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' GROUP BY post_author ORDER BY n ASC");
        $author = isset($authors[0]) ? (int) $authors[0]->id : 0;
        $author2 = isset($authors[1]) ? (int) $authors[1]->id : 0;
        $authorUser = $author > 0 ? get_user_by('id', $author) : false;

        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);

        $firstDate = $posts !== [] ? (string) get_post_field('post_date', $posts[0]) : '';
        $parentCategory = self::parentCategory();

        $title = $posts !== [] ? (string) get_post_field('post_title', $posts[0]) : '';
        $words = array_values(array_filter(preg_split('/[\s,.;:!?\'’"«»()]+/u', $title) ?: [], static fn (string $word) => mb_strlen($word) >= 5));

        return [
            'posts' => $posts,
            'post_name' => isset($posts[5]) ? (string) get_post_field('post_name', $posts[5]) : null,
            'post_name2' => isset($posts[9]) ? (string) get_post_field('post_name', $posts[9]) : null,
            'child_page' => $childPage,
            'parent_page' => $parentPage,
            'other_parent' => $otherParent,
            'page_path' => $childPage > 0 ? get_page_uri($childPage) : null,
            'admin' => (int) ($admins[0] ?? 0),
            'private_post' => (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'private' ORDER BY ID LIMIT 1"),
            'author' => $author,
            'author2' => $author2,
            'author_nicename' => $authorUser ? $authorUser->user_nicename : null,
            'category' => self::term('category', 0),
            'category2' => self::term('category', 1),
            'parent_category' => $parentCategory,
            'tag' => self::term('post_tag', 0),
            'tag2' => self::term('post_tag', 1),
            'custom_type' => self::customType(),
            'custom_term' => self::customTerm(),
            'numeric_meta' => self::meta('number'),
            'string_meta' => self::meta('text'),
            'date_meta' => self::meta('date'),
            'unindexed_meta' => self::unindexedMeta(),
            'year' => $firstDate !== '' ? (int) substr($firstDate, 0, 4) : 0,
            'month' => $firstDate !== '' ? (int) substr($firstDate, 5, 2) : 0,
            'day' => $firstDate !== '' ? (int) substr($firstDate, 8, 2) : 0,
            'week' => $firstDate !== '' ? (int) gmdate('W', (int) strtotime($firstDate)) : 0,
            'search_word' => $words[0] ?? null,
            'search_phrase' => count($words) >= 2 ? $words[0].' '.$words[1] : null,
        ];
    }

    /**
     * The term with the most posts after $rank others.
     *
     * @return array{id: int, slug: string, name: string, tt_id: int}|null
     */
    private static function term(string $taxonomy, int $rank): ?array
    {
        $terms = get_terms(['taxonomy' => $taxonomy, 'orderby' => 'count', 'order' => 'DESC', 'number' => $rank + 1, 'hide_empty' => true]);

        if (! is_array($terms) || ! isset($terms[$rank])) {
            return null;
        }

        $term = $terms[$rank];

        return ['id' => (int) $term->term_id, 'slug' => $term->slug, 'name' => $term->name, 'tt_id' => (int) $term->term_taxonomy_id];
    }

    /**
     * A category that has children with posts.
     */
    private static function parentCategory(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT parent FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'category' AND parent > 0 AND count > 0 ORDER BY count DESC LIMIT 1");
    }

    /**
     * The indexed post type, other than post and page, with the most published posts.
     */
    private static function customType(): ?string
    {
        $best = null;
        $most = 0;

        foreach ((array) Settings::get('indexed_post_types', []) as $type) {
            if (in_array($type, ['post', 'page', 'attachment'], true) || ! post_type_exists($type)) {
                continue;
            }

            $count = (int) (wp_count_posts($type)->publish ?? 0);

            if ($count > $most) {
                [$best, $most] = [$type, $count];
            }
        }

        return $best;
    }

    /**
     * A term of a custom taxonomy of the custom type, with its query var.
     *
     * @return array{taxonomy: string, slug: string, query_var: string}|null
     */
    private static function customTerm(): ?array
    {
        $type = self::customType();

        if ($type === null) {
            return null;
        }

        foreach (get_object_taxonomies($type, 'objects') as $taxonomy) {
            if ($taxonomy->_builtin || ! is_string($taxonomy->query_var) || $taxonomy->query_var === '') {
                continue;
            }

            $term = self::term($taxonomy->name, 0);

            if ($term !== null) {
                return ['taxonomy' => $taxonomy->name, 'slug' => $term['slug'], 'query_var' => $taxonomy->query_var];
            }
        }

        return null;
    }

    /**
     * An indexed meta key whose values are all of a kind, with the post type
     * that has it the most and a few of its values.
     *
     * @param  'number'|'text'|'date'  $kind
     * @return array<string, mixed>|null
     */
    private static function meta(string $kind): ?array
    {
        global $wpdb;

        foreach ((array) Settings::get('indexed_meta_keys', []) as $key) {
            $type = $wpdb->get_var($wpdb->prepare(
                "SELECT p.post_type FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND p.post_status = 'publish' GROUP BY p.post_type ORDER BY COUNT(*) DESC LIMIT 1",
                $key
            ));

            if (! is_string($type)) {
                continue;
            }

            $values = $wpdb->get_col($wpdb->prepare(
                "SELECT pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'publish' AND pm.meta_value <> ''",
                $key,
                $type
            ));

            if (count($values) < 4 || self::kind($values) !== $kind) {
                continue;
            }

            if ($kind === 'text') {
                $counts = array_count_values(array_map('strval', $values));
                arsort($counts);
                /** @var list<string> $common */
                $common = array_map('strval', array_slice(array_keys($counts), 0, 2));

                if (! isset($common[1]) || mb_strlen($common[0]) < 3) {
                    continue;
                }

                return ['key' => $key, 'type' => $type, 'values' => $common, 'fragment' => mb_substr($common[0], 1, 3)];
            }

            sort($values, $kind === 'number' ? SORT_NUMERIC : SORT_STRING);
            $at = static fn (float $ratio) => $values[(int) floor((count($values) - 1) * $ratio)];

            return $kind === 'number'
                ? ['key' => $key, 'type' => $type, 'median' => $at(0.5) + 0, 'low' => $at(0.25) + 0, 'high' => $at(0.75) + 0]
                : ['key' => $key, 'type' => $type, 'median' => substr((string) $at(0.5), 0, 10)];
        }

        return null;
    }

    /**
     * @param  list<string>  $values
     */
    private static function kind(array $values): string
    {
        if (array_filter($values, static fn ($value) => ! is_numeric($value)) === []) {
            return 'number';
        }

        if (array_filter($values, static fn ($value) => ! preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value)) === []) {
            return 'date';
        }

        // Serialized arrays are no plain text
        if (array_filter($values, static fn ($value) => is_serialized((string) $value)) !== []) {
            return 'list';
        }

        return 'text';
    }

    /**
     * A public meta key of published posts that is not indexed.
     *
     * @return array{key: string, type: string}|null
     */
    private static function unindexedMeta(): ?array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT pm.meta_key AS meta_key, p.post_type AS post_type FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_status = 'publish' AND pm.meta_key NOT LIKE '\_%' GROUP BY pm.meta_key, p.post_type ORDER BY COUNT(*) DESC LIMIT 50"
        );

        foreach ($rows as $row) {
            if (! QueryParity::isIndexedMetaKey((string) $row->meta_key)) {
                return ['key' => (string) $row->meta_key, 'type' => (string) $row->post_type];
            }
        }

        return null;
    }
}
