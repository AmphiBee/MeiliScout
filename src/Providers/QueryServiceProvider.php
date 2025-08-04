<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Foundation\Container;
use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Query\QueryIntegration;

/**
 * Service provider for registering query integration functionality.
 */
class QueryServiceProvider extends ServiceProvider
{
    /**
     * Creates a new QueryServiceProvider instance.
     *
     * @param  Container|null  $container  The dependency injection container
     */
    public function __construct(?Container $container = null) 
    {
        parent::__construct($container);
    }

    /**
     * Registers the query integration service.
     */
    public function register(): void
    {
        if ($this->container !== null) {
            $this->container->singleton(QueryIntegration::class);
            $this->container->get(QueryIntegration::class);
        } else {
            // Fallback: create QueryIntegration directly
            $queryBuilder = new \Pollora\MeiliScout\Query\MeiliQueryBuilder();
            new QueryIntegration($queryBuilder);
        }
    }
}
