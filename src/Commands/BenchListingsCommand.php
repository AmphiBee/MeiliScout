<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Commands;

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;
use Pollora\MeiliScout\Listings\Diagnostics\ListingBench;
use Pollora\MeiliScout\Listings\ListingsServiceProvider;
use WP_CLI;

/**
 * Times the front listings' requests.
 */
class BenchListingsCommand
{
    /**
     * Median time to first byte of a routed listing's page, fragment and
     * counts (the browser's multi-search, with its tenant token), for a few
     * states: the first page, a later page, one facet, two facets.
     *
     * Requests go from this host: run it where the network is the one to
     * measure. A page cache in front of the site makes the page and the
     * fragment what visitors get once it is warm.
     *
     * ## OPTIONS
     *
     * <listing>
     * : The listing's id.
     *
     * [--lang=<language>]
     * : The language (the multilingual plugin's code).
     *
     * [--runs=<n>]
     * : Requests measured per URL, after three to warm up.
     * ---
     * default: 20
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp meiliscout bench-listings projects
     *     $ wp meiliscout bench-listings projects --lang=en --runs=50 --format=csv
     *
     * @param  array<int, string>  $args
     * @param  array<string, string>  $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        if (ListingsServiceProvider::unavailable() !== null) {
            WP_CLI::error('The listings module does not run: Settings › Listings.');
        }

        try {
            $definition = DefinitionRegistry::get($args[0]);
        } catch (InvalidListing|\OutOfBoundsException $e) {
            WP_CLI::error($e->getMessage());

            return;
        }

        if ($definition->route === []) {
            WP_CLI::error('The listing has no route: its pages have no URL of their own to measure.');
        }

        $rows = ListingBench::run($definition, (string) ($assocArgs['lang'] ?? ''), max(1, (int) ($assocArgs['runs'] ?? 20)));

        WP_CLI\Utils\format_items($assocArgs['format'] ?? 'table', $rows, ['state', 'target', 'ms', 'status', 'bytes', 'gzip', 'url']);
    }
}
