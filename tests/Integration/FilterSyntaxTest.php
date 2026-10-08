<?php

declare(strict_types=1);

use Pollora\MeiliScout\Services\ClientFactory;

/*
 * The filters the unit tests make the builders produce are only strings to
 * them: Meilisearch has to parse each one. The unit suite is run with
 * MEILISCOUT_RECORD_FILTERS, which records every filter built, and each is
 * sent to a scratch index where every attribute is filterable.
 */

const SYNTAX_INDEX = 'meiliscout_filter_syntax_check';

function recordedFilters(): array
{
    static $filters = null;

    if ($filters !== null) {
        return $filters;
    }

    $file = tempnam(sys_get_temp_dir(), 'meiliscout-filters');
    $root = dirname(__DIR__, 2);

    exec(sprintf(
        'cd %s && MEILISCOUT_RECORD_FILTERS=%s %s vendor/bin/pest -c phpunit.xml 2>&1',
        escapeshellarg($root),
        escapeshellarg($file),
        escapeshellarg(PHP_BINARY)
    ), $output, $status);

    if ($status !== 0) {
        throw new RuntimeException("The unit suite failed:\n".implode("\n", array_slice($output, -30)));
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    unlink($file);

    return $filters = array_values(array_unique($lines));
}

beforeAll(function () {
    $client = ClientFactory::getClient();
    $task = $client->createIndex(SYNTAX_INDEX, ['primaryKey' => 'ID']);
    $client->waitForTask($task['taskUid']);
    $task = $client->index(SYNTAX_INDEX)->updateSettings([
        'filterableAttributes' => [[
            'attributePatterns' => ['*'],
            'features' => ['facetSearch' => false, 'filter' => ['equality' => true, 'comparison' => true]],
        ]],
    ]);
    $client->waitForTask($task['taskUid']);
});

afterAll(function () {
    ClientFactory::getClient()?->deleteIndex(SYNTAX_INDEX);
});

test('the unit suite builds filters', function () {
    expect(count(recordedFilters()))->toBeGreaterThan(20);
})->group('integration');

test('Meilisearch parses every filter the builders produce', function () {
    $index = ClientFactory::getClient()->index(SYNTAX_INDEX);
    $rejected = [];

    foreach (recordedFilters() as $filter) {
        try {
            $index->search('', ['filter' => $filter, 'limit' => 0]);
        } catch (Throwable $e) {
            $rejected[] = $filter."\n    → ".strtok($e->getMessage(), "\n");
        }
    }

    expect($rejected)->toBe([], "Filters Meilisearch rejects:\n".implode("\n", $rejected));
})->group('integration');
