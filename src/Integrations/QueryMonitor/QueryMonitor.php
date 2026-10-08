<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Integrations\QueryMonitor;

/**
 * Adds a MeiliScout panel to Query Monitor: each query (WP_Query, or
 * get_terms()) that asked for Meilisearch, whether it served it or why not,
 * what was sent, and how long it took.
 *
 * Query Monitor's classes exist once it asks for its collectors: the classes
 * extending them are loaded then.
 */
final class QueryMonitor
{
    public const ID = 'meiliscout';

    /**
     * Hooked on qm/collectors.
     *
     * @param  array<string, object>  $collectors
     * @return array<string, object>
     */
    public static function registerCollector(array $collectors): array
    {
        if (class_exists('QM_DataCollector')) {
            require_once __DIR__.'/Collector.php';
            $collectors[self::ID] = new Collector;
        }

        return $collectors;
    }

    /**
     * Hooked on qm/outputter/html.
     *
     * @param  array<string, object>  $output
     * @return array<string, object>
     */
    public static function registerOutput(array $output, mixed $collectors = null): array
    {
        $collector = class_exists('QM_Collectors') ? \QM_Collectors::get(self::ID) : null; // @phpstan-ignore class.notFound

        if ($collector !== null && class_exists('QM_Output_Html')) {
            require_once __DIR__.'/Output.php';
            $output[self::ID] = new Output($collector);
        }

        return $output;
    }
}
