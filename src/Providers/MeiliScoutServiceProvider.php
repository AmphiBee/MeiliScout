<?php

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Foundation\Container;
use Pollora\MeiliScout\Config\Config;

/**
 * Main service provider for the MeiliScout plugin.
 */
class MeiliScoutServiceProvider extends ServiceProvider
{
    /**
     * Creates a new MeiliScoutServiceProvider instance.
     *
     * @param Container $container The dependency injection container
     */
    public function __construct(protected Container $container)
    {
    }

    /**
     * Registers the service provider's bindings and loads configuration.
     *
     * @return void
     */
    public function register()
    {
        // Load plugin configuration
        $this->loadConfig();

        // Register bindings
        //$this->container->bind(SomeService::class, fn() => new SomeService());
    }

    /**
     * Loads and initializes plugin configuration.
     *
     * @return void
     */
    protected function loadConfig()
    {
        // Retrieve configuration values
        $meiliHost = Config::get('meili_host');
        $meiliKey = Config::get('meili_key');

        // Define plugin configuration constants
        define('MEILISCOUT_CONFIG', [
            'host' => $meiliHost,
            'key' => $meiliKey,
        ]);
    }
}
