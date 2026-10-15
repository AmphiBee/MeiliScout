<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Blocks;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;
use Pollora\MeiliScout\Suggest\Suggest;

/**
 * The editor's way to a search field with suggestions:
 *
 * - the `meiliscout/suggest` block: the field, its sources and options chosen
 *   in the block's panel. Its definition is made from its attributes
 *   (`args()`), under an id of its own (`id()`: the same attributes, the same
 *   field), and saved with the post (save_post) so that the token endpoint and
 *   the public fields know it on a request that does not render the block;
 * - the core Search block, which a `meiliscoutSuggest` attribute (the id of a
 *   field declared in PHP, chosen in its panel) turns into that field.
 */
final class BlockSuggests
{
    public const BLOCK = 'meiliscout/suggest';

    /**
     * Each saved block's id, the post holding it, its definition.
     */
    private const OPTION = 'meiliscout/block_suggests';

    public static function boot(): void
    {
        add_action('init', [self::class, 'registerBlock'], 11);
        add_action('init', [self::class, 'declareSaved'], 20);
        add_action('save_post', [self::class, 'save'], 10, 2);
        add_action('delete_post', [self::class, 'forgetPost']);
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('register_block_type_args', [self::class, 'searchAttribute'], 10, 2);
        add_filter('render_block_core/search', [self::class, 'searchBlock'], 10, 2);
    }

    public static function registerBlock(): void
    {
        register_block_type(dirname(__DIR__, 3).'/blocks/suggest', ['render_callback' => [self::class, 'render']]);
    }

    /**
     * The definition a block's attributes make.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function args(array $attributes): array
    {
        $sources = [];

        $types = array_values(array_filter((array) ($attributes['postTypes'] ?? []), 'is_string'));
        if ($types !== []) {
            $sources['posts'] = ['post_types' => $types, 'limit' => (int) ($attributes['postsLimit'] ?? 5)];
        }

        $taxonomies = array_values(array_filter((array) ($attributes['taxonomies'] ?? []), 'is_string'));
        if ($taxonomies !== []) {
            $sources['terms'] = ['type' => 'terms', 'taxonomies' => $taxonomies, 'limit' => (int) ($attributes['termsLimit'] ?? 3)];
        }

        $args = [
            'sources' => $sources,
            'min_chars' => (int) ($attributes['minChars'] ?? 2),
            'recent' => (bool) ($attributes['recent'] ?? true),
            'transport' => (string) ($attributes['transport'] ?? 'direct'),
        ];

        if (($attributes['resultsUrl'] ?? '') !== '') {
            $args['results_url'] = (string) $attributes['resultsUrl'];
        }

        return $args;
    }

    /**
     * A block's id: its definition's, so that two blocks alike are one field.
     *
     * @param  array<string, mixed>  $args
     */
    public static function id(array $args): string
    {
        return 'block-'.substr(md5((string) wp_json_encode($args)), 0, 12);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function render(array $attributes): string
    {
        $args = self::args($attributes);
        $id = self::id($args);

        if (! SuggestRegistry::has($id)) {
            SuggestRegistry::declare($id, $args);
        }

        $field = Suggest::render($id, array_filter([
            'label' => (string) ($attributes['label'] ?? ''),
            'placeholder' => (string) ($attributes['placeholder'] ?? ''),
        ]));

        return $field === '' ? '' : '<div '.get_block_wrapper_attributes().'>'.$field.'</div>';
    }

    /**
     * Lets the core Search block name a suggest field.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function searchAttribute(array $args, string $name): array
    {
        if ($name === 'core/search') {
            $args['attributes']['meiliscoutSuggest'] = ['type' => 'string', 'default' => ''];
        }

        return $args;
    }

    /**
     * The core Search block as the suggest field it names, its label and
     * placeholder kept; as it is when the field is not declared.
     *
     * @param  array<string, mixed>  $block
     */
    public static function searchBlock(string $html, array $block): string
    {
        $id = (string) ($block['attrs']['meiliscoutSuggest'] ?? '');

        if ($id === '' || ! SuggestRegistry::has($id)) {
            return $html;
        }

        $attributes = (array) $block['attrs'];
        $field = Suggest::render($id, array_filter([
            'label' => isset($attributes['label']) ? (string) $attributes['label'] : '',
            'placeholder' => isset($attributes['placeholder']) ? (string) $attributes['placeholder'] : '',
            'class' => trim('wp-block-search '.(string) ($attributes['className'] ?? '')),
        ]));

        return $field === '' ? $html : $field;
    }

    /**
     * The saved blocks' fields, declared for the requests that do not render them.
     */
    public static function declareSaved(): void
    {
        foreach (self::saved() as $id => $entry) {
            if (! SuggestRegistry::has($id)) {
                SuggestRegistry::declare($id, $entry['args']);
            }
        }
    }

    /**
     * Keeps the definitions of a post's suggest blocks.
     */
    public static function save(int $postId, \WP_Post $post): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        $saved = self::withoutPost(self::saved(), $postId);

        if ($post->post_status !== 'trash' && has_block(self::BLOCK, $post)) {
            foreach (self::blocks(parse_blocks($post->post_content)) as $block) {
                $args = self::args((array) $block['attrs']);
                $saved[self::id($args)] = ['post' => $postId, 'args' => $args];
            }
        }

        update_option(self::OPTION, $saved, true);
    }

