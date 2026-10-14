<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Config\Portability;

use Pollora\MeiliScout\Config\Portability\ImportException;
use Pollora\MeiliScout\Config\Portability\SecretGuard;
use Pollora\MeiliScout\Config\Portability\SectionPlan;
use Pollora\MeiliScout\Config\Portability\Transfer;
use Pollora\MeiliScout\Config\Settings;

require_once __DIR__.'/wordpress.php';

beforeEach(function () {
    $GLOBALS['wp_options'] = [];
    $GLOBALS['wp_version'] = '6.9';
    $GLOBALS['portability_post_types'] = ['post', 'page', 'project'];
    $GLOBALS['listings_wordpress'] = true;
    putenv('MEILISCOUT_ASYNC_INDEXING');
});

afterEach(function () {
    unset($GLOBALS['portability_post_types'], $GLOBALS['listings_wordpress']);
});

/**
 * A site's configuration, as the admin would have saved it.
 */
function configured(): void
{
    Settings::save('indexed_post_types', ['post', 'project']);
    Settings::save('indexed_taxonomies', ['category']);
    Settings::save('indexed_meta_keys', ['_price', 'client_sector']);
    Settings::save('indexed_term_meta_keys', ['color']);
    Settings::save('searchable_attributes', ['post_title', 'metas._price']);
    Settings::save('realtime_indexing', 'async');
    Settings::save('query_integration', ['search' => true, 'archives' => false, 'rest_search' => true, 'admin' => false]);
    Settings::save('max_total_hits', 5000);
    Settings::save('listings_enabled', true);
    Settings::save('listings_colors', ['accent' => '#0a7']);
}

