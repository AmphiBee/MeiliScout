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
use Pollora\MeiliScout\Suggest\Definition\SuggestDefinition;
use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;
use Pollora\MeiliScout\Suggest\Definition\SuggestSource;
use Pollora\MeiliScout\Suggest\Render\Plan;
use Pollora\MeiliScout\Suggest\Render\Templates;
use Pollora\MeiliScout\Suggest\Suggest;
use Pollora\MeiliScout\Suggest\Template\Hit;
use Pollora\MeiliScout\Suggest\Template\SuggestTemplate;

/*
 * How a suggestion is drawn, in every form a source's template takes: the
 * default, HTML, a callable, a class, Blade, Twig, and the browser's own.
 */

/**
 * A suggest whose first source has the given template.
 *
 * @return array{0: SuggestDefinition, 1: SuggestSource}
 */
function suggestWith(mixed $template, array $source = []): array
{
    SuggestRegistry::declare('tpl-test', ['sources' => ['posts' => ['post_types' => ['post'], 'template' => $template] + $source]]);
    $definition = SuggestRegistry::get('tpl-test');

    return [$definition, $definition->sources['posts']];
}

afterEach(function () {
    SuggestRegistry::remove('tpl-test');
    remove_all_filters('meiliscout/listings/blade');
    remove_all_filters('meiliscout/listings/twig');
    remove_all_filters('meiliscout/suggest/template');
});

test('the default suggestion is an option with an image, the title marked, and the type', function () {
    [$definition, $source] = suggestWith(null);
    $template = Templates::for($source, $definition);

    expect($template['js'])->toBeNull()
        ->and($template['markup'])->toContain('role="option"')
        ->toContain('data-wp-bind--href="context.hit.url"')
        ->toContain('data-wp-bind--src="context.hit.image"')
        ->toContain('data-wp-each--part="context.hit.titleParts"')
        ->toContain('data-wp-text="context.hit.meta"')
        ->and($template['fields'])->toBe(['ID', 'post_title', 'url', 'post_type', 'thumbnail']);
});

test('HTML is bound as it is, and wrapped in an option when it leaves the bindings out', function () {
    [$definition, $source] = suggestWith('<p class="mine"><b data-wp-text="context.hit.title"></b></p>');
    $markup = Templates::for($source, $definition)['markup'];

    expect($markup)->toStartWith('<div class="meiliscout-suggest__option" role="option"')
        ->toContain('<p class="mine">')
        ->toEndWith('</div>');

    [$definition, $source] = suggestWith('<a class="own" '.(new Hit)->option().'>x</a>');
    expect(Templates::for($source, $definition)['markup'])->toStartWith('<a class="own"');
});

test('a callable and a class get the source, the field and the hit binder', function () {
    [$definition, $source] = suggestWith(fn (SuggestSource $s, SuggestDefinition $d, Hit $hit) => '<a '.$hit->option().' '.$hit->href().'><em '.$hit->text('title').'></em> '.$s->key.'/'.$d->id.'</a>');
    $markup = Templates::for($source, $definition)['markup'];

    expect($markup)->toContain('data-wp-bind--href="context.hit.url"')
        ->toContain('data-wp-text="context.hit.title"')
        ->toContain('posts/tpl-test');

    $class = new class implements SuggestTemplate
    {
        public function markup(SuggestSource $source, SuggestDefinition $definition, Hit $hit): string
        {
            return '<a '.$hit->option().$hit->href().'><span'.$hit->text('doc.post_date').'></span></a>';
        }
    };
    [$definition, $source] = suggestWith($class, ['fields' => ['post_date']]);
    $template = Templates::for($source, $definition);

    expect($template['markup'])->toContain('context.hit.doc.post_date')
        ->and($template['fields'])->toContain('post_date');
});

