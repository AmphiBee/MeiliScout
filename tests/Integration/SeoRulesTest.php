<?php

declare(strict_types=1);

use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\ListingsServiceProvider;
use Pollora\MeiliScout\Listings\Seo\RuleKey;
use Pollora\MeiliScout\Listings\Seo\RulesCsv;
use Pollora\MeiliScout\Listings\Seo\SeoRule;
use Pollora\MeiliScout\Listings\Seo\SeoRules;
use Pollora\MeiliScout\Listings\Seo\SeoRulesService;

/*
 * The SEO rules' table on the demo site: resolution by key and language, the
 * CSV, the preview of a URL. A listing of its own, declared for the test.
 */

beforeEach(function () {
    if (ListingsServiceProvider::unavailable() !== null || ! taxonomy_exists('project_type')) {
        $this->markTestSkipped('Needs the listings module on and the demo\'s project_type taxonomy.');
    }

    $this->id = 'seo-test-'.strtolower(wp_generate_password(6, false, false));
    $this->page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SEO rules test', 'post_name' => $this->id]);
    DefinitionRegistry::declare($this->id, [
        'post_types' => ['realisation'],
        'route' => ['page' => $this->page],
        'facets' => ['type' => ['source' => 'taxonomy:project_type'], 'secteur' => ['source' => 'meta:client_sector']],
    ]);
    SeoRules::install();
    $this->terms = get_terms(['taxonomy' => 'project_type', 'number' => 2, 'hide_empty' => false, 'orderby' => 'term_id', 'use_meilisearch' => false]);
});

afterEach(function () {
    if (isset($this->page)) {
        wp_delete_post($this->page, true);
    }

    foreach (isset($this->id) ? SeoRules::all($this->id) : [] as $rule) {
        SeoRules::delete((int) $rule->id);
    }
});

test('the table exists once installed', function () {
    global $wpdb;

    expect(SeoRules::installed())->toBeTrue()
        ->and($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', SeoRules::table())))->toBe(SeoRules::table());
});

test('the most specific key wins, the view\'s language before every language', function () {
    [$a] = $this->terms;
    $keys = RuleKey::candidates(['type' => $a->term_id]);

    SeoRules::save(new SeoRule($this->id, '', 'type=*', 0, 'Any type'));
    expect(SeoRules::find($this->id, 'fr_FR', $keys)?->title)->toBe('Any type');

    SeoRules::save(new SeoRule($this->id, '', 'type='.$a->term_id, 2, 'This type, every language'));
    expect(SeoRules::find($this->id, 'fr_FR', $keys)?->title)->toBe('This type, every language');

    SeoRules::save(new SeoRule($this->id, 'fr_FR', 'type='.$a->term_id, 2, 'This type in French'));
    expect(SeoRules::find($this->id, 'fr_FR', $keys)?->title)->toBe('This type in French')
        ->and(SeoRules::find($this->id, 'en_US', $keys)?->title)->toBe('This type, every language')
        ->and(SeoRules::find($this->id, 'fr_FR', [''])?->title)->toBeNull();
});

test('a rule of the same listing, language and key is replaced', function () {
    SeoRulesService::save(['listing' => $this->id, 'key' => '', 'title' => 'First']);
    $replaced = SeoRulesService::save(['listing' => $this->id, 'key' => '', 'title' => 'Second']);

    expect($replaced)->toBeTrue()
        ->and(SeoRules::all($this->id))->toHaveCount(1)
        ->and(SeoRules::all($this->id)[0]->title)->toBe('Second');
});

test('a rule needs a field, a known listing and a key its facets allow', function (array $input, string $message) {
    // The messages as written, whatever the site's language
    switch_to_locale('en_US');

    try {
        expect(fn () => SeoRulesService::save(['listing' => $this->id] + $input))->toThrow(InvalidArgumentException::class, $message);
    } finally {
        restore_previous_locale();
    }
})->with([
    'no field' => [['key' => ''], 'at least one field'],
    'meta facet' => [['key' => 'secteur=Culture', 'title' => 'x'], 'no taxonomy facet'],
    'unknown term' => [['key' => 'type=no-such-term', 'title' => 'x'], 'No term'],
    'bad locale' => [['key' => '', 'locale' => 'French', 'title' => 'x'], 'not a locale'],
]);

test('the CSV goes back and forth, terms by slug accepted', function () {
    [$a] = $this->terms;
    $csv = "listing,key,title,description,faq\n"
        ."{$this->id},type={$a->slug},\"Type, {type}\",Desc,\"[{\"\"question\"\":\"\"Q?\"\",\"\"answer\"\":\"\"A.\"\"}]\"\n"
        ."{$this->id},type=no-such-term,X,,\n";

    $report = RulesCsv::import($csv);
    $rules = SeoRules::all($this->id);

    expect($report['created'])->toBe(1)
        ->and($report['errors'])->toHaveCount(1)
        ->and($report['errors'][0]['line'])->toBe(3)
        ->and($rules)->toHaveCount(1)
        ->and($rules[0]->key)->toBe('type='.$a->term_id)
        ->and($rules[0]->faq)->toBe([['question' => 'Q?', 'answer' => 'A.']]);

    // Exported, deleted, imported again: the same rule
    $exported = RulesCsv::export($rules);
    SeoRules::delete((int) $rules[0]->id);
    $again = RulesCsv::import($exported);

    expect($again)->toBe(['created' => 1, 'updated' => 0, 'errors' => []])
        ->and(SeoRules::all($this->id)[0]->fields())->toBe($rules[0]->fields())
        ->and($exported)->toContain($a->name);
});

test('the preview of a URL: the view and its rule', function () {
    [$a] = $this->terms;
    SeoRulesService::save(['listing' => $this->id, 'key' => 'type=*', 'title' => '{type} projects']);
    $base = Listings::baseUrl(DefinitionRegistry::get($this->id));

    $plain = SeoRulesService::preview($base);
    $filtered = SeoRulesService::preview((string) wp_parse_url($base, PHP_URL_PATH).'page/2/?type='.$a->slug);

    expect($plain['listing'])->toBe($this->id)
        ->and($plain['indexable'])->toBeTrue()
        ->and($plain['canonical'])->toBe($base)
        ->and($plain['robots'])->toBe('index, follow')
        ->and($plain['title'])->toBe('')
        ->and($plain['structured_data'][0]['@type'])->toBe('ItemList')
        ->and($filtered['indexable'])->toBeFalse()
        ->and($filtered['reason'])->toBe('filters')
        ->and($filtered['robots'])->toBe('noindex, follow')
        ->and($filtered['rule_key'])->toBe('type=*')
        ->and($filtered['title'])->toStartWith($a->name.' projects')
        ->and(fn () => SeoRulesService::preview('/no-listing-here/'))->toThrow(InvalidArgumentException::class);
});
