<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Foundation;

use Closure;
use Meilisearch\Client;
use Pollora\MeiliScout\Query\MeiliQueryBuilder;
use Pollora\MeiliScout\Query\QueryIntegration;
use Pollora\MeiliScout\Services\ClientFactory;
use Psr\Container\ContainerInterface;

use function Pollora\MeiliScout\get_template_part;

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

    private array $instances = [];

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

    private function register(): void
    {
        add_action('admin_notices', function () {
            if (ClientFactory::isConfigured()) {
                return;
            }
            $message = "Impossible de se connecter à Meilisearch. Vérifiez que l'hôte et la clé API sont corrects.";
            get_template_part('components/alert', ['message' => $message, 'type' => 'error']);

        });

        // Enregistrer le client Meilisearch
        $this->instances[Client::class] = ClientFactory::getClient();

        // Enregistrer le MeiliQueryBuilder
        $this->instances[MeiliQueryBuilder::class] = new MeiliQueryBuilder(
            $this->instances[Client::class]
        );

        // Enregistrer le QueryIntegration avec sa dépendance
        $this->instances[QueryIntegration::class] = new QueryIntegration(
            $this->instances[MeiliQueryBuilder::class]
        );
    }
}