test('a template binds only what a suggestion holds, and only attributes that are not handlers', function () {
    $hit = new Hit;

    expect((string) $hit->text('metas.price'))->toBe(' data-wp-text="context.hit.metas.price"')
        ->and((string) $hit->show('image'))->toBe(' data-wp-bind--hidden="!context.hit.image"')
        ->and((string) $hit->toggle('is-new', 'count'))->toBe(' data-wp-class--is-new="context.hit.count"')
        ->and(fn () => $hit->text('post_content'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $hit->text('title" onclick="x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $hit->attr('onclick', 'title'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $hit->toggle('a b', 'title'))->toThrow(InvalidArgumentException::class);
});

test('a template that fails falls back to the default suggestion', function () {
    [$definition, $source] = suggestWith(fn () => throw new RuntimeException('broken'));

    expect(Templates::for($source, $definition)['markup'])->toContain('data-wp-each--part="context.hit.titleParts"');
});

test('Blade views are rendered once with the binder', function () {
    $views = sys_get_temp_dir().'/meiliscout-suggest-views-'.getmypid();
    @mkdir($views, 0777, true);
    file_put_contents($views.'/hit.blade.php', '<a {!! $hit->option() !!} {!! $hit->href() !!}><strong {{ $hit->text(\'title\') }}></strong> from {{ $suggest->id }}/{{ $source->key }}</a>');

    $files = new Filesystem;
    $cache = sys_get_temp_dir().'/meiliscout-suggest-blade-'.getmypid();
    $files->ensureDirectoryExists($cache);
    $compiler = new BladeCompiler($files, $cache);
    $resolver = new EngineResolver;
    $resolver->register('blade', fn () => new CompilerEngine($compiler, $files));
    $factory = new Factory($resolver, new FileViewFinder($files, [$views]), new Dispatcher(new Container));
    $factory->setContainer(new Container);
    add_filter('meiliscout/listings/blade', fn () => $factory);

    [$definition, $source] = suggestWith('blade:hit');
    $markup = Templates::for($source, $definition)['markup'];

    expect($markup)->toStartWith('<a  role="option"')
        ->toContain('<strong  data-wp-text="context.hit.title"></strong>')
        ->toContain('from tpl-test/posts');
});

test('Twig templates are rendered once with the binder, printed as it is', function () {
    $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
        'hit.twig' => '<a {{ hit.option() }} {{ hit.href() }}><b {{ hit.text("title") }}></b> {{ suggest.id }}</a>',
    ]), ['autoescape' => 'html']);
    add_filter('meiliscout/listings/twig', fn () => $twig);

    [$definition, $source] = suggestWith('twig:hit.twig');
    $markup = Templates::for($source, $definition)['markup'];

    expect($markup)->toStartWith('<a  role="option"')
        ->toContain('<b  data-wp-text="context.hit.title"></b> tpl-test</a>')
        ->not->toContain('&quot;');
});

test('a template of the browser leaves no markup, and names itself in the field', function () {
    [$definition, $source] = suggestWith('js:my-hit', ['fields' => ['post_excerpt']]);
    $template = Templates::for($source, $definition);

    expect($template['js'])->toBe('my-hit')
        ->and($template['markup'])->toBe('')
        ->and($template['fields'])->toContain('post_excerpt');

    $html = Suggest::render('tpl-test');

    expect($html)->toContain('data-template="my-hit"')
        ->toContain('data-wp-watch="callbacks.renderGroup"')
        ->not->toContain('data-wp-each--hit="context.groups.0.hits"');

    expect(Plan::forDefinition($definition)['sources'][0]['search']['attributesToRetrieve'])->toContain('post_excerpt');
});

test('a filter has the last word on a compiled template', function () {
    add_filter('meiliscout/suggest/template', fn (array $compiled) => ['markup' => '<i role="option" data-wp-bind--id="context.hit.optionId"></i>', 'fields' => ['ID']] + $compiled);
    [$definition, $source] = suggestWith(null);

    expect(Templates::for($source, $definition)['markup'])->toBe('<i role="option" data-wp-bind--id="context.hit.optionId"></i>');
});
