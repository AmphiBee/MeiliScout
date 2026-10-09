<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo;

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;

/**
 * SEO rules to and from CSV: one rule per row, a header naming the columns
 * (any order). The key's terms by id or slug; faq as JSON
 * ([{"question": "...", "answer": "..."}]). key_label, written for people,
 * is not read back.
 */
final class RulesCsv
{
    public const COLUMNS = ['listing', 'locale', 'key', 'title', 'description', 'h1', 'intro', 'faq', 'key_label'];

    /**
     * @param  list<SeoRule>  $rules
     */
    public static function export(array $rules): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, self::COLUMNS, ',', '"', '');

        foreach ($rules as $rule) {
            try {
                $label = RuleKey::label(DefinitionRegistry::get($rule->listing), $rule->key);
            } catch (InvalidListing|\OutOfBoundsException) {
                $label = '';
            }

            fputcsv($handle, [
                $rule->listing,
                $rule->locale,
                $rule->key,
                $rule->title,
                $rule->description,
                $rule->h1,
                $rule->intro,
                $rule->faq === [] ? '' : (string) wp_json_encode($rule->faq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $label,
            ], ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Saves the rules of a CSV: a row of an existing listing, language and
     * key replaces that rule. Rows with an error are skipped and reported.
     *
     * @return array{created: int, updated: int, errors: list<array{line: int, message: string}>}
     */
    public static function import(string $csv, bool $dryRun = false): array
    {
        $report = ['created' => 0, 'updated' => 0, 'errors' => []];
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return $report;
        }

        // A byte order mark, as spreadsheets write it
        fwrite($handle, (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($handle);

        $header = fgetcsv($handle, null, ',', '"', '');
        $columns = is_array($header) ? array_map(fn ($name) => strtolower(trim((string) $name)), $header) : [];

        if (! in_array('listing', $columns, true)) {
            fclose($handle);
            $report['errors'][] = ['line' => 1, 'message' => __('The first line must name the columns, listing at least.', 'meiliscout')];

            return $report;
        }

        $line = 1;
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if ($row === [null] || array_filter($row, fn ($cell) => trim((string) $cell) !== '') === []) {
                continue;
            }

            $values = [];
            foreach ($columns as $i => $name) {
                $values[$name] = (string) ($row[$i] ?? '');
            }

            try {
                $existing = SeoRulesService::save($values, $dryRun);
                $report[$existing ? 'updated' : 'created']++;
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $report['errors'][] = ['line' => $line, 'message' => $e->getMessage()];
            }
        }

        fclose($handle);

        return $report;
    }
}
