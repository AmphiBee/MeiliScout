<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Http\Controllers\ArchiveFacetsController;
use Pollora\MeiliScout\Http\Controllers\FacetsController;
use Pollora\MeiliScout\Query\MeiliQueryBuilder;

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
        $facetsController = new FacetsController;
        $facetsController->register();
        
        // Register archive facets controller
        if ($this->container !== null) {
            $queryBuilder = $this->container->get(MeiliQueryBuilder::class);
            $archiveFacetsController = new ArchiveFacetsController($queryBuilder);
            $archiveFacetsController->register();
        } else {
            // Fallback: create MeiliQueryBuilder directly
            $queryBuilder = new MeiliQueryBuilder();
            $archiveFacetsController = new ArchiveFacetsController($queryBuilder);
            $archiveFacetsController->register();
        }
    }
}
