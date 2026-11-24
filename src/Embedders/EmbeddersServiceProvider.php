<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Embedders;

use Pollora\MeiliScout\Foundation\ServiceProvider;

/**
 * Embedders declared in code. The function themes call always exists, connected or not.
 */
final class EmbeddersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        require_once __DIR__.'/functions.php';
    }
}
