<?php

declare(strict_types=1);

use Pollora\MeiliScout\Query\AutoIntegration;
use Pollora\MeiliScout\Query\DebugHeader;
use Pollora\MeiliScout\Query\QueryLog;

if (! function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}

function mainQuery(array $vars = [], array $flags = []): WP_Query
{
    $query = new WP_Query($vars);
    $query->main = true;

    foreach ($flags as $flag) {
        $query->$flag = true;
    }

    return $query;
}

beforeEach(function () {
    $GLOBALS['wp_options'] = [];
    $GLOBALS['doing_ajax'] = false;
    $GLOBALS['is_admin'] = false;
});

test('nothing is served without asking, by default', function () {
    expect(AutoIntegration::wants(mainQuery([], ['is_search'])))->toBeFalse()
        ->and(AutoIntegration::settings())->toBe(['search' => false, 'archives' => false, 'rest_search' => false, 'admin' => false]);
});

test('the settings cover the site search, archives and admin lists, main queries only', function () {
    AutoIntegration::save(['search' => true, 'archives' => true]);

    $secondary = new WP_Query;
    $secondary->is_search = true;

    expect(AutoIntegration::wants(mainQuery([], ['is_search'])))->toBeTrue()
        ->and(AutoIntegration::wants(mainQuery([], ['is_category'])))->toBeTrue()
        ->and(AutoIntegration::wants(mainQuery([], ['is_tax'])))->toBeTrue()
        ->and(AutoIntegration::wants(mainQuery()))->toBeFalse()
        ->and(AutoIntegration::wants($secondary))->toBeFalse();

    $GLOBALS['is_admin'] = true;
    expect(AutoIntegration::wants(mainQuery([], ['is_search'])))->toBeFalse();

    AutoIntegration::save(['admin' => true]);
    expect(AutoIntegration::wants(mainQuery()))->toBeTrue();
});

test('use_meilisearch wins either way', function () {
    AutoIntegration::save(['search' => true]);

    expect(AutoIntegration::wants(mainQuery(['use_meilisearch' => false], ['is_search'])))->toBeFalse()
        ->and(AutoIntegration::wants(new WP_Query(['use_meilisearch' => true])))->toBeTrue();
});

test('AJAX queries are served only when they ask', function () {
    AutoIntegration::save(['search' => true]);
    $GLOBALS['doing_ajax'] = true;

    expect(AutoIntegration::wants(mainQuery([], ['is_search'])))->toBeFalse();
});

test('filters can skip a query, or have the last word', function () {
    AutoIntegration::save(['search' => true]);

    $GLOBALS['filters']['meiliscout/skip_query_integration'] = true;
    expect(AutoIntegration::wants(mainQuery([], ['is_search'])))->toBeFalse();

    $GLOBALS['filters'] = ['meiliscout/integrate_query' => true];
    expect(AutoIntegration::wants(mainQuery()))->toBeTrue();
});

test('REST searches ask for Meilisearch when the setting is on', function () {
    expect(AutoIntegration::restQuery(['s' => 'x']))->toBe(['s' => 'x']);

    AutoIntegration::save(['rest_search' => true]);

    expect(AutoIntegration::restQuery(['s' => 'x']))->toBe(['s' => 'x', 'use_meilisearch' => true])
        ->and(AutoIntegration::restQuery(['s' => '']))->toBe(['s' => ''])
        ->and(AutoIntegration::restQuery(['s' => 'x', 'use_meilisearch' => false]))->toBe(['s' => 'x', 'use_meilisearch' => false]);
});

test('the debug header says served, or the reason on one ASCII line', function () {
    expect(DebugHeader::value(['served' => true]))->toBe('served')
        ->and(DebugHeader::value(['served' => false, 'reason' => "unindexed_meta:prix\nX: é"]))->toBe('fallback:unindexed_meta:prixX: ');
});

test('a search as curl leaves the key out', function () {
    update_option('meiliscout/meili_host', 'https://search.example.com/');

    expect(QueryLog::curl('site_posts', ['q' => "l'été", 'limit' => 2]))
        ->toBe("curl -X POST 'https://search.example.com/indexes/site_posts/search' -H 'Authorization: Bearer \$MEILI_SEARCH_KEY' -H 'Content-Type: application/json' --data '{\"q\":\"l'\\''été\",\"limit\":2}'");
});
