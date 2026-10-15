<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest;

use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Suggest\Blocks\BlockSuggests;
use Pollora\MeiliScout\Suggest\Transport\RestController;

/**
 * Search fields with suggestions, served by Meilisearch straight to the
 * browser. Opt-in by declaration: nothing runs on a site that declares no
 * field (meiliscout_register_suggest()), and it never depends on the front
 * listings module.
 *
 * The functions themes call always exist: a theme does not break when the
 * plugin is not connected yet.
 */
final class SuggestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        require_once __DIR__.'/functions.php';

        add_action('rest_api_init', [RestController::class, 'routes']);
        Suggest::boot();
        BlockSuggests::boot();
    }
}
