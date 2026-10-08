<?php

declare(strict_types=1);

use Pollora\MeiliScout\Diagnostics\QueryParity;
use Pollora\MeiliScout\Diagnostics\TermQueryParity;

/*
 * Each built-in case of `wp meiliscout check-queries --terms`, against the
 * site's MySQL and Meilisearch: a term query Meilisearch serves returns what
 * MySQL returns, to the array keys; one it does not serve gives the reason.
 */

dataset('term parity cases', function () {
    foreach (TermQueryParity::cases() as $case) {
        yield $case['label'] => [$case];
    }
});

test('Meilisearch returns the terms MySQL returns, or says why it did not serve the query', function (array $case) {
    if ($case['args'] === null) {
        $this->markTestSkipped('The site lacks the data for this case.');
    }

    $result = TermQueryParity::compare($case['args'], $case['mode'], $case['keys']);

    expect($result['outcome'])->not->toBeIn([QueryParity::DIFF, QueryParity::ERROR], implode('; ', $result['notes']));

    if ($result['outcome'] === QueryParity::FALLBACK) {
        expect($result['reason'])->toBeString()->not->toBe('');
    }
})->with('term parity cases')->group('integration');
