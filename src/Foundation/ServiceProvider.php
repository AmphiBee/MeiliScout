<?php

namespace Pollora\MeiliScout\Foundation;

/**
 * Base service provider class that all service providers must extend.
 */
abstract class ServiceProvider
{
    /**
     * Registers the service provider's bindings in the container.
     *
     * @return void
     */
    abstract public function register();
}
