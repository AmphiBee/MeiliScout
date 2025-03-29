<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Foundation\Container;
use Pollora\MeiliScout\Query\QueryIntegration;

/**
 * Service provider for registering query integration functionality.
 */
class QueryServiceProvider extends ServiceProvider
{
    /**
     * Creates a new QueryServiceProvider instance.
     *
     * @param Container $container The dependency injection container
     */
    public function __construct(protected Container $container)
    {
    }

    /**
     * Registers the query integration service.
     *
     * @return void
     */
    public function register(): void
    {
        $this->container->singleton(QueryIntegration::class);
        $this->container->get(QueryIntegration::class);
    }
}
