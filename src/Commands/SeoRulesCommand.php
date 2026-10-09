<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Commands;

use Pollora\MeiliScout\Listings\Seo\RulesCsv;
use Pollora\MeiliScout\Listings\Seo\SeoRules;
use Pollora\MeiliScout\Listings\Seo\SeoRulesService;
use WP_CLI;

/**
 * Manages the SEO rules of the front listings: their titles, descriptions,
 * headings, introductions and questions, by listing, language and facets.
 */
class SeoRulesCommand
{
    /**
     * Lists the rules.
     *
     * ## OPTIONS
     *
     * [--listing=<id>]
     * : Only this listing's.
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
     * @param  array<int, string>  $args
     * @param  array<string, string>  $assocArgs
     */
    public function list(array $args, array $assocArgs): void
    {
        SeoRules::install();
        $rows = [];

        foreach (SeoRules::all($assocArgs['listing'] ?? null) as $rule) {
            $rows[] = ['id' => $rule->id, 'listing' => $rule->listing, 'locale' => $rule->locale ?: '*', 'key' => $rule->key ?: '-', 'title' => $rule->title, 'description' => $rule->description];
        }

        WP_CLI\Utils\format_items($assocArgs['format'] ?? 'table', $rows, ['id', 'listing', 'locale', 'key', 'title', 'description']);
    }

    /**
     * Writes the rules as CSV, to a file or the standard output.
     *
     * ## OPTIONS
     *
     * [<file>]
     * : The file to write. Without it, the standard output.
     *
     * [--listing=<id>]
     * : Only this listing's.
     *
     * ## EXAMPLES
     *
     *     $ wp meiliscout seo-rules export rules.csv
     *
     * @param  array<int, string>  $args
     * @param  array<string, string>  $assocArgs
     */
    public function export(array $args, array $assocArgs): void
    {
        SeoRules::install();
        $csv = RulesCsv::export(SeoRules::all($assocArgs['listing'] ?? null));

        if (! isset($args[0])) {
            WP_CLI::line(rtrim($csv, "\n"));

            return;
        }

        if (file_put_contents($args[0], $csv) === false) {
            WP_CLI::error(sprintf('Cannot write %s.', $args[0]));
        }

        WP_CLI::success(sprintf('Rules written to %s.', $args[0]));
    }

    /**
     * Saves the rules of a CSV: columns listing, locale, key, title,
     * description, h1, intro, faq (JSON). A rule of the same listing, language
     * and key is replaced. Terms by id or slug.
     *
     * ## OPTIONS
     *
     * <file>
     * : The CSV file, - for the standard input.
     *
     * [--dry-run]
     * : Checks every row, saves nothing.
     *
     * ## EXAMPLES
     *
     *     $ wp meiliscout seo-rules import rules.csv --dry-run
     *
     * @param  array<int, string>  $args
     * @param  array<string, string>  $assocArgs
     */
    public function import(array $args, array $assocArgs): void
    {
        $csv = $args[0] === '-' ? stream_get_contents(STDIN) : @file_get_contents($args[0]);

        if (! is_string($csv)) {
            WP_CLI::error(sprintf('Cannot read %s.', $args[0]));
        }

        $dryRun = (bool) WP_CLI\Utils\get_flag_value($assocArgs, 'dry-run', false);
        $report = RulesCsv::import($csv, $dryRun);

        foreach ($report['errors'] as $error) {
            WP_CLI::warning(sprintf('Line %d: %s', $error['line'], $error['message']));
        }

        $message = sprintf('%d created, %d replaced, %d with errors%s.', $report['created'], $report['updated'], count($report['errors']), $dryRun ? ' (dry run, nothing saved)' : '');

        if ($report['errors'] !== []) {
            WP_CLI::error($message);
        }

        WP_CLI::success($message);
    }

    /**
     * Deletes rules.
     *
     * ## OPTIONS
     *
     * <id>...
     * : The rules' ids.
     *
     * @param  array<int, string>  $args
     */
    public function delete(array $args): void
    {
        foreach ($args as $id) {
            SeoRules::delete((int) $id)
                ? WP_CLI::log(sprintf('Rule %d deleted.', (int) $id))
                : WP_CLI::warning(sprintf('No rule %d.', (int) $id));
        }
    }

    /**
     * Shows what a listing's URL tells search engines: indexable or not, robots,
     * canonical, adjacent pages, the rule applied.
     *
     * ## OPTIONS
     *
     * <url>
     * : A URL of a listing's page, or its path: /projects/page/2/?type=refonte
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
     * @param  array<int, string>  $args
     * @param  array<string, string>  $assocArgs
     */
    public function preview(array $args, array $assocArgs): void
    {
        try {
            $view = SeoRulesService::preview($args[0]);
        } catch (\InvalidArgumentException $e) {
            WP_CLI::error($e->getMessage());

            return;
        }

        if (($assocArgs['format'] ?? 'table') === 'json') {
            WP_CLI::line((string) wp_json_encode($view, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $rows = [];
        foreach (['listing', 'indexable', 'reason', 'robots', 'canonical', 'prev', 'next', 'page', 'pages', 'total', 'title', 'description', 'h1', 'rule_label', 'locale', 'adapter', 'not_found'] as $field) {
            $value = $view[$field] ?? null;
            $rows[] = ['field' => $field, 'value' => is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value];
        }

        WP_CLI\Utils\format_items('table', $rows, ['field', 'value']);
    }
}
