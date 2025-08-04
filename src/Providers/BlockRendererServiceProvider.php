<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Foundation\Webpack;

/**
 * Service provider for managing and enqueueing plugin assets.
 */
class BlockRendererServiceProvider extends ServiceProvider
{
    /**
     * The Webpack instance for asset handling.
     */
    protected Webpack $webpack;

    /**
     * Creates a new AssetsServiceProvider instance.
     */
    public function __construct()
    {
        $this->webpack = new Webpack;
    }

    /**
     * Registers the service provider's hooks and actions.
     *
     * @return void
     */
    public function register()
    {
        add_filter('render_block', [$this, 'injectTemplateAttributes'], 10, 2);
    }

    public function injectTemplateAttributes($block_content, $block): string
    {
        if ($block['blockName'] !== 'meiliscout/query-loop-search') {
            return $block_content;
        }

        if (empty($block['innerBlocks'])) {
            return $block_content;
        }

        $post_template_block = $this->findPostTemplateBlock($block['innerBlocks']);

        if (! $post_template_block) {
            return $block_content;
        }

        $template_string = serialize_block($post_template_block);
        $encoded = rawurlencode($template_string);

        // Add query ID if it exists
        $query_id = $block['attrs']['queryId'] ?? '';
        $query_id_attr = $query_id ? sprintf(' data-query-id="%s"', esc_attr($query_id)) : '';

        return str_replace(
            ' data-query=',
            $query_id_attr.' data-template='.esc_attr($encoded).' data-query=',
            $block_content
        );
    }

    public function findPostTemplateBlock($blocks)
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
