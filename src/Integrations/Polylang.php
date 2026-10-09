<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Integrations;

/**
 * Polylang (checked on 3.8). Its languages are a taxonomy, indexed like the
 * others: the tax_query it adds to a query on the front is translated. With
 * it, it sets the query vars taxonomy=language and term_id, which WordPress
 * does not read: term_id is declared handled, or every query of a Polylang
 * site would run on MySQL (unsupported_arg:term_id).
 */
final class Polylang
{
    public static function boot(): void
    {
        add_action('plugins_loaded', [self::class, 'register'], 20);
    }

    public static function register(): void
    {
        if (! function_exists('pll_current_language')) {
            return;
        }

        add_filter('meiliscout/supported_query_vars', fn (array $handled) => [...$handled, 'term_id']);
    }
}
