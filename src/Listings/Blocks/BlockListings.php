<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Blocks;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\ListingsServiceProvider;
use Pollora\MeiliScout\Listings\Query\ListingQuery;
use Pollora\MeiliScout\Listings\Render\Renderer;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Listings\State\UrlCodec;

/**
 * The editor mode (design §10), registered while the module is off too: its
 * blocks then print nothing.
 *
 * The editor mode: the meiliscout/listing block holds the core's
 * Post Template and pagination blocks, and the module's facet and part blocks.
 *
 * - Its definition comes from its attributes and its facet blocks
 *   (BlockDefinitionReader), saved with the post (save_post: posts, pages,
 *   site editor templates) so that the fragment and token endpoints, the
 *   public fields and the admin know it; rendering declares it afresh.
 * - Before its inner blocks render, the listing runs (render_block_data):
 *   Post Template's query becomes the listing's (query_loop_block_query_vars),
 *   the core pagination reads the listing's page.
 * - Post Template and each core pagination become router regions; the
 *   pagination's links are the listing's canonical URLs, loaded in place.
 * - The fragment of a block listing renders the block again, as saved.
 */
final class BlockListings
{
    /**
     * Each saved listing block: its id, the post holding it, its definition.
     */
    private const OPTION = 'meiliscout/block_listings';

    public const BLOCKS = ['listing', 'facet', 'part'];

    /**
     * The listing blocks rendering, innermost last.
     *
     * @var list<array{id: string, queryId: int, paginations: int}>
     */
    private static array $stack = [];

    public static function boot(): void
    {
        add_action('init', [self::class, 'registerBlocks']);
        add_action('init', [self::class, 'declareSaved'], 20);
        add_action('save_post', [self::class, 'save'], 10, 2);
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('delete_post', [self::class, 'forgetPost']);
        add_filter('render_block_data', [self::class, 'enter']);
        add_filter('query_loop_block_query_vars', [self::class, 'queryVars'], 10, 2);
        add_filter('render_block_core/post-template', [self::class, 'postTemplate'], 10, 3);
        add_filter('render_block_core/query-pagination', [self::class, 'pagination'], 10, 3);
        add_filter('block_categories_all', [self::class, 'category']);
    }

    /**
     * The inserter's MeiliScout category, which the listing's blocks are in.
     *
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<int, array<string, mixed>>
     */
    public static function category(array $categories): array
    {
        if (in_array('meiliscout', array_column($categories, 'slug'), true)) {
            return $categories;
        }

        // Before the theme's blocks, as Woo puts its own
        $at = array_search('theme', array_column($categories, 'slug'), true);
        $category = ['slug' => 'meiliscout', 'title' => 'MeiliScout', 'icon' => 'search'];
        array_splice($categories, $at === false ? count($categories) : (int) $at, 0, [$category]);

        return $categories;
    }

    public static function registerBlocks(): void
    {
        $root = dirname(__DIR__, 3);
        $asset = $root.'/build/listings/editor.asset.php';
        $asset = is_file($asset) ? require $asset : ['dependencies' => [], 'version' => false];

        wp_register_script('meiliscout-listings-editor', plugins_url('build/listings/editor.js', $root.'/plugin.php'), $asset['dependencies'] ?? [], $asset['version'] ?? false, true);
        wp_set_script_translations('meiliscout-listings-editor', 'meiliscout', $root.'/languages');

        foreach (self::BLOCKS as $block) {
            register_block_type($root.'/blocks/'.$block, ['render_callback' => [self::class, 'render'.ucfirst($block)]]);
        }
    }

    /**
     * The editor's endpoints, for those who edit posts.
     */
    public static function routes(): void
    {
        $canEdit = fn () => current_user_can('edit_posts');

        register_rest_route('meiliscout/v1', '/listings/editor', [
            'methods' => 'GET',
            'permission_callback' => $canEdit,
            'callback' => [self::class, 'editorConfig'],
        ]);

        register_rest_route('meiliscout/v1', '/listings/validate', [
            'methods' => 'POST',
            'permission_callback' => $canEdit,
            'callback' => [self::class, 'validate'],
        ]);
    }

