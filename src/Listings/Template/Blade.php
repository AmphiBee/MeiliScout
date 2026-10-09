<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Template;

/**
 * The listings' Blade components, under the meiliscout namespace:
 *
 *     <x-meiliscout::listing id="projects" />
 *     <x-meiliscout::facet listing="projects" facet="type" />
 *     <x-meiliscout::part listing="projects" part="sort" />
 *     <x-meiliscout::active-filters listing="projects" />
 *     <x-meiliscout::results listing="projects" />
 *     <x-meiliscout::pagination listing="projects" />
 *
 * Registered on init when the site runs Laravel's Blade (Pollora, Acorn);
 * register() takes any other Blade compiler.
 */
final class Blade
{
    public const NAMESPACE = 'meiliscout';

    public static function boot(): void
    {
        add_action('init', [self::class, 'registerApplication'], 1);
    }

    /**
     * The application's Blade compiler, when there is one.
     */
    public static function registerApplication(): void
    {
        if (! function_exists('app')) {
            return;
        }

        try {
            $app = app();
            if (is_object($app) && method_exists($app, 'bound') && $app->bound('blade.compiler')) {
                self::register($app->make('blade.compiler'));
            }
        } catch (\Throwable $e) {
            error_log('MeiliScout: could not register the Blade components: '.$e->getMessage());
        }
    }

    public static function register(\Illuminate\View\Compilers\BladeCompiler $compiler): void
    {
        $compiler->anonymousComponentPath(self::path(), self::NAMESPACE);
    }

    public static function path(): string
    {
        return dirname(__DIR__, 3).'/templates/blade/components';
    }
}
