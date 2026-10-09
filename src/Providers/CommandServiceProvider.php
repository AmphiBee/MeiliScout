<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Commands\BenchListingsCommand;
use Pollora\MeiliScout\Commands\CheckListingsCommand;
use Pollora\MeiliScout\Commands\CheckQueriesCommand;
use Pollora\MeiliScout\Commands\IndexCommand;
use Pollora\MeiliScout\Commands\SeoRulesCommand;
use Pollora\MeiliScout\Foundation\ServiceProvider;

/**
 * Service provider for registering WP-CLI commands.
 */
class CommandServiceProvider extends ServiceProvider
{
    /**
     * Registers WP-CLI commands when in CLI environment.
     */
    public function register(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            $indexCommand = new IndexCommand;
            \WP_CLI::add_command('meiliscout index', $indexCommand);
            \WP_CLI::add_command('meiliscout index-chunk', [$indexCommand, 'index_chunk']);
            \WP_CLI::add_command('meiliscout status', [$indexCommand, 'status']);
            \WP_CLI::add_command('meiliscout delete-legacy-indexes', [$indexCommand, 'delete_legacy_indexes']);
            \WP_CLI::add_command('meiliscout check-queries', new CheckQueriesCommand);
            \WP_CLI::add_command('meiliscout check-listings', new CheckListingsCommand);
            \WP_CLI::add_command('meiliscout bench-listings', new BenchListingsCommand);
            \WP_CLI::add_command('meiliscout seo-rules', SeoRulesCommand::class);
        }
    }
}
