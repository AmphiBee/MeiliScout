<?php

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Foundation\ServiceProvider;

/**
 * Service provider for registering REST API routes and handlers.
 */
class ApiServiceProvider extends ServiceProvider
{
    /**
     * Registers the service provider's hooks and actions.
     *
     * @return void
     */
    public function register()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Registers REST API routes for the plugin.
     *
     * @return void
     */
    public function registerRoutes()
    {
        // REST routes declaration
    }
}
