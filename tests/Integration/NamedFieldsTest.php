<?php

declare(strict_types=1);

use Pollora\MeiliScout\Fields\FieldRegistry;

/*
 * Named custom fields against a real WordPress: the demo's ACF and Meta Box
 * fields (mu-plugin meiliscout-fields-demo.php) and what the admin's REST
 * routes make of them. Skipped on a site without those plugins.
 */

beforeEach(function () {
    if (! function_exists('acf_get_field_groups') || ! function_exists('rwmb_get_registry')) {
        $this->markTestSkipped('ACF and Meta Box are not active on this site.');
    }

    wp_set_current_user(1);
    FieldRegistry::flush();
});

function metaKeysRoute(array $params): array
{
    $request = new WP_REST_Request('GET', '/meiliscout/v1/meta-keys');
    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }
    $response = rest_do_request($request);

    expect($response->get_status())->toBe(200);

    return $response->get_data();
}

test('ACF and Meta Box fields are named, with their post types and types', function () {
    $sector = FieldRegistry::get('client_sector');
    $date = FieldRegistry::get('event_date');

    expect($sector->label)->toBe('Réalisation › Secteur du client')
        ->and($sector->source)->toBe('acf')
        ->and($sector->postTypes)->toBe(['realisation'])
        ->and(FieldRegistry::get('contact_email')->label)->toBe('Réalisation › Contact › E-mail')
        ->and($date->source)->toBe('metabox')
        ->and($date->type)->toBe('date');
});

test('repeaters, tabs, options pages and headings give no field', function () {
    $keys = array_keys(FieldRegistry::all());

    expect($keys)->not->toContain('steps')
        ->and($keys)->not->toContain('tab')
        ->and($keys)->not->toContain('slogan')
        ->and($keys)->not->toContain('event_title');
});

test('the field search lists named fields first, with their group, and never a raw duplicate', function () {
    $data = metaKeysRoute(['search' => 'secteur', 'post_types' => ['realisation']]);
    $sector = array_values(array_filter($data['keys'], fn ($key) => $key['key'] === 'client_sector'));

    expect($sector)->toHaveCount(1)
        ->and($sector[0]['registered'])->toBeTrue()
        ->and($sector[0]['label'])->toBe('Réalisation › Secteur du client')
        ->and($sector[0]['group'])->toBe('Advanced Custom Fields')
        ->and($sector[0]['type'])->toBe('text');
});

test('what a search matches is found by name as well as by key', function () {
    $byName = metaKeysRoute(['search' => 'livraison', 'post_types' => ['realisation']]);
    $byKey = metaKeysRoute(['search' => 'delivery', 'post_types' => ['realisation']]);

    expect(array_column($byName['keys'], 'key'))->toContain('delivery_date')
        ->and(array_column($byKey['keys'], 'key'))->toContain('delivery_date');
});

test('the list is capped, and says how many more match', function () {
    $data = metaKeysRoute(['search' => '', 'post_types' => ['realisation', 'post', 'page']]);

    expect(count($data['keys']))->toBeLessThanOrEqual(20)
        ->and($data['total'])->toBeGreaterThanOrEqual(count($data['keys']));
});

test('a source narrows the list to its fields', function () {
    $data = metaKeysRoute(['source' => 'metabox', 'post_types' => ['realisation']]);

    expect(array_unique(array_column($data['keys'], 'source')))->toBe(['metabox'])
        ->and(array_column($data['keys'], 'key'))->toContain('event_date');
});

test('WordPress\'s own bookkeeping keys are not suggested', function () {
    $keys = array_column(metaKeysRoute(['search' => '_', 'post_types' => ['realisation', 'post', 'page']])['keys'], 'key');

    expect(array_filter($keys, fn ($key) => str_starts_with($key, '_wp_') || $key === '_thumbnail_id'))->toBe([]);
});

test('the references ACF stores next to its fields are not offered as keys', function () {
    $data = metaKeysRoute(['search' => 'client', 'post_types' => ['realisation']]);

    expect(array_column($data['keys'], 'key'))->not->toContain('_client_sector');
});

test('the sources that have fields on the selected types are told, for the filter chips', function () {
    $data = metaKeysRoute(['post_types' => ['realisation']]);
    $sources = array_column($data['sources'], 'count', 'source');

    expect($sources)->toHaveKeys(['acf', 'metabox'])
        ->and($sources['acf'])->toBeGreaterThanOrEqual(5);
});

test('a field already selected comes back named in the selection', function () {
    $response = rest_do_request(new WP_REST_Request('GET', '/meiliscout/v1/content'));
    $selected = array_column($response->get_data()['meta_keys'], null, 'key');

    if (! isset($selected['client_sector'])) {
        $this->markTestSkipped('client_sector is not selected on this site.');
    }

    expect($selected['client_sector']['label'])->toBe('Réalisation › Secteur du client')
        ->and($selected['client_sector']['source'])->toBe('acf')
        ->and($selected['featured']['type'] ?? 'boolean')->toBe('boolean');
});

test('a facet on a named field takes its short name as its label', function () {
    $listing = \Pollora\MeiliScout\Listings\Definition\ListingDefinition::fromArray('named-fields-test', [
        'post_types' => ['realisation'],
        'route' => ['page' => 756],
        'facets' => [
            'secteur' => ['source' => 'meta:client_sector'],
            'materiaux' => ['source' => 'meta:materials', 'label' => 'Choisi par le site'],
        ],
    ]);

    // A label given by the definition wins over the plugin's name
    expect(array_column($listing->facets, 'label', 'key'))->toBe([
        'secteur' => 'Secteur du client',
        'materiaux' => 'Choisi par le site',
    ]);
});
