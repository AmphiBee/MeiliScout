<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Commands;

use Pollora\MeiliScout\Diagnostics\QueryParity;
use Pollora\MeiliScout\Diagnostics\TermQueryParity;
use WP_CLI;

/**
 * Compares WP_Query (and get_terms()) results on MySQL and on Meilisearch.
 */
class CheckQueriesCommand
{
    /**
     * Runs each query on MySQL, then with use_meilisearch, and compares the results.
     *
     * A query Meilisearch serves must return the same posts, in the same order,
     * with the same totals; one it does not serve must give the reason. The
     * built-in cases pick their posts, terms and authors in the site's data.
     *
     * Outcomes: OK, DIFF (Meilisearch served a different result), FALLBACK (MySQL
     * served it, the reason is given), INFO (a search: overlap only), SKIP (data
     * missing), ERROR. The command exits with 1 when a case gives DIFF or ERROR.
     *
     * ## OPTIONS
     *
     * [--terms]
     * : Term queries (get_terms(), WP_Term_Query) instead of post queries.
     *
     * [--case=<filter>]
     * : Only the cases whose label contains this text.
     *
     * [--args=<json>]
     * : Runs this WP_Query (with --terms, these get_terms() arguments) instead of the built-in cases, e.g. '{"cat":3}'.
     *
     * [--mode=<mode>]
     * : With --args: order (same posts in the same order), set, count, search, sorted or fallback (must fall back).
     * ---
     * default: order
     * options:
     *   - order
     *   - set
     *   - count
     *   - search
     *   - sorted
     *   - fallback
     * ---
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
     *     $ wp meiliscout check-queries
     *     $ wp meiliscout check-queries --case=tax_query
     *     $ wp meiliscout check-queries --args='{"post_type":"page","orderby":"menu_order"}'
     *     $ wp meiliscout check-queries --terms --args='{"taxonomy":"category","child_of":3}'
     *
     * @param  array<int, string>  $args
     * @param  array<string, string>  $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        if (isset($assocArgs['args'])) {
            $queryArgs = json_decode($assocArgs['args'], true);

            if (! is_array($queryArgs)) {
                WP_CLI::error('--args must be a JSON object of WP_Query arguments.');
            }

            $mode = $assocArgs['mode'] ?? QueryParity::MODE_ORDER;
            $compared = isset($assocArgs['terms']) ? TermQueryParity::compare($queryArgs, $mode) : QueryParity::compare($queryArgs, $mode);
            $results = [['case' => $assocArgs['args'], ...$compared]];
        } else {
            $results = isset($assocArgs['terms']) ? TermQueryParity::runCases($assocArgs['case'] ?? null) : QueryParity::runCases($assocArgs['case'] ?? null);
        }

        $tally = QueryParity::tally($results);

        if (($assocArgs['format'] ?? 'table') === 'json') {
            WP_CLI::line((string) wp_json_encode(['results' => $results, 'tally' => $tally], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->print($results, $tally);
        }

        if (($tally[QueryParity::DIFF] ?? 0) > 0 || ($tally[QueryParity::ERROR] ?? 0) > 0) {
            WP_CLI::halt(1);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  array<string, int>  $tally
     */
    private function print(array $results, array $tally): void
    {
        foreach ($results as $result) {
            $counts = isset($result['mysql_found']) ? "[{$result['mysql_found']}/{$result['meili_found']}]" : '';

            WP_CLI::line(sprintf('%-9s %-56s %s', $result['outcome'], mb_strimwidth((string) $result['case'], 0, 56, '…'), $counts));

            foreach ($result['notes'] as $note) {
                WP_CLI::line("          · {$note}");
            }
        }

        WP_CLI::line('');
        WP_CLI::line(implode('  ', array_map(static fn ($outcome, $count) => "{$outcome} {$count}", array_keys($tally), $tally)));
    }
}
