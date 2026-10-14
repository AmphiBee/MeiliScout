<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config\Portability;

use Pollora\MeiliScout\Config\Portability\Sections\IndexingSection;
use Pollora\MeiliScout\Config\Portability\Sections\ListingsSection;
use Pollora\MeiliScout\Config\Portability\Sections\QueriesSection;
use Pollora\MeiliScout\Config\Portability\Sections\SeoRulesSection;

use function __;
use function apply_filters;
use function gmdate;
use function home_url;
use function json_decode;
use function sprintf;
use function strlen;

/**
 * Exports the configuration to a file and plans, then applies, an import.
 *
 * The file is one JSON document, versioned, one entry per section. Its keys
 * are an allow-list: a setting the sections do not name is never read from it
 * nor written to the site, and the connection (URL, keys), the listings key
 * and the state of the indexes are never part of it.
 */
final class Transfer
{
    /**
     * The file's format, bumped when its shape changes.
     */
    public const FORMAT = 1;

    /**
     * The largest file an import reads.
     */
    public const MAX_BYTES = 1048576;

    /**
     * @return array<string, Section>  By key, in the order a file lists them
     */
    public static function sections(): array
    {
        $sections = [new IndexingSection, new QueriesSection, new ListingsSection, new SeoRulesSection];

        /**
         * Filters the sections an export carries: a plugin adds its own.
         *
         * @param  list<Section>  $sections
         */
        /** @var array<mixed> $sections A filter's answer is whatever a plugin returned */
        $sections = apply_filters('meiliscout/config_sections', $sections);

        $byKey = [];
        foreach ($sections as $section) {
            if ($section instanceof Section) {
                $byKey[$section->key()] = $section;
            }
        }

        return $byKey;
    }

    /**
     * @param  list<string>|null  $only  The sections to export (all by default)
     * @return array<string, mixed>
     */
    public static function export(?array $only = null, bool $includeEnvironment = false): array
    {
        $sections = [];

        foreach (self::sections() as $key => $section) {
            if (($only !== null && ! in_array($key, $only, true)) || $section->unavailable() !== null) {
                continue;
            }

            $sections[$key] = $section->export($includeEnvironment);
        }

        $document = [
            'meiliscout_export' => self::FORMAT,
            'plugin_version' => defined('MEILISCOUT_VERSION') ? MEILISCOUT_VERSION : 'dev',
            'exported_at' => gmdate('c'),
            'source' => ['site' => home_url()],
            'sections' => $sections,
        ];

        SecretGuard::assertClean($document);

        return $document;
    }

    /**
     * Reads a file's text.
     *
     * @return array<string, mixed>
     *
     * @throws ImportException
     */
    public static function parse(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new ImportException(__('The file is larger than 1 MB: it is not an export of MeiliScout.', 'meiliscout'), 'too_large');
        }

        $document = json_decode(ltrim($json, "\xEF\xBB\xBF"), true);

        if (! is_array($document) || ! isset($document['meiliscout_export'])) {
            throw new ImportException(__('This is not a MeiliScout export.', 'meiliscout'), 'not_an_export');
        }

        if (! is_int($document['meiliscout_export']) || $document['meiliscout_export'] > self::FORMAT) {
            throw new ImportException(__('This export was made by a newer version of MeiliScout: update the plugin to read it.', 'meiliscout'), 'newer_format');
        }

        if (! isset($document['sections']) || ! is_array($document['sections'])) {
            throw new ImportException(__('This export holds no section.', 'meiliscout'), 'empty');
        }

        return $document;
    }

    /**
     * What importing a document would do. Nothing is written.
     *
     * @param  array<string, mixed>  $document  What parse() returned
     * @param  array{only?: list<string>|null, include_env?: bool, strict?: bool}  $options
     */
    public static function plan(array $document, array $options = []): ImportPlan
    {
        $only = $options['only'] ?? null;
        $known = self::sections();
        $plans = [];
        $labels = [];
        $warnings = [];

        foreach ($document['sections'] as $key => $data) {
            if (! isset($known[$key])) {
                $warnings[] = sprintf(
                    /* translators: %s: a section's name in the file */
                    __('The section “%s” is not known to this version of MeiliScout: ignored.', 'meiliscout'),
                    (string) $key
                );
                continue;
            }

            if ($only !== null && ! in_array($key, $only, true)) {
                continue;
            }

            $labels[$key] = $known[$key]->label();
            $plans[$key] = is_array($data)
                ? $known[$key]->plan($data, $options)
                : self::broken($key);
        }

        if ($only !== null) {
            foreach (array_diff($only, array_keys($document['sections'])) as $missing) {
                $warnings[] = sprintf(
                    /* translators: %s: a section's name */
                    __('The file has no section “%s”.', 'meiliscout'),
                    $missing
                );
            }
        }

        $source = (array) ($document['source'] ?? []);
        $source['plugin_version'] = $document['plugin_version'] ?? null;
        // Whether it holds values of the server it came from, which an import may leave out
        $source['has_environment'] = array_filter($document['sections'], static fn ($data) => is_array($data) && isset($data['environment'])) !== [];
        $source['exported_at'] = $document['exported_at'] ?? null;

        return new ImportPlan($plans, $labels, $warnings, $source, (bool) ($options['strict'] ?? false));
    }

    /**
     * Writes a plan.
     *
     * @param  list<string>|null  $only  The sections to apply (all of the plan by default)
     * @return list<string>  The sections written
     */
    public static function apply(ImportPlan $plan, ?array $only = null): array
    {
        $known = self::sections();
        $applied = [];

        foreach ($plan->sections as $key => $sectionPlan) {
            if (($only !== null && ! in_array($key, $only, true)) || ! $sectionPlan->changes() || ! isset($known[$key])) {
                continue;
            }

            $known[$key]->apply($sectionPlan);
            $applied[] = $key;
        }

        return $applied;
    }

    private static function broken(string $key): SectionPlan
    {
        $plan = new SectionPlan($key);
        $plan->fail(__('This section must be an object.', 'meiliscout'));

        return $plan;
    }
}
