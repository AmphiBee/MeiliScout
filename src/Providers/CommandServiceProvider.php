<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Commands\IndexCommand;
use Pollora\MeiliScout\Foundation\ServiceProvider;

/**
 * Service provider for registering WP-CLI commands.
 */
class CommandServiceProvider extends ServiceProvider
{
    /**
     * Registers WP-CLI commands when in CLI environment.
     *
     * @return void
     */
    public function register(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('meiliscout index', new IndexCommand());
        }
    }
} 