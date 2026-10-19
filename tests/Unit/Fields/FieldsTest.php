<?php

declare(strict_types=1);

namespace {
    // The WordPress and plugin functions the providers read, answering from what a test sets
    if (! function_exists('__')) {
        function __($text, $domain = 'default') { return $text; }
    }
    if (! function_exists('acf_get_field_groups')) {
        function acf_get_field_groups() { return $GLOBALS['acf_groups'] ?? []; }
    }
    if (! function_exists('acf_get_fields')) {
        function acf_get_fields($group) { return $GLOBALS['acf_fields'][$group['key']] ?? []; }
    }
    if (! function_exists('rwmb_get_registry')) {
        function rwmb_get_registry($name)
        {
            return new class
            {
                public function get_by_object_type($type) { return $GLOBALS['rwmb_fields'] ?? []; }
            };
        }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Fields {

    use Pollora\MeiliScout\Fields\FieldProvider;
    use Pollora\MeiliScout\Fields\FieldRegistry;
    use Pollora\MeiliScout\Fields\MetaField;
    use Pollora\MeiliScout\Fields\Providers\AcfProvider;
    use Pollora\MeiliScout\Fields\Providers\MetaBoxProvider;
    use Pollora\MeiliScout\Fields\Providers\WooCommerceProvider;
    use Pollora\MeiliScout\Services\MetaKeyCatalog;

    // What a test left in the registry must not reach the next one
    afterEach(function () {
        FieldRegistry::flush(true);
        $GLOBALS['acf_groups'] = $GLOBALS['acf_fields'] = $GLOBALS['rwmb_fields'] = [];
    });

    /**
     * A provider with fixed fields.
     *
     * @param  list<MetaField>  $fields
     */
    function fakeProvider(array $fields, bool $available = true, bool $throws = false): FieldProvider
    {
        return new class($fields, $available, $throws) implements FieldProvider
        {
            public function __construct(private array $fields, private bool $available, private bool $throws) {}

            public function available(): bool { return $this->available; }

            public function fields(): array
            {
                if ($this->throws) {
                    throw new \RuntimeException('broken plugin');
                }

                return $this->fields;
            }
        };
    }

    // ---- MetaField

    test('a field is made from an array, and only from a usable one', function () {
        $field = MetaField::from(['key' => 'budget', 'label' => 'Project › Budget', 'type' => 'number', 'post_types' => ['project', 3]]);

        expect($field)->toBeInstanceOf(MetaField::class)
            ->and($field->key)->toBe('budget')
            ->and($field->label)->toBe('Project › Budget')
            ->and($field->type)->toBe('number')
            ->and($field->postTypes)->toBe(['project'])
            ->and($field->source)->toBe('custom');

        expect(MetaField::from(['label' => 'No key']))->toBeNull()
            ->and(MetaField::from(['key' => '  ']))->toBeNull()
            ->and(MetaField::from(['key' => 7]))->toBeNull();
    });

    test('a field without a name is called by its key, and an unknown type is not kept', function () {
        $field = MetaField::from(['key' => 'budget', 'type' => 'money']);

        expect($field->label)->toBe('budget')->and($field->type)->toBeNull();
    });

    test('the short label is the name without where it sits', function () {
        expect((new MetaField('b', 'Project › Contact › Email', 'G', 'acf'))->shortLabel())->toBe('Email')
            ->and((new MetaField('b', 'Budget', 'G', 'acf'))->shortLabel())->toBe('Budget');
    });

    test('a field applies to its post types, or to any when it names none', function () {
        $project = new MetaField('b', 'Budget', 'G', 'acf', postTypes: ['project']);
        $any = new MetaField('s', 'Subtitle', 'G', 'acf');

        expect($project->appliesTo(['project', 'post']))->toBeTrue()
            ->and($project->appliesTo(['post']))->toBeFalse()
            ->and($project->appliesTo([]))->toBeTrue()
            ->and($any->appliesTo(['post']))->toBeTrue();
    });

    // ---- FieldRegistry

    test('the registry gathers the providers\' fields, skipping a broken or inactive one', function () {
        $GLOBALS['filters']['meiliscout/meta_field_providers'] = [
            fakeProvider([new MetaField('a', 'A', 'G', 'one')]),
            fakeProvider([new MetaField('b', 'B', 'G', 'two')], available: false),
            fakeProvider([new MetaField('c', 'C', 'G', 'three')], throws: true),
            'not a provider',
        ];

        // The broken provider is logged, not thrown
        $previous = ini_set('error_log', '/dev/null');
        $keys = array_keys(FieldRegistry::all());
        ini_set('error_log', (string) $previous);

        expect($keys)->toBe(['a']);
    });

    test('the first provider to name a key keeps it, and a declared field wins', function () {
        $GLOBALS['filters']['meiliscout/meta_field_providers'] = [
            fakeProvider([new MetaField('price', 'First', 'G', 'one')]),
            fakeProvider([new MetaField('price', 'Second', 'G', 'two')]),
        ];

        expect(FieldRegistry::label('price'))->toBe('First');

        FieldRegistry::declare('price', ['label' => 'Theme › Price', 'type' => 'number']);

        expect(FieldRegistry::label('price'))->toBe('Theme › Price')
            ->and(FieldRegistry::get('price')->type)->toBe('number');
    });

    test('the meta_fields filter can add and shape fields, with arrays or instances', function () {
        $GLOBALS['filters']['meiliscout/meta_field_providers'] = [];
        $GLOBALS['filters']['meiliscout/meta_fields'] = [
            ['key' => 'from_array', 'label' => 'Array'],
            new MetaField('from_object', 'Object', 'G', 'x'),
            ['label' => 'No key, ignored'],
            'rubbish',
        ];

        expect(array_keys(FieldRegistry::all()))->toBe(['from_array', 'from_object']);
    });

    test('a key without a name is called by itself', function () {
        $GLOBALS['filters']['meiliscout/meta_field_providers'] = [];

        expect(FieldRegistry::label('_unknown'))->toBe('_unknown')->and(FieldRegistry::get('_unknown'))->toBeNull();
    });

    test('fields are searched by name, key or group, on some post types, from one source', function () {
        $GLOBALS['filters']['meiliscout/meta_field_providers'] = [fakeProvider([
            new MetaField('budget', 'Project › Budget', 'Advanced Custom Fields', 'acf', postTypes: ['project']),
            new MetaField('_price', 'WooCommerce › Price', 'WooCommerce', 'woocommerce', postTypes: ['product']),
            new MetaField('subtitle', 'Subtitle', 'Meta Box', 'metabox'),
        ])];
        $keys = static fn (array $fields) => array_map(static fn (MetaField $field) => $field->key, $fields);

        expect($keys(FieldRegistry::search('')))->toBe(['budget', '_price', 'subtitle'])
            ->and($keys(FieldRegistry::search('BUDG')))->toBe(['budget'])
            ->and($keys(FieldRegistry::search('woo')))->toBe(['_price'])
            ->and($keys(FieldRegistry::search('', ['project'])))->toBe(['budget', 'subtitle'])
            ->and($keys(FieldRegistry::search('', [], ['budget'])))->toBe(['_price', 'subtitle'])
            ->and($keys(FieldRegistry::search('', [], [], 'metabox')))->toBe(['subtitle']);
    });

    test('the sources that have fields on some post types are counted', function () {
        $GLOBALS['filters']['meiliscout/meta_field_providers'] = [fakeProvider([
            new MetaField('a', 'A', 'Advanced Custom Fields', 'acf', postTypes: ['project']),
            new MetaField('b', 'B', 'Advanced Custom Fields', 'acf'),
            new MetaField('c', 'C', 'WooCommerce', 'woocommerce', postTypes: ['product']),
        ])];

        expect(FieldRegistry::sources(['project']))->toBe([['source' => 'acf', 'group' => 'Advanced Custom Fields', 'count' => 2]])
            ->and(count(FieldRegistry::sources([])))->toBe(2);
    });

    test('the keys that only accompany a field are known', function () {
        $GLOBALS['filters']['meiliscout/meta_field_providers'] = [fakeProvider([
            new MetaField('budget', 'Budget', 'G', 'acf', companionKeys: ['_budget']),
        ])];

        expect(FieldRegistry::companionKeys())->toBe(['_budget' => true]);
    });

    // ---- ACF

    /**
     * @return array<string, mixed>
     */
    function acfGroup(string $key, string $title, array $location): array
    {
        return ['key' => $key, 'title' => $title, 'location' => $location];
    }

    test('ACF fields are named after their group, and fields of a group field keep its prefix', function () {
        $GLOBALS['acf_groups'] = [acfGroup('g1', 'Project', [[['param' => 'post_type', 'operator' => '==', 'value' => 'project']]])];
        $GLOBALS['acf_fields']['g1'] = [
            ['key' => 'f1', 'label' => 'Budget', 'name' => 'budget', 'type' => 'number'],
            ['key' => 'f2', 'label' => 'Contact', 'name' => 'contact', 'type' => 'group', 'sub_fields' => [
                ['key' => 'f3', 'label' => 'Email', 'name' => 'email', 'type' => 'email'],
                ['key' => 'f4', 'label' => 'Address', 'name' => 'address', 'type' => 'group', 'sub_fields' => [
                    ['key' => 'f5', 'label' => 'City', 'name' => 'city', 'type' => 'text'],
                ]],
            ]],
        ];

        $fields = (new AcfProvider)->fields();
        $byKey = array_column(array_map(static fn (MetaField $field) => ['key' => $field->key, 'field' => $field], $fields), 'field', 'key');

        expect(array_keys($byKey))->toBe(['budget', 'contact_email', 'contact_address_city'])
            ->and($byKey['budget']->label)->toBe('Project › Budget')
            ->and($byKey['contact_email']->label)->toBe('Project › Contact › Email')
            ->and($byKey['contact_address_city']->label)->toBe('Project › Contact › Address › City')
            ->and($byKey['budget']->group)->toBe('Advanced Custom Fields')
            ->and($byKey['budget']->source)->toBe('acf')
            ->and($byKey['budget']->postTypes)->toBe(['project'])
            ->and($byKey['budget']->type)->toBe(MetaKeyCatalog::TYPE_NUMBER)
            ->and($byKey['budget']->companionKeys)->toBe(['_budget']);
    });

    test('ACF fields that store nothing a key can name are left out', function () {
        $GLOBALS['acf_groups'] = [acfGroup('g1', 'Project', [[['param' => 'post_type', 'operator' => '==', 'value' => 'project']]])];
        $GLOBALS['acf_fields']['g1'] = [
            ['key' => 'f1', 'label' => 'Details', 'name' => 'details', 'type' => 'tab'],
            ['key' => 'f2', 'label' => 'Steps', 'name' => 'steps', 'type' => 'repeater', 'sub_fields' => [['key' => 'f3', 'label' => 'Title', 'name' => 'title', 'type' => 'text']]],
            ['key' => 'f4', 'label' => 'Blocks', 'name' => 'blocks', 'type' => 'flexible_content'],
            ['key' => 'f5', 'label' => 'Note', 'name' => 'note', 'type' => 'message'],
            ['key' => 'f6', 'label' => 'Kept', 'name' => 'kept', 'type' => 'text'],
            ['key' => 'f7', 'label' => 'No name', 'name' => '', 'type' => 'text'],
        ];

        expect(array_map(static fn (MetaField $field) => $field->key, (new AcfProvider)->fields()))->toBe(['kept']);
    });

    test('an ACF type tells what the meta stores', function (string $type, array $field, ?string $expected) {
        expect(AcfProvider::typeOf($type, $field))->toBe($expected);
    })->with([
        'number' => ['number', [], MetaKeyCatalog::TYPE_NUMBER],
        'range' => ['range', [], MetaKeyCatalog::TYPE_NUMBER],
        'date' => ['date_picker', [], MetaKeyCatalog::TYPE_DATE],
        'flag' => ['true_false', [], MetaKeyCatalog::TYPE_BOOLEAN],
        'checkboxes' => ['checkbox', [], MetaKeyCatalog::TYPE_LIST],
        'a select' => ['select', [], MetaKeyCatalog::TYPE_TEXT],
        'a multiple select' => ['select', ['multiple' => 1], MetaKeyCatalog::TYPE_LIST],
        'text' => ['wysiwyg', [], MetaKeyCatalog::TYPE_TEXT],
        'something new' => ['future_type', [], null],
    ]);

    test('the post types of an ACF group come from its location rules', function (array $location, ?array $expected) {
        expect(AcfProvider::postTypes($location))->toBe($expected);
    })->with([
        'one type' => [[[['param' => 'post_type', 'operator' => '==', 'value' => 'project']]], ['project']],
        'two types, as alternatives' => [[
            [['param' => 'post_type', 'operator' => '==', 'value' => 'project']],
            [['param' => 'post_type', 'operator' => '==', 'value' => 'page']],
        ], ['project', 'page']],
        'a type and a template' => [[[
            ['param' => 'post_type', 'operator' => '==', 'value' => 'page'],
            ['param' => 'page_template', 'operator' => '==', 'value' => 'wide.php'],
        ]], ['page']],
        'a category, which says no type: any' => [[[['param' => 'post_category', 'operator' => '==', 'value' => 'news']]], []],
        'a type and a category as alternatives: any' => [[
            [['param' => 'post_type', 'operator' => '==', 'value' => 'project']],
            [['param' => 'post_category', 'operator' => '==', 'value' => 'news']],
        ], []],
        'an options page only: none' => [[[['param' => 'options_page', 'operator' => '==', 'value' => 'settings']]], null],
        'a taxonomy term only: none' => [[[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]], null],
        'a type and an options page' => [[
            [['param' => 'post_type', 'operator' => '==', 'value' => 'project']],
            [['param' => 'options_page', 'operator' => '==', 'value' => 'settings']],
        ], ['project']],
        'no location' => [[], null],
    ]);

    test('an ACF group shown on options pages only gives no field', function () {
        $GLOBALS['acf_groups'] = [acfGroup('g1', 'Settings', [[['param' => 'options_page', 'operator' => '==', 'value' => 'settings']]])];
        $GLOBALS['acf_fields']['g1'] = [['key' => 'f1', 'label' => 'Slogan', 'name' => 'slogan', 'type' => 'text']];

        expect((new AcfProvider)->fields())->toBe([]);
    });

    // ---- Meta Box

    test('Meta Box fields are named, with the post types of their meta box', function () {
        $GLOBALS['rwmb_fields'] = [
            'project' => [
                'budget' => ['id' => 'budget', 'name' => 'Budget', 'type' => 'number'],
                'intro' => ['id' => 'intro', 'name' => 'Intro', 'type' => 'heading'],
                'photos' => ['id' => 'photos', 'name' => 'Photos', 'type' => 'image_advanced'],
            ],
            'page' => [
                'budget' => ['id' => 'budget', 'name' => 'Budget', 'type' => 'number'],
            ],
        ];

        $fields = array_column(array_map(static fn (MetaField $field) => ['key' => $field->key, 'field' => $field], (new MetaBoxProvider)->fields()), 'field', 'key');

        expect(array_keys($fields))->toBe(['budget', 'photos'])
            ->and($fields['budget']->label)->toBe('Budget')
            ->and($fields['budget']->group)->toBe('Meta Box')
            ->and($fields['budget']->postTypes)->toBe(['project', 'page'])
            ->and($fields['budget']->type)->toBe(MetaKeyCatalog::TYPE_NUMBER)
            ->and($fields['photos']->type)->toBe(MetaKeyCatalog::TYPE_LIST);
    });

    test('a Meta Box field kept in a custom table cannot be indexed from a meta key', function () {
        $GLOBALS['rwmb_fields'] = ['project' => [
            'a' => ['id' => 'a', 'name' => 'In the postmeta', 'type' => 'text'],
            'b' => ['id' => 'b', 'name' => 'In a table', 'type' => 'text', 'storage' => new class {}],
        ]];

        expect(array_map(static fn (MetaField $field) => $field->key, (new MetaBoxProvider)->fields()))->toBe(['a']);
    });

    test('a Meta Box type tells what the meta stores', function (string $type, array $field, ?string $expected) {
        expect(MetaBoxProvider::typeOf($type, $field))->toBe($expected);
    })->with([
        'number' => ['number', [], MetaKeyCatalog::TYPE_NUMBER],
        'date' => ['date', [], MetaKeyCatalog::TYPE_DATE],
        'a date stored as a timestamp' => ['date', ['timestamp' => true], MetaKeyCatalog::TYPE_NUMBER],
        'a switch' => ['switch', [], MetaKeyCatalog::TYPE_BOOLEAN],
        'a multiple select' => ['select_advanced', ['multiple' => true], MetaKeyCatalog::TYPE_LIST],
        'a select' => ['select', [], MetaKeyCatalog::TYPE_TEXT],
        'unknown' => ['future', [], null],
    ]);

    // ---- WooCommerce

    test('WooCommerce fields are named, numbers and flags typed, on the product type', function () {
        $fields = array_column(array_map(static fn (MetaField $field) => ['key' => $field->key, 'field' => $field], (new WooCommerceProvider)->fields()), 'field', 'key');

        expect($fields)->toHaveKeys(['_price', '_sku', '_stock_status', 'total_sales', '_virtual'])
            ->and($fields['_price']->label)->toBe('WooCommerce › Price')
            ->and($fields['_price']->type)->toBe(MetaKeyCatalog::TYPE_NUMBER)
            ->and($fields['_virtual']->type)->toBe(MetaKeyCatalog::TYPE_BOOLEAN)
            ->and($fields['_sku']->type)->toBe(MetaKeyCatalog::TYPE_TEXT)
            ->and($fields['_price']->postTypes)->toBe(['product'])
            ->and($fields['_price']->source)->toBe('woocommerce');
    });

    test('WooCommerce fields are only offered when WooCommerce is there', function () {
        // The suite never loads WooCommerce
        expect((new WooCommerceProvider)->available())->toBe(class_exists('WooCommerce'));
    });

    test('the helper declares a field', function () {
        require_once dirname(__DIR__, 3).'/src/Fields/functions.php';
        $GLOBALS['filters']['meiliscout/meta_field_providers'] = [];

        meiliscout_register_meta_field('budget', ['label' => 'Studio › Budget', 'group' => 'Studio', 'type' => 'number', 'post_types' => ['project']]);

        $field = FieldRegistry::get('budget');

        expect($field->label)->toBe('Studio › Budget')
            ->and($field->group)->toBe('Studio')
            ->and($field->postTypes)->toBe(['project']);
    });
}
