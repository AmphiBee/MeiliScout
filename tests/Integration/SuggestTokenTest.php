<?php

declare(strict_types=1);

use Pollora\MeiliScout\Config\Config;
use Pollora\MeiliScout\Listings\PublicFields;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;
use Pollora\MeiliScout\Suggest\Transport\SuggestKey;
use Pollora\MeiliScout\Suggest\Transport\SuggestTokens;

/*
 * A suggest field's token on the demo site: what a browser holding it can
 * read, and what it cannot.
 */

beforeEach(function () {
    SuggestRegistry::declare('token-test', [
        'sources' => [
            'posts' => ['post_types' => ['post']],
            'topics' => ['type' => 'terms', 'taxonomies' => ['category']],
        ],
    ]);

    // The indexes return the public fields now that a field is declared
    PublicFields::sync();
    $admin = ClientFactory::getClient();
    for ($i = 0; $i < 40 && $admin->getTasks((new \Meilisearch\Contracts\TasksQuery)->setStatuses(['enqueued', 'processing']))->getTotal() > 0; $i++) {
        usleep(500_000);
    }
});

afterEach(function () {
    SuggestRegistry::remove('token-test');
});

/**
 * A client holding the field's token.
 */
function suggestClient(): \Meilisearch\Client
{
    $token = SuggestTokens::forDefinition(SuggestRegistry::get('token-test'));
    expect($token)->not->toBeNull();

    return ClientFactory::build((string) Config::get('meili_host'), $token['value']);
}

test('the field has a key of its own, search only, on the two indexes', function () {
    $key = SuggestKey::get();
    $saved = SuggestKey::saved();
    $admin = ClientFactory::getClient();
    $meili = $admin->getKey($key['uid']);

    expect($key)->not->toBeNull()
        ->and($saved['indexes'])->toBe([IndexNames::active('posts'), IndexNames::active('taxonomies')])
        ->and($meili->getActions())->toBe(['search'])
        ->and($meili->getIndexes())->toBe($saved['indexes']);
});

test('the token reads published posts of the declared types, and only public fields', function () {
    $hits = suggestClient()->index(IndexNames::active('posts'))->search('', ['limit' => 50])->getHits();

    expect($hits)->not->toBeEmpty();

    foreach ($hits as $hit) {
        expect($hit['post_type'])->toBe('post')
            ->and($hit)->toHaveKey('post_title')
            ->and($hit)->not->toHaveKey('post_author')
            ->and($hit)->not->toHaveKey('post_content')
            ->and($hit)->not->toHaveKey('post_password');
    }
});

test('a filter of the browser cannot widen the token\'s', function () {
    $hits = suggestClient()->index(IndexNames::active('posts'))
        ->search('', ['filter' => 'post_type = "page" OR post_type = "post"', 'limit' => 50])->getHits();

    expect(array_unique(array_column($hits, 'post_type')))->toBe(['post']);
});

test('the token reads the declared taxonomies, without a term\'s metas', function () {
    $hits = suggestClient()->index(IndexNames::active('taxonomies'))->search('', ['limit' => 50])->getHits();

    expect($hits)->not->toBeEmpty();

    foreach ($hits as $hit) {
        expect($hit['taxonomy'])->toBe('category')
            ->and($hit)->toHaveKey('name')
            ->and($hit)->not->toHaveKey('metas');
    }
});

test('a post type the field does not read is out of reach', function () {
    // Another post type is out of reach: the token's filter is the field's
    $page = suggestClient()->index(IndexNames::active('posts'))->search('', ['filter' => 'post_type = "page"'])->getHits();

    expect($page)->toBe([]);
});
