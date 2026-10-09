<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Template;

/**
 * The template engines a site has: Blade (Laravel, Pollora, Acorn), Twig
 * (Timber, or one the site hands over). Looked up when a card needs them.
 */
final class Engines
{
    /**
     * Blade's view factory: the application's, else the one the site gives.
     */
    public static function blade(): ?\Illuminate\Contracts\View\Factory
    {
        $factory = null;

        if (function_exists('app')) {
            try {
                $app = app();
                $factory = is_object($app) && method_exists($app, 'bound') && $app->bound('view') ? $app->make('view') : null;
            } catch (\Throwable) {
                $factory = null;
            }
        }

        /**
         * Filters the Blade view factory listings render their Blade cards with.
         *
         * @param  \Illuminate\Contracts\View\Factory|null  $factory  The application's, when there is one.
         */
        $factory = apply_filters('meiliscout/listings/blade', $factory);

        return $factory instanceof \Illuminate\Contracts\View\Factory ? $factory : null;
    }

    /**
     * Renders a Twig template: with Timber when it is there, else with the
     * environment the site gives.
     *
     * @param  array<string, mixed>  $context
     */
    public static function twig(string $template, array $context): ?string
    {
        /**
         * Filters the Twig environment listings render their Twig cards with.
         * Without one, Timber's (Timber::compile()) when Timber is active.
         *
         * @param  \Twig\Environment|null  $twig
         */
        $twig = apply_filters('meiliscout/listings/twig', null);

        if ($twig instanceof \Twig\Environment) {
            return $twig->render($template, $context);
        }

        if (class_exists(\Timber\Timber::class)) {
            // Timber 2's post object, as its templates expect
            $getPost = 'Timber\\Timber::get_post';
            if (isset($context['post']) && $context['post'] instanceof \WP_Post && is_callable($getPost)) {
                $context['post'] = $getPost($context['post']);
            }
            $html = \Timber\Timber::compile($template, $context);

            return is_string($html) ? $html : null;
        }

        return null;
    }
}
