<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Integrations\QueryMonitor;

use Pollora\MeiliScout\Query\QueryLog;

/**
 * The data of the MeiliScout panel. Loaded only when Query Monitor runs.
 */
final class Data extends \QM_Data
{
    /**
     * @var list<array<string, mixed>>
     */
    public $queries = [];
}

/**
 * Collects the queries that asked for Meilisearch. Loaded only when Query Monitor runs.
 *
 * @extends \QM_DataCollector<Data>
 */
final class Collector extends \QM_DataCollector
{
    public $id = QueryMonitor::ID;

    public function get_storage(): \QM_Data
    {
        return new Data;
    }

    public function process(): void
    {
        $this->data->queries = QueryLog::entries();
    }
}
