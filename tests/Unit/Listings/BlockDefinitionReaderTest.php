<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Listings;

use Pollora\MeiliScout\Listings\Blocks\BlockDefinitionReader;

require_once __DIR__.'/cases.php';

// A listing block's definition: its attributes, and its facets from the facet blocks it holds

function facetBlock(array $attrs): array
{
    return ['blockName' => 'meiliscout/facet', 'attrs' => $attrs, 'innerBlocks' => []];
}

function listingBlock(array $attrs, array $inner): array
{
    return ['blockName' => 'meiliscout/listing', 'attrs' => $attrs, 'innerBlocks' => $inner];
}

test('the facets come from the facet blocks, nested anywhere, in the document\'s order', function () {
    $block = listingBlock(['postTypes' => ['post'], 'perPage' => 6], [
        ['blockName' => 'core/columns', 'attrs' => [], 'innerBlocks' => [
            ['blockName' => 'core/column', 'attrs' => [], 'innerBlocks' => [facetBlock(['source' => 'meta:color', 'limit' => 5])]],
            ['blockName' => 'core/column', 'attrs' => [], 'innerBlocks' => [facetBlock(['source' => 'meta:price', 'type' => 'range', 'decimals' => 2, 'label' => 'Price'])]],
        ]],
        facetBlock(['source' => '']),
    ]);

    $args = BlockDefinitionReader::read($block);

    expect(array_keys($args['facets']))->toBe(['color', 'price'])
        ->and($args['facets']['color'])->toBe(['source' => 'meta:color', 'limit' => 5])
        ->and($args['facets']['price'])->toBe(['source' => 'meta:price', 'type' => 'range', 'label' => 'Price', 'decimals' => 2])
        ->and($args['post_types'])->toBe(['post'])
        ->and($args['per_page'])->toBe(6)
        ->and($args['transport'])->toBe('fragment');
});

test('a facet\'s key comes from its source, unless given; a second facet with the same key is left out', function () {
    $args = BlockDefinitionReader::read(listingBlock([], [
        facetBlock(['source' => 'taxonomy:Project-Type']),
        facetBlock(['source' => 'meta:_price', 'key' => 'prix']),
        facetBlock(['source' => 'taxonomy:project_type', 'label' => 'Again']),
    ]));

    expect(array_keys($args['facets']))->toBe(['project_type', 'prix']);
});

test('a facet inside a listing nested in this one belongs to the nested one', function () {
    $args = BlockDefinitionReader::read(listingBlock([], [
        facetBlock(['source' => 'meta:color']),
        listingBlock([], [facetBlock(['source' => 'meta:size'])]),
    ]));

    expect(array_keys($args['facets']))->toBe(['color'])
        ->and(BlockDefinitionReader::listingBlocks([listingBlock(['listingId' => 'a'], [listingBlock(['listingId' => 'b'], [])])]))->toHaveCount(2);
});

test('sorts become the definition\'s, a block listing never uses the client transport', function () {
    $args = BlockDefinitionReader::read(listingBlock([
        'sorts' => [
            ['key' => 'recent', 'label' => 'Recent', 'orderby' => 'date', 'order' => 'DESC'],
            ['key' => 'price', 'label' => 'Price', 'orderby' => 'meta_value_num', 'metaKey' => '_price', 'order' => 'ASC'],
            ['key' => '', 'label' => 'No key'],
        ],
        'defaultSort' => 'price',
        'transport' => 'client',
    ], []));

    expect($args['sorts'])->toBe([
        'recent' => ['label' => 'Recent', 'orderby' => 'date', 'order' => 'DESC'],
        'price' => ['label' => 'Price', 'orderby' => 'meta_value_num', 'order' => 'ASC', 'meta_key' => '_price'],
    ])->and($args['default_sort'])->toBe('price')
        ->and($args['transport'])->toBe('fragment');
});

test('a facet named as a WordPress query var takes its title as its name in the URL', function () {
    $args = BlockDefinitionReader::read(listingBlock([], [
        facetBlock(['source' => 'taxonomy:category', 'label' => 'Rubrique']),
        facetBlock(['source' => 'meta:page', 'label' => '']),
        facetBlock(['source' => 'meta:color', 'param' => 'couleur']),
    ]));

    expect($args['facets']['category'])->not->toHaveKey('param')
        ->and($args['facets']['page']['param'])->toBe('page_')
        ->and($args['facets']['color']['param'])->toBe('couleur')
        ->and(BlockDefinitionReader::param(['label' => 'Rubrique'], 'paged'))->toBe('rubrique');
});
