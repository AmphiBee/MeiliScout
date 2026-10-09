<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Commands;

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;
use Pollora\MeiliScout\Listings\Diagnostics\CountParity;
use Pollora\MeiliScout\Listings\Diagnostics\ListingChecks;
use Pollora\MeiliScout\Listings\Diagnostics\RouteProbe;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\ListingsServiceProvider;
use WP_CLI;

/**
 * Checks the declared front listings.
 */
class CheckListingsCommand
{
    /**
     * Checks each listing: its definition and sources, its route and path
     * prefixes (as the Listings screen shows them), its counts against MySQL,
     * and its URLs over HTTP (first page, fragment, a view of each facet of the
     * path, 404 on a value that is no term, 301 on values out of order), in
     * every language.
     *
     * Exits with 1 when a listing has an error, a count differs, or a URL
     * answers with another status.
     *
     * ## OPTIONS
     *
     * [--listing=<id>]
     * : Only this listing.
     *
     * [--lang=<language>]
     * : Only this language (the multilingual plugin's code).
     *
     * [--skip-http]
     * : No HTTP requests (a site this host cannot reach).
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp meiliscout check-listings
     *     $ wp meiliscout check-listings --listing=projects --lang=en
     *     $ wp meiliscout check-listings --skip-http --format=json
     *
     * @param  array<int, string>  $args
     * @param  array<string, string>  $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $unavailable = ListingsServiceProvider::unavailable();
        if ($unavailable !== null) {
            WP_CLI::error(sprintf('The listings module does not run (%s): Settings › Listings.', $unavailable));
        }

        $ids = isset($assocArgs['listing']) ? [$assocArgs['listing']] : DefinitionRegistry::ids();
        $languages = isset($assocArgs['lang']) ? [$assocArgs['lang']] : (Languages::adapter()->languages() ?: ['']);
        $documents = ListingChecks::documents();
        $reports = [];
        $failed = false;

        foreach ($ids as $id) {
            if (! DefinitionRegistry::has($id)) {
                WP_CLI::error(sprintf('No listing "%s" is declared.', $id));
            }

            $report = ListingChecks::report($id, $documents);
            $report['parity'] = [];
            $report['http'] = [];
            $failed = $failed || ListingChecks::level($report) === ListingChecks::ERROR;

            try {
                $definition = DefinitionRegistry::get($id);
            } catch (InvalidListing) {
                $reports[] = $report;

                continue;
            }

            foreach ($languages as $language) {
                $parity = CountParity::compare($definition, $language);
                $report['parity'][] = $parity;
                $failed = $failed || $parity['diffs'] > 0;

                if (! isset($assocArgs['skip-http'])) {
                    $probes = RouteProbe::run($definition, $language);
                    $report['http'][] = ['language' => $language, 'probes' => $probes];
                    $failed = $failed || in_array(false, array_column($probes, 'ok'), true);
                }
            }

            $reports[] = $report;
        }

        if (($assocArgs['format'] ?? 'table') === 'json') {
            WP_CLI::line((string) wp_json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            array_walk($reports, [$this, 'print']);
        }

        if ($failed) {
            WP_CLI::halt(1);
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function print(array $report): void
    {
        WP_CLI::line(WP_CLI::colorize(sprintf('%%B%s%%n (%s)', $report['id'], $report['source'])));

        foreach ($report['checks'] as $check) {
            WP_CLI::line(sprintf('  %-8s %s', strtoupper($check['level']), $check['message']));
        }

        foreach ($report['parity'] as $parity) {
            $language = $parity['language'] !== '' ? "[{$parity['language']}] " : '';
            WP_CLI::line(sprintf(
                '  %-8s %scounts: total %d (MySQL %d), %d values%s',
                $parity['diffs'] === 0 ? 'OK' : 'DIFF',
                $language,
                $parity['total']['shown'],
                $parity['total']['mysql'],
                count($parity['rows']),
                $parity['served'] ? '' : " — posts served by MySQL ({$parity['reason']})"
            ));

            foreach ($parity['rows'] as $row) {
                if (! $row['ok']) {
                    WP_CLI::line(sprintf('           · %s=%s: shown %s, MySQL %s', $row['facet'], $row['value'], $row['shown'], $row['mysql']));
                }
            }
        }

        foreach ($report['http'] as $http) {
            $language = $http['language'] !== '' ? "[{$http['language']}] " : '';

            foreach ($http['probes'] as $probe) {
                WP_CLI::line(sprintf(
                    '  %-8s %s%s: %d%s  %s',
                    $probe['ok'] ? 'OK' : 'HTTP',
                    $language,
                    $probe['label'],
                    $probe['status'],
                    $probe['ok'] ? '' : " (expected {$probe['expected']})",
                    $probe['note'] !== '' ? $probe['note'] : $probe['url']
                ));
            }
        }

        WP_CLI::line('');
    }
}
