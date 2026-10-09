<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Foundation\Container;
use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Integrations\QueryMonitor\QueryMonitor;
use Pollora\MeiliScout\Integrations\Polylang;
use Pollora\MeiliScout\Integrations\Wpml;
use Pollora\MeiliScout\Query\AutoIntegration;
use Pollora\MeiliScout\Query\DebugHeader;
use Pollora\MeiliScout\Query\QueryIntegration;
use Pollora\MeiliScout\Query\Terms\TermAutoIntegration;
use Pollora\MeiliScout\Query\Terms\TermQueryIntegration;

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
     * Registers the query integration service, and what helps see what it does.
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

        // get_terms(), from the taxonomies index
        new TermQueryIntegration;

        // The REST API's searches, for the indexed post types and taxonomies
        add_action('rest_api_init', static function (): void {
            foreach ((array) Settings::get('indexed_post_types', []) as $type) {
                add_filter("rest_{$type}_query", [AutoIntegration::class, 'restQuery']);
            }

            foreach ((array) Settings::get('indexed_taxonomies', []) as $taxonomy) {
                add_filter("rest_{$taxonomy}_query", [TermAutoIntegration::class, 'restQuery']);
            }
        });

        add_action('wp', [DebugHeader::class, 'send']);

        // Multilingual plugins: Polylang's query vars, WPML's SQL filters and the language of its posts
        Polylang::boot();
        Wpml::boot();

        // Query Monitor loads after the plugin: its classes exist once it asks for collectors
        add_filter('qm/collectors', [QueryMonitor::class, 'registerCollector'], 20);
        add_filter('qm/outputter/html', [QueryMonitor::class, 'registerOutput'], 120, 2);
    }
}