    /**
     * What the editor offers: the indexed post types, their taxonomies, the indexed meta keys.
     */
    public static function editorConfig(): \WP_REST_Response
    {
        $types = [];
        foreach ((array) Settings::get('indexed_post_types', []) as $type) {
            $object = get_post_type_object((string) $type);
            if ($object !== null) {
                $types[] = ['name' => $object->name, 'label' => html_entity_decode((string) $object->labels->name, ENT_QUOTES, 'UTF-8')];
            }
        }

        $taxonomies = [];
        foreach (get_taxonomies(['public' => true], 'objects') as $taxonomy) {
            $taxonomies[] = [
                'name' => $taxonomy->name,
                'label' => html_entity_decode((string) $taxonomy->labels->name, ENT_QUOTES, 'UTF-8'),
                'hierarchical' => (bool) $taxonomy->hierarchical,
                'postTypes' => array_values((array) $taxonomy->object_type),
            ];
        }

        return new \WP_REST_Response([
            'unavailable' => ListingsServiceProvider::unavailable(),
            'postTypes' => $types,
            'taxonomies' => $taxonomies,
            'metaKeys' => array_values(array_map('strval', (array) Settings::get('indexed_meta_keys', []))),
        ]);
    }

    /**
     * The errors of a listing block as edited: its attributes and its facet blocks'.
     */
    public static function validate(\WP_REST_Request $request): \WP_REST_Response
    {
        $facets = array_map(
            fn ($attributes) => ['blockName' => BlockDefinitionReader::FACET, 'attrs' => (array) $attributes, 'innerBlocks' => []],
            array_values((array) $request->get_param('facets'))
        );
        $block = ['blockName' => BlockDefinitionReader::LISTING, 'attrs' => (array) $request->get_param('attributes'), 'innerBlocks' => $facets];

        $args = BlockDefinitionReader::read($block);

        // Saved with a page or a post, the block has it as its route (args()); not in a site template
        $postType = (string) $request->get_param('postType');
        if ($postType !== '' && ! in_array($postType, ['wp_template', 'wp_template_part', 'wp_block'], true)) {
            $args['route'] = ['post' => 1];
        }

        try {
            ListingDefinition::fromArray('editor', $args);
            $errors = [];
        } catch (InvalidListing $e) {
            $errors = $e->errors;
        }

        return new \WP_REST_Response(['errors' => $errors]);
    }

    /**
     * The listing blocks saved with their posts.
     */
    public static function declareSaved(): void
    {
        foreach (self::saved() as $id => $entry) {
            if (! DefinitionRegistry::has($id)) {
                DefinitionRegistry::declare($id, $entry['args']);
            }
        }
    }

    /**
     * Keeps the definitions of a post's listing blocks.
     */
    public static function save(int $postId, \WP_Post $post): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        $saved = array_filter(self::saved(), fn (array $entry) => $entry['post'] !== $postId);

        if ($post->post_status !== 'trash' && has_block(BlockDefinitionReader::LISTING, $post)) {
            foreach (BlockDefinitionReader::listingBlocks(parse_blocks($post->post_content)) as $block) {
                $listingId = (string) ($block['attrs']['listingId'] ?? '');
                if ($listingId === '') {
                    continue;
                }

                $saved[BlockDefinitionReader::id($listingId)] = [
                    'post' => $postId,
                    'args' => self::args($block, $post),
                ];
            }
        }

