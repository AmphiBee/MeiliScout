<?php

declare(strict_types=1);

use Pollora\MeiliScout\Config\Portability\SecretGuard;
use Pollora\MeiliScout\Config\Portability\Transfer;

/*
 * The configuration export on a real WordPress: what this site exports it
 * imports as no change, without a secret, and the SEO rules it holds are
 * recognised as its own. Nothing here writes.
 */

test('what the site exports, it imports as no change', function () {
    $document = Transfer::export();
    $plan = Transfer::plan(Transfer::parse((string) json_encode($document)));

    expect($plan->hasErrors())->toBeFalse()
        ->and($plan->changes())->toBeFalse()
        ->and($plan->reindex())->toBeFalse()
        ->and(SecretGuard::offending($document))->toBe([]);
});

test('the export never holds the connection nor the listings key', function () {
    $json = (string) json_encode(Transfer::export(null, true));

    foreach (['meili_host', 'meili_key', 'meili_search_key', 'listings_key', 'last_indexing_structure'] as $name) {
        expect($json)->not->toContain($name);
    }
});

test('a file with another value plans a change that writes nothing', function () {
    $document = Transfer::export(['queries']);
    $before = get_option('meiliscout/max_total_hits');
    $document['sections']['queries']['max_total_hits'] = (int) $document['sections']['queries']['max_total_hits'] + 1;

    $plan = Transfer::plan(Transfer::parse((string) json_encode($document)));

    expect($plan->changes())->toBeTrue()
        ->and($plan->sections['queries']->rows[2]['field'])->toBe('max_total_hits')
        ->and(get_option('meiliscout/max_total_hits'))->toBe($before);
});
