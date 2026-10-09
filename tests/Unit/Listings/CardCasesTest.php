<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Listings;

use Pollora\MeiliScout\Listings\Render\Hits;
use Pollora\MeiliScout\Listings\Render\Renderer;

require_once __DIR__.'/cases.php';

// The cases resources/listings/test/hits.test.js runs too: the client makes the cards the server makes

test('a post\'s date in the site\'s format', function () {
    $fixture = listingCases('card-cases.json');

    foreach ($fixture['dates'] as $case) {
        expect(Hits::formatDate(['format' => $case['format']] + $fixture['names'], $case['date']))->toBe($case['label'], $case['format']);
    }
});

test('a card from a document', function () {
    $fixture = listingCases('card-cases.json');

    foreach ($fixture['cards'] as $case) {
        // The client reads JSON: objects compared as arrays
        $hit = json_decode((string) json_encode(Hits::fromDocument($case['document'], ['color', 'price'], $fixture['names'])), true);

        expect($hit)->toBe($case['hit']);
    }
});

test('the pagination\'s items', function () {
    foreach (listingCases('card-cases.json')['pagination'] as $case) {
        expect(Renderer::pageItems($case['page'], $case['pages']))->toBe($case['items']);
    }
});