        update_option(self::OPTION, $saved, true);
    }

    public static function forgetPost(int $postId): void
    {
        $saved = self::saved();
        $kept = array_filter($saved, fn (array $entry) => $entry['post'] !== $postId);

        if (count($kept) !== count($saved)) {
            update_option(self::OPTION, $kept, true);
        }
    }

    /**
     * @return array<string, array{post: int, args: array<string, mixed>}>
     */
    public static function saved(): array
    {
        $saved = get_option(self::OPTION, []);

        return is_array($saved) ? $saved : [];
    }

    public static function isBlock(string $id): bool
    {
        return isset(self::saved()[$id]);
    }

    /**
     * Before a listing block's inner blocks render: its definition declared,
     * the listing run, the core pagination's page set.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public static function enter(array $block): array
    {
        if (($block['blockName'] ?? null) !== BlockDefinitionReader::LISTING || ($block['attrs']['listingId'] ?? '') === '' || ListingsServiceProvider::unavailable() !== null) {
            return $block;
        }

        $id = BlockDefinitionReader::id((string) $block['attrs']['listingId']);
        $saved = self::saved()[$id] ?? null;
        $post = $saved !== null ? get_post($saved['post']) : null;
        DefinitionRegistry::declare($id, self::args($block, $post instanceof \WP_Post ? $post : null));

        $queryId = (int) ($block['attrs']['queryId'] ?? 0);
        self::$stack[] = ['id' => $id, 'queryId' => $queryId, 'paginations' => 0];

        $result = Listings::result($id);
        if ($result !== null) {
            // The core pagination blocks read their page there
            $_GET['query-'.$queryId.'-page'] = $result[0]->state->page;
        }

        return $block;
    }

    /**
     * Post Template's query, inside a listing block: the listing's.
     *
     * @param  array<string, mixed>  $vars
     * @return array<string, mixed>
     */
    public static function queryVars(array $vars, \WP_Block $block): array
    {
        $current = self::current($block);

        if ($current === null || ($result = Listings::result($current['id'])) === null) {
            return $vars;
        }

        return ListingQuery::wpQueryArgs($result[0]->definition, $result[0]->state);
    }

    /**
     * Post Template, inside a listing block: the router region of its results.
     */
    public static function postTemplate(string $html, array $block, \WP_Block $instance): string
    {
        $current = self::current($instance);

        if ($current === null) {
            return $html;
        }

        if (trim($html) === '') {
            $html = sprintf('<p class="meiliscout-results__empty">%s</p>', esc_html__('No results match these filters.', 'meiliscout'));
        }

        return self::region($current['id'], 'results', $html);
    }

    /**
     * The core pagination, inside a listing block: its links are the
     * listing's canonical URLs, loaded in place; a router region of its own.
     */
    public static function pagination(string $html, array $block, \WP_Block $instance): string
    {
        $index = array_key_last(self::$stack);

        if ($index === null || self::current($instance) === null || ($result = Listings::result(self::$stack[$index]['id'])) === null) {
            return $html;
        }

        [$listing, $base] = $result;
        $key = 'query-'.self::$stack[$index]['queryId'].'-page';
        $tags = new \WP_HTML_Tag_Processor($html);

        while ($tags->next_tag(['tag_name' => 'a'])) {
            $href = (string) $tags->get_attribute('href');
            parse_str((string) wp_parse_url(html_entity_decode($href), PHP_URL_QUERY), $query);
            $page = max(1, (int) ($query[$key] ?? 1));

            $tags->set_attribute('href', UrlCodec::url($listing->definition, $listing->state->onPage($page), $base));
            $tags->set_attribute('data-wp-on--click', 'meiliscout/listing::actions.navigate');
            foreach (['data-wp-on--mouseenter', 'data-wp-watch'] as $directive) {
                $tags->remove_attribute($directive);
            }
        }

        $n = ++self::$stack[$index]['paginations'];

        return self::region(self::$stack[$index]['id'], 'pagination-'.$n, $tags->get_updated_html());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function renderListing(array $attributes, string $content, \WP_Block $block): string
    {
        $current = array_pop(self::$stack);

        // The module off: nothing, rather than the posts its Post Template would list without it
        if ($current === null) {
            return '';
        }

        $id = $current['id'];

        try {
            $definition = DefinitionRegistry::get($id);
        } catch (InvalidListing|\OutOfBoundsException $e) {
            return current_user_can('manage_options') ? sprintf('<div class="meiliscout-listing-error" role="alert">%s</div>', esc_html($e->getMessage())) : '';
        }

        return sprintf(
            '<div %1$s id="%2$s" data-meiliscout="listing" data-wp-interactive="meiliscout/listing" data-wp-context="%3$s">%4$s%5$s</div>',
            get_block_wrapper_attributes(['class' => 'meiliscout-listing']),
            esc_attr(Renderer::domId($definition)),
            esc_attr((string) wp_json_encode(['listing' => $id])),
            // The form every field names, when no facet block printed it
            Listings::form($id),
            $content
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function renderFacet(array $attributes, string $content, \WP_Block $block): string
    {
        $id = self::listingOf($block);

        if ($id === null) {
            return '';
        }

        return self::wrap(Listings::part($id, 'facet', ['facet' => BlockDefinitionReader::facetKey($attributes)]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function renderPart(array $attributes, string $content, \WP_Block $block): string
    {
        $id = self::listingOf($block);
        $part = (string) ($attributes['part'] ?? '');

        if ($id === null || ! in_array($part, ['search', 'sort', 'total', 'active', 'apply', 'reset', 'pagination', 'intro', 'faq'], true)) {
            return '';
        }

        return self::wrap(Listings::part($id, $part));
    }

    /**
     * The fragment of a block listing at a URL: the block rendered again, as
     * saved, for that state. The router takes its regions.
     */
    public static function fragment(string $id, ListingState $state, string $base): ?string
    {
        $saved = self::saved()[$id] ?? null;
        $post = $saved !== null ? get_post($saved['post']) : null;

        if (! $post instanceof \WP_Post) {
            return null;
        }

        foreach (BlockDefinitionReader::listingBlocks(parse_blocks($post->post_content)) as $block) {
            if (BlockDefinitionReader::id((string) ($block['attrs']['listingId'] ?? '')) === $id) {
                Listings::setRequest($id, $state, $base);

                return render_block($block);
            }
        }

        return null;
    }

    /**
     * meiliscout_register_listing()'s arguments for a listing block, with the
     * route of the post holding it (none in a site editor template).
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function args(array $block, ?\WP_Post $post): array
    {
        $args = BlockDefinitionReader::read($block);

        if ($post !== null && ! in_array($post->post_type, ['wp_template', 'wp_template_part', 'wp_block'], true)) {
            $args['route'] = $post->post_type === 'page' ? ['page' => $post->ID] : ['post' => $post->ID];
        }

        return $args;
    }

    /**
     * The listing a block's query belongs to.
     *
     * @return array{id: string, queryId: int, paginations: int}|null
     */
    private static function current(\WP_Block $block): ?array
    {
        $current = end(self::$stack);

        return $current !== false && (int) ($block->context['queryId'] ?? -1) === $current['queryId'] ? $current : null;
    }

    private static function listingOf(\WP_Block $block): ?string
    {
        $listingId = (string) ($block->context['meiliscout/listing'] ?? '');

        return $listingId === '' || ListingsServiceProvider::unavailable() !== null ? null : BlockDefinitionReader::id($listingId);
    }

    private static function region(string $id, string $name, string $html): string
    {
        try {
            $dom = Renderer::domId(DefinitionRegistry::get($id));
        } catch (InvalidListing|\OutOfBoundsException) {
            return $html;
        }

        $region = $name === 'results' ? $dom : $dom.'-'.$name;

        // The router hydrates a region as a root: it needs its namespace and context
        return sprintf(
            '<div id="%1$s" class="meiliscout-listing__%2$s" data-meiliscout="%2$s" data-wp-interactive="meiliscout/listing" data-wp-context="%5$s" data-wp-router-region="%3$s"%6$s>%4$s</div>',
            esc_attr($region.($name === 'results' ? '-results' : '')),
            esc_attr($name === 'results' ? 'results' : 'pagination'),
            esc_attr($region),
            $html,
            esc_attr((string) wp_json_encode(['listing' => $id])),
            $name === 'results' ? ' data-wp-bind--aria-busy="state.busy"' : ''
        );
    }

    /**
     * A part in its block's wrapper, which carries the block supports (colors, spacing...).
     */
    private static function wrap(string $html): string
    {
        return $html === '' ? '' : '<div '.get_block_wrapper_attributes().'>'.$html.'</div>';
    }
}
