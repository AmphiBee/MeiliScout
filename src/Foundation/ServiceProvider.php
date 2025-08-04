<?php

namespace Pollora\MeiliScout\Foundation;

/**
 * Base service provider class that all service providers must extend.
 */
abstract class ServiceProvider
{
    /**
     * The dependency injection container instance.
     *
     * @var Container|null
     */
    protected ?Container $container = null;

    /**
     * Creates a new ServiceProvider instance.
     *
     * @param Container|null $container The dependency injection container
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container;
    }

    /**
     * Registers the service provider's bindings in the container.
     *
     * @return void
     */
    abstract public function register();
}
