<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Commands;

use Pollora\MeiliScout\Config\Portability\ImportException;
use Pollora\MeiliScout\Config\Portability\Transfer;
use WP_CLI;

/**
 * Moves MeiliScout's configuration from one site to another: what is indexed,
 * what is searched, the queries served, the front listings, the SEO rules.
 * Never the connection, the keys, nor the indexes' state.
 */
class ConfigCommand
{
    /**
     * Writes the configuration as JSON, to a file or the standard output.
     *
     * ## OPTIONS
     *
     * [<file>]
     * : The file to write. Without it, the standard output.
     *
     * [--sections=<list>]
     * : Comma-separated sections: indexing, queries, listings, seo_rules. All by default.
     *
     * [--include-env]
     * : Also the values that belong to the server (the public URL of Meilisearch).
     *
     * ## EXAMPLES
     *
     *     $ wp meiliscout export meiliscout.json
     *     $ wp meiliscout export --sections=indexing,queries > conf.json
     *
     * @param  array<int, string>  $args
     * @param  array<string, mixed>  $assocArgs
     */
    public function export(array $args, array $assocArgs): void
    {
        $only = $this->list($assocArgs['sections'] ?? null);
        $this->knownOrFail($only);

        try {
            $document = Transfer::export($only, (bool) ($assocArgs['include-env'] ?? false));
        } catch (\LogicException $e) {
            WP_CLI::error($e->getMessage());

            return;
        }

        $json = (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;

        if (isset($args[0])) {
            if (file_put_contents($args[0], $json) === false) {
                WP_CLI::error("Cannot write {$args[0]}.");
            }

            WP_CLI::success(sprintf('%d section(s) written to %s.', count($document['sections']), $args[0]));

            return;
        }

        echo $json;
    }

    /**
     * Shows what importing a file would change, and with --apply, changes it.
     *
     * Without --apply nothing is written: it is a dry run. The import never
     * reindexes by itself; it says when the index is out of date.
     *
     * ## OPTIONS
     *
     * <file>
     * : An export of MeiliScout.
     *
     * [--only=<list>]
     * : Comma-separated sections to read from the file.
     *
     * [--include-env]
     * : Also import the values that belong to the server (the public URL of Meilisearch).
     *
     * [--strict]
     * : A warning is a refusal (a post type this site does not register, a setting that is ignored...).
     *
     * [--apply]
     * : Write the changes.
     *
     * [--reindex]
     * : After --apply, run a full indexation when the index is out of date.
     *
     * ## EXAMPLES
     *
     *     $ wp meiliscout import meiliscout.json
     *     $ wp meiliscout import meiliscout.json --strict --apply --reindex
     *
     * @param  array<int, string>  $args
     * @param  array<string, mixed>  $assocArgs
     */
    public function import(array $args, array $assocArgs): void
    {
        $file = $args[0] ?? '';

        if (! is_file($file) || ! is_readable($file)) {
            WP_CLI::error("Cannot read {$file}.");
        }

        try {
            $document = Transfer::parse((string) file_get_contents($file));
        } catch (ImportException $e) {
            WP_CLI::error($e->getMessage());

            return;
        }

        $only = $this->list($assocArgs['only'] ?? null);
        $this->knownOrFail($only);

        $plan = Transfer::plan($document, [
            'only' => $only,
            'include_env' => (bool) ($assocArgs['include-env'] ?? false),
            'strict' => (bool) ($assocArgs['strict'] ?? false),
        ]);

        foreach ($plan->warnings as $warning) {
            WP_CLI::warning($warning);
        }

        foreach ($plan->sections as $key => $section) {
            WP_CLI::log('');
            WP_CLI::log(sprintf('%s (%s): %s', $plan->labels[$key] ?? $key, $key, $section->skipped ?? $section->status()));

            foreach ($section->rows as $row) {
                WP_CLI::log(sprintf(
                    '  %-10s %s: %s -> %s%s',
                    $row['status'],
                    $row['label'],
                    $this->show($row['before']),
                    $this->show($row['after']),
                    $row['note'] ? "  ({$row['note']})" : ''
                ));
            }

            foreach ($section->warnings as $warning) {
                WP_CLI::warning($warning);
            }
            foreach ($section->errors as $error) {
                WP_CLI::log("  error: {$error}");
            }
        }

        WP_CLI::log('');

        if ($plan->hasErrors()) {
            WP_CLI::error('The file has errors, nothing was written.');
        }

        if (! $plan->changes()) {
            WP_CLI::success('Nothing to change: this site already has this configuration.');

            return;
        }

        if (! $plan->applicable()) {
            WP_CLI::error('Refused: --strict and the file raised warnings.');
        }

        if (empty($assocArgs['apply'])) {
            WP_CLI::success('Dry run: nothing was written. Run again with --apply to write it.'.($plan->reindex() ? ' The index will need a full indexation.' : ''));

            return;
        }

        $applied = Transfer::apply($plan);
        WP_CLI::success(sprintf('Imported: %s.', implode(', ', $applied)));

        if (! $plan->reindex()) {
            return;
        }

        if (! empty($assocArgs['reindex'])) {
            WP_CLI::runcommand('meiliscout index');

            return;
        }

        WP_CLI::warning('What is indexed changed: run `wp meiliscout index` (or add --reindex) to rebuild the index.');
    }

    /**
     * @return list<string>|null
     */
    private function list(mixed $value): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * @param  list<string>|null  $names
     */
    private function knownOrFail(?array $names): void
    {
        foreach ($names ?? [] as $name) {
            if (! isset(Transfer::sections()[$name])) {
                WP_CLI::error(sprintf('Unknown section "%s". Known: %s.', $name, implode(', ', array_keys(Transfer::sections()))));
            }
        }
    }

    private function show(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'on' : 'off',
            is_array($value) && array_is_list($value) => $value === [] ? '(none)' : implode(', ', array_map(fn ($v) => (string) (is_scalar($v) ? $v : json_encode($v)), $value)),
            is_array($value) || is_object($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $value === null || $value === '' => '(none)',
            default => (string) $value,
        };
    }
}
