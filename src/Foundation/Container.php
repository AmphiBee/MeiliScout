<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Foundation;

use Closure;
use Meilisearch\Client;
use Pollora\MeiliScout\Config\Config;
use Pollora\MeiliScout\Providers\Admin\AdminServiceProvider;
use Pollora\MeiliScout\Query\MeiliQueryBuilder;
use Pollora\MeiliScout\Query\QueryIntegration;
use Pollora\MeiliScout\Services\ClientFactory;
use Psr\Container\ContainerInterface;


/**
 * PSR-11 compliant dependency injection container implementation.
 */
class Container implements ContainerInterface
{
    /**
     * Stores the class definitions for regular bindings.
     *
     * @var array<string, Closure>
     */
    protected array $definitions = [];

    /**
     * Stores the class definitions for singleton bindings.
     *
     * @var array<string, Closure>
     */
    protected array $singletons = [];

    /**
     * Stores the initialized singleton instances.
     *
     * @var array<string, mixed>
     */
    protected array $initializedSingletons = [];

    /**
     * Stores all service instances (both singletons and regular bindings).
     *
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * Creates a new Container instance and registers all bindings.
     */
    public function __construct()
    {
        $this->register();
    }

    /**
     * Registers a singleton binding in the container.
     *
     * @param  string  $class  The class name to register
     * @param  Closure|null  $builder  Optional builder function
     */
    public function singleton(string $class, ?Closure $builder = null): void
    {
        $this->singletons[$class] = $builder ?? fn () => new $class;
    }

    /**
     * Registers a binding in the container.
     *
     * @param  string  $class  The class name to register
     * @param  Closure|null  $builder  Optional builder function
     */
    public function bind(string $class, ?Closure $builder = null): void
    {
        $this->definitions[$class] = $builder ?? static fn () => new $class;
    }

    /**
     * Retrieves an entry from the container.
     *
     * @param  string  $id  The identifier of the entry to look for
     * @return mixed The entry
     *
     * @throws \Exception When no entry is found
     */
    public function get(string $id)
    {
        if (! isset($this->instances[$id])) {
            throw new \RuntimeException("Service not found: $id");
        }

        return $this->instances[$id];
    }

    /**
     * Checks if an entry exists in the container.
     *
     * @param  string  $id  The identifier to check
     * @return bool Whether the entry exists
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->definitions) || array_key_exists($id, $this->singletons) || array_key_exists($id, $this->instances);
    }

    /**
     * Registers core service bindings and initializes container instances.
     */
    private function register(): void
    {
        add_action('admin_notices', function () {
            // The MeiliScout page shows the connection state itself
            if (! current_user_can('manage_options') || ($_GET['page'] ?? null) === AdminServiceProvider::PAGE) {
                return;
            }

            $errors = [];

            if (! ClientFactory::isConfigured()) {
                $errors[] = __('MeiliScout is not configured yet: set the Meilisearch host and API key in the MeiliScout settings.', 'meiliscout');
            } elseif (! ClientFactory::isReachable()) {
                $errors[] = __('Unable to connect to Meilisearch. Please verify that the host and API key are correct.', 'meiliscout');
            }

            if (! empty(Config::get('meili_search_key')) && ! ClientFactory::isSearchConfigured()) {
                $errors[] = __('Unable to connect to Meilisearch. Please verify that the host and API search key are correct.', 'meiliscout');
            }

            foreach ($errors as $message) {
                printf(
                    '<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
                    esc_html($message),
                    esc_url(admin_url('admin.php?page='.AdminServiceProvider::PAGE.'#/settings')),
                    esc_html__('Open the settings', 'meiliscout')
                );
            }
        });

        // Register Meilisearch client
        $this->instances[Client::class] = ClientFactory::getClient();

        // Register MeiliQueryBuilder
        $this->instances[MeiliQueryBuilder::class] = new MeiliQueryBuilder();

        // Register QueryIntegration with its dependency
        $this->instances[QueryIntegration::class] = new QueryIntegration(
            $this->instances[MeiliQueryBuilder::class]
        );
    }
}
