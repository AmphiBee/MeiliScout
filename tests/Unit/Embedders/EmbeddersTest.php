<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Embedders {

    use Meilisearch\Endpoints\Indexes;
    use Pollora\MeiliScout\Config\Portability\Sections\EmbeddersSection;
    use Pollora\MeiliScout\Config\Portability\SectionPlan;
    use Pollora\MeiliScout\Embedders\Embedders;
    use Pollora\MeiliScout\Services\IndexSettings;

    require_once __DIR__.'/../Config/Portability/wordpress.php';

    const MISTRAL_KEY_ENV = 'MEILISCOUT_TEST_MISTRAL_KEY';

    /**
     * @return array<string, mixed>
     */
    function mistral(array $overrides = []): array
    {
        return [
            'source' => 'rest',
            'url' => 'https://api.mistral.ai/v1/embeddings',
            'apiKey' => MISTRAL_KEY_ENV,
            'dimensions' => 1024,
            'request' => ['model' => 'mistral-embed', 'input' => ['{{text}}', '{{..}}']],
            'response' => ['data' => [['embedding' => '{{embedding}}'], '{{..}}']],
            'headers' => ['X-Secret' => 'never exported'],
            'documentTemplate' => '{{ doc.post_title }}',
            ...$overrides,
        ];
    }

    /**
     * An index whose updateSettings() calls are kept, in order.
     */
    function recordingIndex($test, array &$pushed): Indexes
    {
        $index = (fn () => $this->createMock(Indexes::class))->call($test);
        $index->method('updateSettings')->willReturnCallback(function (array $settings) use (&$pushed) {
            $pushed[] = $settings;

            return [];
        });

        return $index;
    }

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        putenv(MISTRAL_KEY_ENV.'=first-key');
        $this->errorLog = ini_set('error_log', '/dev/null');
    });

    afterEach(function () {
        putenv(MISTRAL_KEY_ENV);
        ini_set('error_log', (string) $this->errorLog);
    });

    test('a declared embedder is pushed with the index settings, its API key read from the environment', function () {
        Embedders::declare('mistral', mistral(), ['posts', 'manual_chunks']);
        $pushed = [];

        IndexSettings::push(recordingIndex($this, $pushed), 'example_test_posts', ['searchableAttributes' => ['post_title']]);
        IndexSettings::push(recordingIndex($this, $pushed), 'example_test_manual_chunks__rebuild', []);
        IndexSettings::push(recordingIndex($this, $pushed), 'example_test_taxonomies', ['searchableAttributes' => ['name']]);

        expect($pushed[0]['embedders']['mistral'])->toMatchArray(['source' => 'rest', 'apiKey' => 'first-key', 'dimensions' => 1024])
            ->and($pushed[0]['searchableAttributes'])->toBe(['post_title'])
            ->and($pushed[1]['embedders'])->toHaveKey('mistral')
            ->and($pushed[2])->toBe(['searchableAttributes' => ['name']]);
    });

    test('the API key may be a callable or a constant', function () {
        define('MEILISCOUT_TEST_EMBEDDER_KEY', 'constant-key');
        Embedders::declare('from_callable', mistral(['apiKey' => fn () => 'callable-key']));
        Embedders::declare('from_constant', mistral(['apiKey' => 'MEILISCOUT_TEST_EMBEDDER_KEY']));
        $pushed = [];

        IndexSettings::push(recordingIndex($this, $pushed), 'example_test_posts', []);

        expect($pushed[0]['embedders']['from_callable']['apiKey'])->toBe('callable-key')
            ->and($pushed[0]['embedders']['from_constant']['apiKey'])->toBe('constant-key');
    });

    test('a key that cannot be read is left out, for Meilisearch to keep the one it has', function () {
        Embedders::declare('mistral', mistral(['apiKey' => 'MEILISCOUT_TEST_UNDEFINED_KEY']));
        $pushed = [];

        IndexSettings::push(recordingIndex($this, $pushed), 'example_test_posts', []);

        expect($pushed[0]['embedders']['mistral'])->not->toHaveKey('apiKey');
    });

    test('a callable failing to give the key is a key that cannot be read, and the settings are still pushed', function () {
        Embedders::declare('mistral', mistral(['apiKey' => fn () => throw new \RuntimeException('The vault is sealed.')]));
        $pushed = [];

        IndexSettings::push(recordingIndex($this, $pushed), 'example_test_posts', ['searchableAttributes' => ['post_title']]);

        expect($pushed[0]['embedders']['mistral'])->toHaveKey('source')
            ->and($pushed[0]['embedders']['mistral'])->not->toHaveKey('apiKey')
            ->and($pushed[0]['searchableAttributes'])->toBe(['post_title']);
    });

    test('a new API key does not push the settings again, a new model does', function () {
        Embedders::declare('mistral', mistral());
        $pushed = [];
        $index = recordingIndex($this, $pushed);

        expect(IndexSettings::pushIfChanged($index, 'example_test_posts', []))->toBeTrue();

        putenv(MISTRAL_KEY_ENV.'=second-key');
        expect(IndexSettings::pushIfChanged($index, 'example_test_posts', []))->toBeFalse();

        Embedders::declare('mistral', mistral(['request' => ['model' => 'mistral-embed-2', 'input' => ['{{text}}', '{{..}}']]]));
        expect(IndexSettings::pushIfChanged($index, 'example_test_posts', []))->toBeTrue()
            ->and($pushed[1]['embedders']['mistral']['apiKey'])->toBe('second-key');
    });

    test('no API key is kept in an option', function () {
        Embedders::declare('mistral', mistral());
        $pushed = [];

        IndexSettings::push(recordingIndex($this, $pushed), 'example_test_posts', []);

        expect(serialize($GLOBALS['wp_options']))->not->toContain('first-key');
    });

    test('settings without embedders keep the fingerprint they had', function () {
        $pushed = [];
        $index = recordingIndex($this, $pushed);
        $GLOBALS['wp_options']['meiliscout/index_settings_hash/example_test_posts'] = md5((string) json_encode(['sortableAttributes' => ['post_date']]));

        expect(IndexSettings::pushIfChanged($index, 'example_test_posts', ['sortableAttributes' => ['post_date']]))->toBeFalse();
    });

    test('the embedders an indexable sets itself win over the declared ones', function () {
        Embedders::declare('mistral', mistral());
        $pushed = [];

        IndexSettings::push(recordingIndex($this, $pushed), 'example_test_manual_chunks', ['embedders' => ['mistral' => ['source' => 'userProvided', 'dimensions' => 3]]]);
        IndexSettings::push(recordingIndex($this, $pushed), 'example_test_posts', []);

        expect($pushed[0]['embedders']['mistral'])->toBe(['source' => 'userProvided', 'dimensions' => 3])
            ->and($pushed[1]['embedders']['mistral']['source'])->toBe('rest');
    });

    test('the meiliscout/embedders filter has the last word', function () {
        Embedders::declare('mistral', mistral());
        $GLOBALS['filters']['meiliscout/embedders'] = ['manual_chunks' => ['local' => ['source' => 'userProvided', 'dimensions' => 3]]];

        expect(Embedders::forIndex('posts'))->toBe([])
            ->and(Embedders::forIndex('manual_chunks'))->toHaveKey('local');
    });

    test('the meiliscout/index_settings filter adds a setting no indexable sets', function () {
        $GLOBALS['filters']['meiliscout/index_settings'] = ['searchCutOffMs' => 1500];
        $pushed = [];

        IndexSettings::push(recordingIndex($this, $pushed), 'example_test_posts', ['searchableAttributes' => ['post_title']]);

        expect($pushed[0])->toBe(['searchCutOffMs' => 1500]);
    });

    test('an embedder needs a valid name and a source', function (string $name, array $config) {
        expect(fn () => Embedders::declare($name, $config))->toThrow(\InvalidArgumentException::class);
    })->with([
        'no name' => ['', ['source' => 'rest']],
        'a space in the name' => ['mistral embed', ['source' => 'rest']],
        'no source' => ['mistral', ['url' => 'https://api.mistral.ai']],
    ]);

    test('the export names the embedders, their source, model and template, never their key nor headers', function () {
        Embedders::declare('mistral', mistral(), ['posts', 'manual_chunks']);
        $section = new EmbeddersSection;

        $export = $section->export(true);

        expect($section->unavailable())->toBeNull()
            ->and(array_keys($export))->toBe(['posts', 'manual_chunks'])
            ->and($export['posts']['mistral'])->toMatchArray(['source' => 'rest', 'documentTemplate' => '{{ doc.post_title }}'])
            ->and($export['posts']['mistral']['request']['model'])->toBe('mistral-embed')
            ->and($export['posts']['mistral'])->not->toHaveKeys(['apiKey', 'headers'])
            ->and(json_encode($export))->not->toContain('first-key');
    });

    test('without embedders the export leaves the section out', function () {
        expect((new EmbeddersSection)->unavailable())->not->toBeNull();
    });

    test('an import writes no embedder, and says where the file and the site differ', function () {
        Embedders::declare('mistral', mistral());
        $section = new EmbeddersSection;

        $same = $section->plan($section->export(false));
        $other = $section->plan(['posts' => ['openai' => ['source' => 'openAi']]]);

        expect($same->warnings)->toBe([])
            ->and($same->rows[0]['status'])->toBe(SectionPlan::UNCHANGED)
            ->and($other->warnings)->toHaveCount(1)
            ->and($other->rows[0]['status'])->toBe(SectionPlan::WARNING);
    });

    test('the model is read where the settings put it', function () {
        expect(Embedders::model(mistral()))->toBe('mistral-embed')
            ->and(Embedders::model(['source' => 'openAi', 'model' => 'text-embedding-3-small']))->toBe('text-embedding-3-small')
            ->and(Embedders::model(['source' => 'userProvided']))->toBe('');
    });
}
