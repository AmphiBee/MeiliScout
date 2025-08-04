<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Foundation\ServiceProvider;

use function add_action;
use function add_filter;
use function esc_attr;
use function register_block_type;
use function serialize_block;
use function wp_json_encode;
use function wp_register_block_metadata_collection;
use function wp_register_block_types_from_metadata_collection;

/**
 * Service provider for managing Gutenberg blocks registration and rendering.
 */
class BlocksServiceProvider extends ServiceProvider
{
    /**
     * Registers the service provider's hooks and actions.
     */
    public function register(): void
    {
        add_action('init', [$this, 'registerBlocks']);
        add_filter('render_block_core/query', [$this, 'renderQueryBlock'], 10, 2);
    }

    /**
     * Registers all Gutenberg blocks.
     */
    public function registerBlocks(): void
    {
        if (function_exists('wp_register_block_types_from_metadata_collection')) {
            wp_register_block_types_from_metadata_collection(
                MEILISCOUT_DIR_PATH.'/build',
                MEILISCOUT_DIR_PATH.'/build/blocks-manifest.php'
            );
        } else {
            if (function_exists('wp_register_block_metadata_collection')) {
                wp_register_block_metadata_collection(
                    MEILISCOUT_DIR_PATH.'/build',
                    MEILISCOUT_DIR_PATH.'/build/blocks-manifest.php'
                );
            }
            $manifest_data = require MEILISCOUT_DIR_PATH.'/build/blocks-manifest.php';
            foreach (array_keys($manifest_data) as $block_type) {
                register_block_type(MEILISCOUT_DIR_PATH."/build/{$block_type}");
            }
        }
    }

    /**
     * Renders the Query block with MeiliScout integration.
     */
    public function renderQueryBlock(string $block_content, array $block): string
    {
        // Skip if not our block variation
        if (empty($block['attrs']['namespace']) || $block['attrs']['namespace'] !== 'meiliscout/query-loop-search') {
            return $block_content;
        }

        // Find post template
        if (! empty($block['innerBlocks'])) {
            $post_template_block = $this->findPostTemplateBlock($block['innerBlocks']);
            if ($post_template_block) {
                $template_string = serialize_block($post_template_block);
                $encoded = rawurlencode($template_string);
            }
        }

        // Prepare query data
        $query_data = [
            'perPage' => $block['attrs']['query']['perPage'] ?? 10,
            'pages' => $block['attrs']['query']['pages'] ?? 0,
            'offset' => $block['attrs']['query']['offset'] ?? 0,
            'postType' => $block['attrs']['query']['postType'] ?? 'post',
            'order' => $block['attrs']['query']['order'] ?? 'desc',
            'orderBy' => $block['attrs']['query']['orderBy'] ?? 'date',
            'use_meilisearch' => true,
            'facets' => $block['attrs']['query']['facets'] ?? [],
        ];

        // Add MeiliScout class
        $block_content = str_replace(
            'class="wp-block-query',
            'class="wp-block-query is-meilisearch',
            $block_content
        );

        // Add data attributes
        $data_attributes = sprintf(
            ' data-query-id="%s" data-query="%s" data-enable-url-params="%s"',
            esc_attr($block['attrs']['queryId'] ?? 'default'),
            esc_attr(wp_json_encode($query_data)),
            esc_attr($block['attrs']['enableUrlParams'] ?? 'false')
        );

        if (isset($encoded)) {
            $data_attributes .= sprintf(' data-template="%s"', esc_attr($encoded));
        }

        // Insert data attributes after div opening
        return preg_replace(
            '/(<div[^>]*class="[^"]*wp-block-query[^"]*")/i',
            '$1'.$data_attributes,
            $block_content
        );
    }

    /**
     * Recursively finds the post-template block.
     */
    private function findPostTemplateBlock(array $blocks): ?array
    {
        foreach ($blocks as $block) {
            if ($block['blockName'] === 'core/post-template') {
                return $block;
            }

            if (! empty($block['innerBlocks'])) {
                $found = $this->findPostTemplateBlock($block['innerBlocks']);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }
}
