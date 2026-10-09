<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Listings;

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\PublicFields;
use Pollora\MeiliScout\Query\Builders\FieldsBuilder;
use Pollora\MeiliScout\Query\MeiliQueryBuilder;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\IndexSettings;
use Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder\MockWPQuery;

require_once __DIR__.'/cases.php';

// What browsers may read once listings are on (design, decision A)

beforeEach(function () {
    $GLOBALS['wp_options'] = [];
    DefinitionRegistry::reset();
});

afterEach(fn () => DefinitionRegistry::reset());

test('the index returns the public fields and the meta keys a listing makes public', function () {
    casesDefinition();
    DefinitionRegistry::declare('a', ['post_types' => ['post'], 'public_metas' => ['price']]);
    DefinitionRegistry::declare('b', ['post_types' => ['post'], 'public_metas' => ['price', 'color']]);

    $fields = PublicFields::restrict(['*']);

    expect($fields)->toBe([...PublicFields::FIELDS, 'metas.price', 'metas.color'])
        ->not->toContain('metas.featured')
        ->not->toContain('post_content')
        ->not->toContain('post_author');
});

test('a listing that cannot be served makes nothing public', function () {
    casesDefinition();
    DefinitionRegistry::declare('broken', ['post_types' => ['post'], 'public_metas' => ['price'], 'transport' => 'carrier-pigeon']);

    expect(PublicFields::allowlist())->toBe(PublicFields::FIELDS);
});

test('a list already narrowed is not widened', function () {
    expect(PublicFields::restrict(['ID', 'card']))->toBe(['ID', 'card']);
});

test('MeiliScout\'s own queries still read what they need', function () {
    IndexSettings::remember(IndexNames::active('posts'), ['displayedAttributes' => PublicFields::FIELDS]);

    expect((new MeiliQueryBuilder)->build(new MockWPQuery(['fields' => 'id=>parent']))['attributesToRetrieve'])
        ->toBe(['ID', 'post_parent'])
        ->and((new MeiliQueryBuilder)->build(new MockWPQuery(['post_name__in' => ['a', 'b'], 'orderby' => 'post_name__in']))['attributesToRetrieve'])
        ->toBe(['ID', 'post_name']);
});

test('posts are not built from documents that lack their columns', function () {
    $GLOBALS['filters']['meiliscout/hydrate_from_documents'] = true;

    expect(FieldsBuilder::canHydrate())->toBeTrue()
        ->and(FieldsBuilder::hydrateFromDocuments(new MockWPQuery([])))->toBeTrue();

    IndexSettings::remember(IndexNames::active('posts'), ['displayedAttributes' => PublicFields::FIELDS]);

    expect(FieldsBuilder::canHydrate())->toBeFalse()
        ->and(FieldsBuilder::hydrateFromDocuments(new MockWPQuery([])))->toBeFalse()
        ->and((new MeiliQueryBuilder)->build(new MockWPQuery([]))['attributesToRetrieve'])->toBe(['ID']);
});
