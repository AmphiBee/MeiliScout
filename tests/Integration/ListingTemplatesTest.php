<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\ListingsServiceProvider;
use Pollora\MeiliScout\Listings\Render\CardRenderer;
use Pollora\MeiliScout\Listings\Render\Renderer;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Query\ListingQuery;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Listings\Template\Blade;
use Pollora\MeiliScout\Listings\Template\TwigExtension;

/*
 * The developer mode on the demo site: parts printed one by one, the cards a
 * definition gives (PHP, Blade, Twig, a renderer), Blade components and Twig
 * functions. Needs the module on and a post type with a taxonomy indexed:
 * realisation and project_type on the demo.
 */

function templatesListing(string $id, array $args = []): void
{
    DefinitionRegistry::declare($id, $args + [
        'post_types' => ['realisation'],
        'per_page' => 3,
        'facets' => [
            'type' => ['source' => 'taxonomy:project_type', 'label' => 'Type'],
            'prix' => ['source' => 'meta:_price', 'type' => 'range'],
        ],
        'sorts' => [
            'recent' => ['label' => 'Recent', 'orderby' => 'date', 'order' => 'DESC'],
            'title' => ['label' => 'Title', 'orderby' => 'title', 'order' => 'ASC'],
        ],
    ]);
}

/**
 * The listing's region alone, as the fragment endpoint renders it.
 */
function templatesRegion(string $id): string
{
    $definition = DefinitionRegistry::get($id);

    return Renderer::region(ListingQuery::run($definition, new ListingState));
}

/**
 * A Blade view factory reading the given directory, compiled to a temporary one.
 */
function templatesBlade(string $views): Factory
{
    $files = new Filesystem;
    $cache = sys_get_temp_dir().'/meiliscout-blade-'.getmypid();
    $files->ensureDirectoryExists($cache);

    $compiler = new BladeCompiler($files, $cache);
    $resolver = new EngineResolver;
    $resolver->register('blade', fn () => new CompilerEngine($compiler, $files));
    $factory = new Factory($resolver, new FileViewFinder($files, [$views]), new Dispatcher(new Container));
    $container = new Container;
    $container->instance(\Illuminate\Contracts\View\Factory::class, $factory);
    $container->instance('view', $factory);
    // Blade looks for a component class in the application's namespace first: a Laravel application has one
    $container->instance(\Illuminate\Contracts\Foundation\Application::class, new class
    {
        public function getNamespace(): string
        {
            return 'App\\';
        }
    });
    $factory->setContainer($container);
    Container::setInstance($container);
    Blade::register($compiler);

    return $factory;
}

beforeEach(function () {
    if (ListingsServiceProvider::unavailable() !== null) {
        $this->markTestSkipped('The listings module is off on this site.');
    }

    Listings::forget();
});

afterEach(function () {
    remove_all_filters('meiliscout/listings/blade');
    remove_all_filters('meiliscout/listings/twig');
});

test('parts printed apart share one form, which every field names', function () {
    templatesListing('templates-parts');

    $facet = meiliscout_get_listing_part('templates-parts', 'facet', ['facet' => 'type']);
    $sort = meiliscout_get_listing_part('templates-parts', 'sort');
    $results = meiliscout_get_listing_part('templates-parts', 'results');
    $html = $facet.$sort.$results;

    expect(substr_count($html, '<form '))->toBe(1)
        ->and($facet)->toContain('id="meiliscout-listing-templates-parts-form"')
        ->and($facet)->toContain('data-wp-init="callbacks.init"')
        ->and(preg_match_all('/<input[^>]+name="type"[^>]*form="meiliscout-listing-templates-parts-form"/', $facet))->toBeGreaterThan(0)
        ->and($sort)->toContain('form="meiliscout-listing-templates-parts-form"')
        ->and($sort)->toContain('data-meiliscout-part="sort"')
        ->and($results)->toContain('data-wp-router-region="meiliscout-listing-templates-parts"')
        ->and($results)->toContain('class="meiliscout-result"');
});

