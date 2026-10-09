<?php

declare(strict_types=1);

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Diagnostics\CountParity;
use Pollora\MeiliScout\Listings\Diagnostics\ListingChecks;
use Pollora\MeiliScout\Listings\Diagnostics\RouteProbe;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\ListingsServiceProvider;

/*
 * What the Listings screen and `wp meiliscout check-listings` say, on the demo
 * site: its listings' counts against MySQL in every language, their URLs over
 * HTTP, and the checks a listing declared here trips.
 */

beforeEach(function () {
    if (ListingsServiceProvider::unavailable() !== null || ! DefinitionRegistry::has('realisations')) {
        $this->markTestSkipped('Needs the listings module on and the demo\'s listings.');
    }

    $this->page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Checks test', 'post_name' => 'checks-test-'.strtolower(wp_generate_password(6, false, false))]);
    $this->child = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Child', 'post_name' => 'tp-child', 'post_parent' => $this->page]);
});

afterEach(function () {
    foreach (['child', 'page'] as $post) {
        if (isset($this->{$post})) {
            wp_delete_post($this->{$post}, true);
        }
    }
    foreach (['checks-test', 'checks-reserved', 'checks-twin'] as $id) {
        DefinitionRegistry::remove($id);
    }
});

/**
 * @return list<string>
 */
function checkCodes(array $report, string $level): array
{
    return array_column(array_filter($report['checks'], fn (array $check) => $check['level'] === $level), 'code');
}

test('every demo listing shows MySQL\'s counts, in every language', function () {
    foreach (['realisations', 'realisations-client', 'realisations-briques'] as $id) {
        foreach (Languages::adapter()->languages() ?: [''] as $language) {
            $parity = CountParity::compare(DefinitionRegistry::get($id), $language);

            expect($parity['diffs'])->toBe(0, "{$id} [{$language}]: ".wp_json_encode(array_filter($parity['rows'], fn ($row) => ! $row['ok'])))
                ->and($parity['rows'])->not->toBeEmpty();
        }
    }
});

test('the demo listings have no error', function () {
    foreach (['realisations', 'realisations-client', 'realisations-briques'] as $id) {
        expect(checkCodes(ListingChecks::report($id, ListingChecks::documents()), ListingChecks::ERROR))->toBe([], $id);
    }
});

test('a listing\'s URLs answer as a visitor expects', function () {
    foreach (Languages::adapter()->languages() ?: [''] as $language) {
        $probes = RouteProbe::run(DefinitionRegistry::get('realisations'), $language);

        expect($probes)->not->toBeEmpty();
        foreach ($probes as $probe) {
            expect($probe['status'])->toBe($probe['expected'], "[{$language}] {$probe['label']} {$probe['url']} {$probe['note']}");
        }
    }
});

test('a child page whose slug starts with a prefix is reported', function () {
    DefinitionRegistry::declare('checks-test', [
        'post_types' => ['realisation'],
        'route' => ['page' => $this->page],
        'facets' => ['type' => ['source' => 'taxonomy:project_type', 'path' => 'tp']],
    ]);

    $report = ListingChecks::report('checks-test', ListingChecks::documents());

    expect(checkCodes($report, ListingChecks::WARNING))->toContain('prefix_post')
        ->and($report['facets'][0]['source'])->toBe('taxonomy:project_type');
});

test('a reserved parameter, a comment prefix and a shared route are reported', function () {
    DefinitionRegistry::declare('checks-reserved', [
        'post_types' => ['realisation'],
        'facets' => ['type' => ['source' => 'taxonomy:project_type', 'param' => 'paged']],
    ]);
    DefinitionRegistry::declare('checks-test', [
        'post_types' => ['realisation'],
        'route' => ['page' => $this->page],
        'facets' => ['type' => ['source' => 'taxonomy:project_type', 'path' => 'comment']],
    ]);
    DefinitionRegistry::declare('checks-twin', ['post_types' => ['realisation'], 'route' => ['page' => $this->page]]);

    $reserved = ListingChecks::report('checks-reserved');
    $prefixed = ListingChecks::report('checks-test');

    expect($reserved['valid'])->toBeFalse()
        ->and(implode(' ', array_column($reserved['checks'], 'message')))->toContain('"paged"')
        ->and(checkCodes($prefixed, ListingChecks::ERROR))->toContain('prefix_wordpress')
        ->and(checkCodes($prefixed, ListingChecks::WARNING))->toContain('shared_route');
});
