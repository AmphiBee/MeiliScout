<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Foundation\ServiceProvider;

use function add_action;
use function add_filter;
use function esc_attr;
use function serialize_block;
use function wp_json_encode;
use function wp_register_block_types_from_metadata_collection;

/**
 * Service provider for managing and enqueueing plugin assets.
 */
class BlockServiceProvider extends ServiceProvider
{
    /**
     * Registers the service provider's hooks and actions.
     *
     * @return void
     */
    public function register()
    {
        add_action('init', [$this, 'registerBlocks']);
        add_filter('render_block', [$this, 'generateInnerBlockTemplate'], 10, 3);
    }

    public function generateInnerBlockTemplate($block_content, $block, $parsed_block): string
    {
        // Vérifier si c'est notre bloc wrapper
        if ($block['blockName'] !== 'meiliscout/query-loop-search') {
            return $block_content;
        }

        // Trouver le bloc core/query pour récupérer la query
        $query_block = self::findQueryBlock($block['innerBlocks']);
        if (!$query_block) {
            return $block_content;
        }

        // Récupérer la query du bloc core/query
        $query_data = $query_block['attrs']['query'] ?? [];
        $query_data['use_meilisearch'] = true;

        // Trouver le bloc post-template
        $post_template_block = self::findPostTemplateBlock($query_block['innerBlocks']);
        if ($post_template_block) {
            $template_string = serialize_block($post_template_block);
            $encoded = rawurlencode($template_string);
        }

        // Ajouter la classe is-meilisearch
        $block_content = str_replace(
            'class="wp-block-query',
            'class="wp-block-query-meilisearch',
            $block_content
        );

        // Ajouter les attributs data
        $data_attributes = sprintf(
            ' data-query-id="%s" data-query="%s" data-enable-url-params="%s"',
            esc_attr($block['attrs']['queryId'] ?? 'default'),
            esc_attr(wp_json_encode($query_data)),
            esc_attr($block['attrs']['enableUrlParams'] ?? 'false')
        );

        // Si on a un template, l'ajouter aussi
        if (isset($encoded)) {
            $data_attributes .= sprintf(' data-template="%s"', esc_attr($encoded));
        }

        // Insérer les attributs data juste après l'ouverture de la div
        return preg_replace(
            '/(<div[^>]*class="[^"]*wp-block-query[^"]*")/i',
            '$1'.$data_attributes,
            $block_content
        );
    }

    public static function findQueryBlock($blocks)
    {
        foreach ($blocks as $block) {
            if ($block['blockName'] === 'core/query') {
                return $block;
            }

            if (! empty($block['innerBlocks'])) {
                $found = self::findQueryBlock($block['innerBlocks']);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    public static function findPostTemplateBlock($blocks)
    {
        foreach ($blocks as $block) {
            if ($block['blockName'] === 'core/post-template') {
                return $block;
            }

            if (! empty($block['innerBlocks'])) {
                $found = self::findPostTemplateBlock($block['innerBlocks']);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    public function registerBlocks()
    {

        if (function_exists('wp_register_block_types_from_metadata_collection')) { // Function introduced in WordPress 6.8.
            wp_register_block_types_from_metadata_collection(MEILISCOUT_DIR_PATH.'/build', MEILISCOUT_DIR_PATH.'/build/blocks-manifest.php');
        } else {
            if (function_exists('wp_register_block_metadata_collection')) { // Function introduced in WordPress 6.7.
                wp_register_block_metadata_collection(MEILISCOUT_DIR_PATH.'/build', MEILISCOUT_DIR_PATH.'/build/blocks-manifest.php');
            }
            $manifest_data = require MEILISCOUT_DIR_PATH.'/build/blocks-manifest.php';
            foreach (array_keys($manifest_data) as $block_type) {
                register_block_type(MEILISCOUT_DIR_PATH."/build/{$block_type}");
            }
        }
    }
}
