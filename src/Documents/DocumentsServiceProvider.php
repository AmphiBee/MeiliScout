<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Documents;

use Pollora\MeiliScout\Foundation\ServiceProvider;

/**
 * Writes to other plugins' indexes. The functions they call always exist, connected or not.
 */
final class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        require_once __DIR__.'/functions.php';
    }
}
