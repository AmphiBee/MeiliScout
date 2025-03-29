<?php

namespace AmphibeeV3\MeiliScout;

class Assets
{
    public function __construct()
    {
        \add_action('wp_enqueue_scripts', [$this, 'enqueueStyles']);
        \add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        \add_action('enqueue_block_editor_assets', [$this, 'enqueueEditorAssets']);
    }

    public function enqueueStyles()
    {
        \wp_enqueue_style(
            'meiliscout-facets',
            \plugin_dir_url(\MEILISCOUT_FILE) . 'public/dist/css/front-facets.css',
            [],
            \MEILISCOUT_VERSION
        );
    }

    public function enqueueScripts()
    {
        \wp_enqueue_script(
            'meiliscout-facets',
            \plugin_dir_url(\MEILISCOUT_FILE) . 'public/dist/js/front/facets/index.js',
            [],
            \MEILISCOUT_VERSION,
            true
        );
    }

    public function enqueueEditorAssets()
    {
        \wp_enqueue_style(
            'meiliscout-editor-facets',
            \plugin_dir_url(\MEILISCOUT_FILE) . 'public/dist/css/editor-facets.css',
            [],
            \MEILISCOUT_VERSION
        );
    }
} 