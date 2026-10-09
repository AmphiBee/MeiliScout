<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Listings;

use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\State\ListingState;

require_once __DIR__.'/wordpress.php';

// The cases tests/fixtures/listings/ shares with the client's tests (resources/listings/test)

function listingCases(string $file): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/listings/'.$file), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * A state as the cases write it.
 *
 * @return array<string, mixed>
 */
function stateArray(ListingState $state): array
{
    return [
        'values' => $state->values,
        'ranges' => $state->ranges,
        'sort' => $state->sort,
        'page' => $state->page,
        'search' => $state->search,
    ];
}

function casesDefinition(): ListingDefinition
{
    $GLOBALS['wp_options']['meiliscout/indexed_post_types'] = ['post'];
    $GLOBALS['wp_options']['meiliscout/indexed_meta_keys'] = ['color', 'price', 'featured'];
    $GLOBALS['wp_rewrite'] = new \WP_Rewrite;

    return ListingDefinition::fromArray('cases', listingCases('definition.json'));
}

uses()->beforeEach(fn () => $GLOBALS['listings_wordpress'] = true)
    ->afterEach(fn () => $GLOBALS['listings_wordpress'] = false)
    ->in(__DIR__);
