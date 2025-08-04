<?php

namespace Pollora\MeiliScout;

/**
 * Assets management class for MeiliScout.
 * 
 * Handles registration and enqueuing of frontend and editor styles and scripts.
 */
class Assets
{
    /**
     * Constructor.
     * 
     * Sets up WordPress action hooks for enqueueing assets.
     */
    public function __construct()
    {
        \add_action('wp_enqueue_scripts', [$this, 'enqueueStyles']);
        \add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        \add_action('enqueue_block_editor_assets', [$this, 'enqueueEditorAssets']);
    }

    /**
     * Enqueues frontend stylesheets.
     * 
     * Registers and loads CSS assets for the frontend facets.
     * 
     * @return void
     */
    public function enqueueStyles()
    {
        \wp_enqueue_style(
            'meiliscout-facets',
            \plugin_dir_url(\MEILISCOUT_FILE).'build/frontend.css',
            [],
            \MEILISCOUT_VERSION
        );
    }

    /**
     * Enqueues frontend JavaScript.
     * 
     * Registers and loads JS assets for the frontend facets functionality.
     * 
     * @return void
     */
    public function enqueueScripts()
    {
        \wp_enqueue_script(
            'meiliscout-facets',
            \plugin_dir_url(\MEILISCOUT_FILE).'build/frontend.js',
            [],
            \MEILISCOUT_VERSION,
            true
        );
    }

    /**
     * Enqueues Gutenberg editor assets.
     * 
     * Registers and loads CSS assets specifically for the block editor.
     * 
     * @return void
     */
    public function enqueueEditorAssets()
    {
        \wp_enqueue_style(
            'meiliscout-editor-facets',
            \plugin_dir_url(\MEILISCOUT_FILE).'public/dist/css/editor-facets.css',
            [],
            \MEILISCOUT_VERSION
        );
    }
}