/**
 * A document without the date it was made.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function stable(array $document): array
{
    unset($document['exported_at']);

    return $document;
}

test('an export carries what is worth moving and no secret', function () {
    configured();
    Settings::save('meili_key', 'master-key-value');
    Settings::save('meili_host', 'http://meilisearch:7700');
    Settings::save('listings_key', ['uid' => 'abc', 'key' => 'signing-key']);

    $document = Transfer::export(['indexing', 'queries', 'listings']);
    $json = json_encode($document);

    expect($document['meiliscout_export'])->toBe(Transfer::FORMAT)
        ->and(array_keys($document['sections']))->toBe(['indexing', 'queries', 'listings'])
        ->and($document['sections']['indexing']['post_types'])->toBe(['post', 'project'])
        ->and($json)->not->toContain('master-key-value')
        ->and($json)->not->toContain('meilisearch:7700')
        ->and($json)->not->toContain('signing-key')
        ->and(SecretGuard::offending($document))->toBe([]);
});

test('the environment travels only when asked', function () {
    Settings::save('listings_public_host', 'https://search.example.com');

    $without = Transfer::export(['listings']);
    $with = Transfer::export(['listings'], true);

    expect($without['sections']['listings'])->not->toHaveKey('environment')
        ->and($with['sections']['listings']['environment']['public_host'])->toBe('https://search.example.com');
});

test('a section the site cannot serve is left out of the export', function () {
    // The listings module is off: no SEO rules
    expect(Transfer::export()['sections'])->not->toHaveKey('seo_rules');
});

test('what is exported imports back, to the same file', function () {
    configured();
    $before = stable(Transfer::export(['indexing', 'queries', 'listings']));

    $GLOBALS['wp_options'] = [];
    $plan = Transfer::plan(Transfer::parse((string) json_encode($before)));

    expect($plan->hasErrors())->toBeFalse()
        ->and($plan->changes())->toBeTrue()
        ->and(Transfer::apply($plan))->toBe(['indexing', 'queries', 'listings'])
        ->and(stable(Transfer::export(['indexing', 'queries', 'listings'])))->toEqual($before);
});

test('importing a file the site already has changes nothing', function () {
    configured();
    $plan = Transfer::plan(Transfer::parse((string) json_encode(Transfer::export(['indexing', 'queries', 'listings']))));

    expect($plan->changes())->toBeFalse()
        ->and($plan->reindex())->toBeFalse()
        ->and(Transfer::apply($plan))->toBe([]);
});

test('the plan says before and after, and writes nothing', function () {
    configured();
    $file = ['meiliscout_export' => 1, 'sections' => ['queries' => ['max_total_hits' => 20000, 'http_timeout' => 10]]];

    $plan = Transfer::plan($file);
    $rows = array_column($plan->sections['queries']->rows, null, 'field');

    expect($rows['max_total_hits'])->toMatchArray(['before' => 5000, 'after' => 20000, 'status' => SectionPlan::CHANGED])
        ->and($rows['http_timeout']['status'])->toBe(SectionPlan::UNCHANGED)
        ->and(Settings::get('max_total_hits'))->toBe(5000);
});

test('a setting the file does not mention is left as it is', function () {
    configured();
    Transfer::apply(Transfer::plan(['meiliscout_export' => 1, 'sections' => ['indexing' => ['posts_per_page' => 50]]]));

    expect(Settings::get('indexing.posts_per_page'))->toBe(50)
        ->and(Settings::get('indexed_post_types'))->toBe(['post', 'project'])
        ->and(Settings::get('realtime_indexing'))->toBe('async');
});

test('only what a section names is read, anything else is reported and never written', function () {
    $plan = Transfer::plan(['meiliscout_export' => 1, 'sections' => [
        'queries' => ['max_total_hits' => 3000, 'meili_key' => 'planted'],
        'rogue' => ['anything' => 1],
    ]]);

    Transfer::apply($plan);

    expect(Settings::get('meili_key'))->toBeNull()
        ->and(Settings::get('max_total_hits'))->toBe(3000)
        ->and($plan->sections['queries']->warnings)->toHaveCount(1)
        ->and($plan->warnings)->toHaveCount(1)
        ->and($plan->sections)->not->toHaveKey('rogue');
});

test('a value of the wrong type is an error, and the section is not applied', function () {
    $plan = Transfer::plan(['meiliscout_export' => 1, 'sections' => ['indexing' => [
        'post_types' => 'post',
        'realtime_indexing' => 'sometimes',
        'batch_size' => -3,
    ]]]);

    expect($plan->sections['indexing']->errors)->toHaveCount(3)
        ->and($plan->sections['indexing']->status())->toBe(SectionPlan::ERROR)
        ->and(Transfer::apply($plan))->toBe([])
        ->and(Settings::get('indexed_post_types'))->toBeNull();
});

test('a post type this site does not register is kept, with a warning', function () {
    $plan = Transfer::plan(['meiliscout_export' => 1, 'sections' => ['indexing' => ['post_types' => ['post', 'event']]]]);
    $GLOBALS['portability_post_types'] = ['post'];
    $plan = Transfer::plan(['meiliscout_export' => 1, 'sections' => ['indexing' => ['post_types' => ['post', 'event']]]]);

    expect($plan->sections['indexing']->errors)->toBe([])
        ->and($plan->sections['indexing']->warnings)->toHaveCount(1)
        ->and($plan->sections['indexing']->warnings[0])->toContain('event');

    Transfer::apply($plan);

    expect(Settings::get('indexed_post_types'))->toBe(['post', 'event']);
});

test('strict turns a warning into a refusal', function () {
    $GLOBALS['portability_post_types'] = ['post'];
    $file = ['meiliscout_export' => 1, 'sections' => ['indexing' => ['post_types' => ['event']]]];

    expect(Transfer::plan($file)->applicable())->toBeTrue()
        ->and(Transfer::plan($file, ['strict' => true])->applicable())->toBeFalse();
});

test('a field is searched once its meta key is imported with it', function () {
    $file = ['meiliscout_export' => 1, 'sections' => ['indexing' => [
        'meta_keys' => ['_price'],
        'searchable_attributes' => ['post_title', 'metas._price', 'metas.unknown'],
    ]]];

    $plan = Transfer::plan($file);

    expect($plan->sections['indexing']->data['searchable_attributes'])->toBe(['post_title', 'metas._price'])
        ->and($plan->sections['indexing']->warnings)->toHaveCount(1);

    Transfer::apply($plan);

    expect(Settings::get('searchable_attributes'))->toBe(['post_title', 'metas._price']);
});

test('the index is out of date when what is indexed changes, not for a timeout', function () {
    configured();

    $structure = Transfer::plan(['meiliscout_export' => 1, 'sections' => ['indexing' => ['meta_keys' => ['_price']]]]);
    $tuning = Transfer::plan(['meiliscout_export' => 1, 'sections' => ['indexing' => ['batch_size' => 100], 'queries' => ['http_timeout' => 30]]]);
    $order = Transfer::plan(['meiliscout_export' => 1, 'sections' => ['indexing' => ['searchable_attributes' => ['metas._price', 'post_title']]]]);

    expect($structure->reindex())->toBeTrue()
        ->and($tuning->reindex())->toBeFalse()
        ->and($tuning->changes())->toBeTrue()
        ->and($order->reindex())->toBeTrue();
});

test('the real-time mode an environment variable sets is not changed', function () {
    putenv('MEILISCOUT_ASYNC_INDEXING=true');

    $plan = Transfer::plan(['meiliscout_export' => 1, 'sections' => ['indexing' => ['realtime_indexing' => 'off']]]);
    $row = $plan->sections['indexing']->rows[0];
    Transfer::apply($plan);

    expect($row['status'])->toBe(SectionPlan::WARNING)
        ->and(Settings::get('realtime_indexing'))->toBeNull();
});

test('sections can be chosen when planning and when applying', function () {
    $file = ['meiliscout_export' => 1, 'sections' => [
        'queries' => ['max_total_hits' => 3000],
        'indexing' => ['posts_per_page' => 50],
    ]];

    $planned = Transfer::plan($file, ['only' => ['queries']]);
    expect(array_keys($planned->sections))->toBe(['queries']);

    $all = Transfer::plan($file);
    expect(Transfer::apply($all, ['indexing']))->toBe(['indexing'])
        ->and(Settings::get('max_total_hits'))->toBeNull()
        ->and(Settings::get('indexing.posts_per_page'))->toBe(50);
});

test('the front listings need their WordPress to be turned on by a file', function () {
    $GLOBALS['wp_version'] = '6.5';

    $plan = Transfer::plan(['meiliscout_export' => 1, 'sections' => ['listings' => ['enabled' => true, 'styles' => false]]]);
    Transfer::apply($plan);

    expect(Settings::get('listings_enabled'))->toBeNull()
        ->and(Settings::get('listings_styles'))->toBeFalse();
});

test('a color that is neither hex nor a preset is dropped with a warning', function () {
    $plan = Transfer::plan(['meiliscout_export' => 1, 'sections' => ['listings' => ['colors' => [
        'accent' => 'red', 'text' => '#111', 'background' => 'preset:base', 'shadow' => '#000',
    ]]]]);

    expect($plan->sections['listings']->data['colors'])->toBe(['text' => '#111', 'background' => 'preset:base'])
        ->and($plan->sections['listings']->warnings)->toHaveCount(2);
});

test('the public URL of Meilisearch is imported on request only', function () {
    $file = ['meiliscout_export' => 1, 'sections' => ['listings' => ['environment' => ['public_host' => 'https://search.example.com/']]]];

    $skipped = Transfer::plan($file);
    Transfer::apply($skipped);
    expect(Settings::get('listings_public_host'))->toBeNull()
        ->and($skipped->hasWarnings())->toBeTrue();

    Transfer::apply(Transfer::plan($file, ['include_env' => true]));
    expect(Settings::get('listings_public_host'))->toBe('https://search.example.com');
});

test('a file that is not an export is refused with a reason', function (string $text, string $reason) {
    try {
        Transfer::parse($text);
        $this->fail('it should have been refused');
    } catch (ImportException $e) {
        expect($e->reason)->toBe($reason);
    }
})->with([
    'not JSON' => ['hello', 'not_an_export'],
    'JSON of something else' => ['{"a":1}', 'not_an_export'],
    'a newer format' => ['{"meiliscout_export":99,"sections":{}}', 'newer_format'],
    'no sections' => ['{"meiliscout_export":1}', 'empty'],
]);

test('a file over 1 MB is refused before it is read', function () {
    try {
        Transfer::parse(str_repeat(' ', Transfer::MAX_BYTES + 1));
        $this->fail('it should have been refused');
    } catch (ImportException $e) {
        expect($e->reason)->toBe('too_large');
    }
});

test('the guard knows what a secret is called', function () {
    expect(SecretGuard::offending(['a' => ['search_key' => 'x'], 'b' => ['api-token' => 'x'], 'password' => 'x', 'listings_key' => 'x']))
        ->toBe(['a.search_key', 'b.api-token', 'password', 'listings_key'])
        // The meta keys a site indexes are not secrets
        ->and(SecretGuard::offending(['meta_keys' => ['_price'], 'term_meta_keys' => [], 'public_host' => 'x', 'max_total_hits' => 1]))->toBe([]);
});

test('an export that would carry a secret is stopped', function () {
    add_filter_for_test('meiliscout/config_sections', [new class implements \Pollora\MeiliScout\Config\Portability\Section {
        public function key(): string { return 'leaky'; }
        public function label(): string { return 'Leaky'; }
        public function summary(): string { return ''; }
        public function unavailable(): ?string { return null; }
        public function export(bool $includeEnvironment): array { return ['api_token' => 'secret']; }
        public function plan(array $data, array $options = []): SectionPlan { return new SectionPlan('leaky'); }
        public function apply(SectionPlan $plan): void {}
    }]);

    expect(fn () => Transfer::export())->toThrow(\LogicException::class);
});

/**
 * The suite's apply_filters answers from $GLOBALS['filters'].
 *
 * @param  mixed  $value
 */
function add_filter_for_test(string $hook, $value): void
{
    $GLOBALS['filters'][$hook] = $value;
}