    public static function forgetPost(int $postId): void
    {
        $saved = self::saved();
        $kept = self::withoutPost($saved, $postId);

        if ($kept !== $saved) {
            update_option(self::OPTION, $kept, true);
        }
    }

    /**
     * The suggest blocks of a list of parsed blocks, nested ones too.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private static function blocks(array $blocks): array
    {
        $found = [];

        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === self::BLOCK) {
                $found[] = $block;
            }
            array_push($found, ...self::blocks((array) ($block['innerBlocks'] ?? [])));
        }

        return $found;
    }

    /**
     * @param  array<string, array{post: int, args: array<string, mixed>}>  $saved
     * @return array<string, array{post: int, args: array<string, mixed>}>
     */
    private static function withoutPost(array $saved, int $postId): array
    {
        return array_filter($saved, static fn (array $entry): bool => $entry['post'] !== $postId);
    }

    /**
     * @return array<string, array{post: int, args: array<string, mixed>}>
     */
    private static function saved(): array
    {
        $saved = get_option(self::OPTION, []);

        return is_array($saved) ? $saved : [];
    }

    /**
     * The editor's endpoint, for those who edit posts.
     */
    public static function routes(): void
    {
        register_rest_route('meiliscout/v1', '/suggest/editor', [
            'methods' => 'GET',
            'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
            'callback' => [self::class, 'editorConfig'],
        ]);
    }

    /**
     * What the editor offers: the indexed post types and taxonomies, and the
     * fields declared in PHP (for the Search block).
     */
    public static function editorConfig(): \WP_REST_Response
    {
        $label = static fn (object $object): string => html_entity_decode((string) $object->labels->name, ENT_QUOTES, 'UTF-8');

        $types = [];
        foreach ((array) Settings::get('indexed_post_types', []) as $type) {
            $object = get_post_type_object((string) $type);
            if ($object !== null) {
                $types[] = ['name' => $object->name, 'label' => $label($object)];
            }
        }

        $taxonomies = [];
        foreach ((array) Settings::get('indexed_taxonomies', []) as $name) {
            $object = get_taxonomy((string) $name);
            if ($object !== false) {
                $taxonomies[] = ['name' => $object->name, 'label' => $label($object)];
            }
        }

        // The fields a theme or plugin declared, not the blocks' own
        $declared = array_values(array_filter(SuggestRegistry::ids(), static fn (string $id): bool => ! str_starts_with($id, 'block-') && SuggestRegistry::errors()[$id] === []));

        return new \WP_REST_Response(['postTypes' => $types, 'taxonomies' => $taxonomies, 'suggests' => $declared]);
    }
}
