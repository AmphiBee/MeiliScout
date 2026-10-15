<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Commands;

use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;
use Pollora\MeiliScout\Suggest\Diagnostics\SuggestChecks;
use WP_CLI;

/**
 * Checks the declared search fields with suggestions.
 */
class CheckSuggestCommand
{
    /**
     * Checks each field: its definition, the content its sources read, the
     * index's format, its key and token (and a search with it, as a browser
     * would), and where browsers reach Meilisearch.
     *
     * Exits with 1 when a field has an error.
     *
     * ## OPTIONS
     *
     * [--suggest=<id>]
     * : Only this field.
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
     *     $ wp meiliscout check-suggest
     *     $ wp meiliscout check-suggest --suggest=site --format=json
     *
     * @param  array<int, string>  $args
     * @param  array<string, string>  $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $ids = isset($assocArgs['suggest']) ? [$assocArgs['suggest']] : SuggestRegistry::ids();

        if ($ids === []) {
            WP_CLI::warning('No search field with suggestions is declared (meiliscout_register_suggest(), or a Search with suggestions block).');

            return;
        }

        $documents = \Pollora\MeiliScout\Listings\Diagnostics\ListingChecks::documents() ?? false;
        $terms = SuggestChecks::termDocuments() ?? false;
        $reports = [];
        $failed = false;

        foreach ($ids as $id) {
            if (! SuggestRegistry::has($id)) {
                WP_CLI::error(sprintf('No search field "%s" is declared.', $id));
            }

            $report = SuggestChecks::report($id, $documents, $terms);
            $failed = $failed || SuggestChecks::level($report) === SuggestChecks::ERROR;
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
        WP_CLI::line(WP_CLI::colorize(sprintf('%%B%s%%n (%s, %s)', $report['id'], $report['source'], $report['transport'])));

        foreach ($report['sources'] as $source) {
            WP_CLI::line(sprintf(
                '  %-8s %s: %s — %s, %s',
                strtoupper($source['type']),
                $source['key'],
                implode(', ', $source['objects']),
                $source['documents'] === null ? 'documents unknown' : $source['documents'].' documents',
                $source['template']
            ));
        }

        foreach ($report['checks'] as $check) {
            WP_CLI::line(sprintf('  %-8s %s', strtoupper($check['level']), $check['message']));
        }
    }
}
