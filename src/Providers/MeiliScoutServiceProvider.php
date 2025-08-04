<?php

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Config\Config;
use Pollora\MeiliScout\Foundation\Container;
use Pollora\MeiliScout\Foundation\ServiceProvider;

/**
 * Main service provider for the MeiliScout plugin.
 */
class MeiliScoutServiceProvider extends ServiceProvider
{
    /**
     * Creates a new MeiliScoutServiceProvider instance.
     *
     * @param  Container|null  $container  The dependency injection container
     */
    public function __construct(?Container $container = null) 
    {
        parent::__construct($container);
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
        if ($this->container !== null) {
            // $this->container->bind(SomeService::class, fn() => new SomeService());
        }
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
