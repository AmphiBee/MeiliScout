<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Foundation;

use Pollora\MeiliScout\Providers\Admin\ContentSelectionServiceProvider;
use Pollora\MeiliScout\Providers\Admin\IndexationServiceProvider;
use Pollora\MeiliScout\Providers\ApiServiceProvider;
use Pollora\MeiliScout\Providers\AssetsServiceProvider;
use Pollora\MeiliScout\Providers\BlockRendererServiceProvider;
use Pollora\MeiliScout\Providers\CommandServiceProvider;
use Pollora\MeiliScout\Providers\MeiliScoutServiceProvider;
use Pollora\MeiliScout\Providers\QueryServiceProvider;
use Pollora\MeiliScout\Providers\Admin\SettingsServiceProvider;
use Pollora\MeiliScout\Providers\BlockServiceProvider;
use Pollora\MeiliScout\Providers\RestApiServiceProvider;

/**
 * Main application class responsible for bootstrapping the MeiliScout plugin.
 */
class Application
{
    /**
     * List of service providers to be registered.
     *
     * @var array<class-string>
     */
    protected array $providers = [
        BlockRendererServiceProvider::class,
        BlockServiceProvider::class,
        RestApiServiceProvider::class,
        MeiliScoutServiceProvider::class,
        ApiServiceProvider::class,
        AssetsServiceProvider::class,
        SettingsServiceProvider::class,
        ContentSelectionServiceProvider::class,
        IndexationServiceProvider::class,
        CommandServiceProvider::class,
        QueryServiceProvider::class,
    ];

    /**
     * The dependency injection container instance.
     *
     * @var Container
     */
    protected Container $container;

    /**
     * Creates a new Application instance with its container.
     */
    public function __construct()
    {
        $this->container = new Container;
    }

    /**
     * Boots the application by registering all service providers.
     *
     * @return void
     */
    public function boot()
    {
        foreach ($this->providers as $provider) {
            (new $provider($this->container))->register();
        }
    }

    /**
     * Returns the application's dependency injection container.
     *
     * @return Container
     */
    public function getContainer(): Container
    {
        return $this->container;
    }
}
