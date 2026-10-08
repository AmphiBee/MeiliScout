<?php

declare(strict_types=1);

use Pollora\MeiliScout\Diagnostics\QueryParity;

/*
 * Each built-in case of `wp meiliscout check-queries`, against the site's
 * MySQL and Meilisearch: a query Meilisearch serves returns what MySQL
 * returns, and one it does not serve gives the reason.
 */

dataset('parity cases', function () {
    foreach (QueryParity::cases() as $case) {
        yield $case['label'] => [$case];
    }
});

test('Meilisearch returns what MySQL returns, or says why it did not serve the query', function (array $case) {
    if ($case['args'] === null) {
        $this->markTestSkipped('The site lacks the data for this case.');
    }

    $result = QueryParity::compare($case['args'], $case['mode']);

    expect($result['outcome'])->not->toBeIn([QueryParity::DIFF, QueryParity::ERROR], implode('; ', $result['notes']));

    if ($result['outcome'] === QueryParity::FALLBACK) {
        expect($result['reason'])->toBeString()->not->toBe('');
    }
})->with('parity cases')->group('integration');
