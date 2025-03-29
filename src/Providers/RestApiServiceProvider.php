<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Http\Controllers\FacetsController;

/**
 * Service provider for managing REST API endpoints.
 */
class RestApiServiceProvider extends ServiceProvider
{
    /**
     * Registers the service provider's hooks and actions.
     */
    public function register(): void
    {
        $facetsController = new FacetsController();
        $facetsController->register();
    }
} 