test('a part that does not exist says so to those who can fix it', function () {
    templatesListing('templates-unknown');
    wp_set_current_user((int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 0));

    expect(meiliscout_get_listing_part('templates-unknown', 'facet', ['facet' => 'nope']))->toContain('has no facet &quot;nope&quot;')
        ->and(meiliscout_get_listing_part('templates-unknown', 'carousel'))->toContain('Unknown listing part');

    wp_set_current_user(0);
});

test('the whole listing has one form, its results and its pagination', function () {
    templatesListing('templates-whole');

    $html = meiliscout_get_listing('templates-whole');

    expect(substr_count($html, '<form '))->toBe(1)
        ->and($html)->toContain('data-meiliscout="listing"')
        ->and($html)->toContain('data-meiliscout="results"')
        ->and($html)->toContain('data-meiliscout="pagination"')
        ->and($html)->toContain('meiliscout-pagination__next');
});

test('the fragment renders the cards the definition gives, as the page does', function () {
    templatesListing('templates-callable', ['card' => fn (WP_Post $post) => '<b class="mine">'.esc_html($post->post_name).'</b>']);
    templatesListing('templates-renderer', ['card' => new class implements CardRenderer
    {
        public function render(WP_Post $post, ListingDefinition $definition): string
        {
            return '<i class="renderer">'.$definition->id.'</i>';
        }
    }]);

    expect(templatesRegion('templates-callable'))->toContain('<b class="mine">')
        ->and(templatesRegion('templates-renderer'))->toContain('<i class="renderer">templates-renderer</i>');
});

test('a Blade card, and the Blade components', function () {
    $views = sys_get_temp_dir().'/meiliscout-views-'.getmypid();
    @mkdir($views);
    file_put_contents($views.'/card.blade.php', '<p class="blade-card">{{ $post->post_name }} · {{ $listing->id }}</p>');
    file_put_contents($views.'/page.blade.php', '<x-meiliscout::facet listing="templates-blade" facet="type" /><x-meiliscout::results listing="templates-blade" />');

    $factory = templatesBlade($views);
    add_filter('meiliscout/listings/blade', fn () => $factory);
    templatesListing('templates-blade', ['card' => 'blade:card']);

    $html = $factory->make('page')->render();

    expect($html)->toContain('<p class="blade-card">')
        ->and($html)->toContain('· templates-blade</p>')
        ->and($html)->toContain('data-meiliscout-part="facet"')
        ->and(substr_count($html, '<form '))->toBe(1);
});

test('a Twig card, and the Twig functions', function () {
    $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
        'card.twig' => '<p class="twig-card">{{ post.post_name }} · {{ listing.id }}</p>',
        'page.twig' => "{{ meiliscout_facet('templates-twig', 'type') }}{{ meiliscout_listing_results('templates-twig') }}{{ meiliscout_pagination('templates-twig') }}",
    ]));
    TwigExtension::addTo($twig);
    add_filter('meiliscout/listings/twig', fn () => $twig);
    templatesListing('templates-twig', ['card' => 'twig:card.twig']);

    $html = $twig->render('page.twig');

    expect($html)->toContain('<p class="twig-card">')
        ->and($html)->toContain('· templates-twig</p>')
        ->and($html)->toContain('data-meiliscout="pagination"')
        // Not escaped by Twig
        ->and($html)->toContain('<fieldset class="meiliscout-facet');
});

test('a card that names an engine the site does not have falls back to the default card', function () {
    templatesListing('templates-no-engine', ['card' => 'blade:card']);

    expect(templatesRegion('templates-no-engine'))->toContain('class="meiliscout-card"');
});

test('a definition refuses a card it cannot render', function () {
    templatesListing('templates-bad-card', ['card' => 42, 'client_card' => '']);

    expect(fn () => DefinitionRegistry::get('templates-bad-card'))->toThrow(\Pollora\MeiliScout\Listings\Definition\InvalidListing::class);
});
