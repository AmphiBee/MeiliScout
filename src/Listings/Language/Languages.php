<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Language;

/**
 * The language adapter of the site: Polylang, WPML, or none.
 */
final class Languages
{
    private static ?LanguageAdapter $adapter = null;

    public static function adapter(): LanguageAdapter
    {
        if (self::$adapter === null) {
            $adapter = match (true) {
                Polylang::active() => new Polylang,
                Wpml::active() => new Wpml,
                default => new NoLanguage,
            };

            /**
             * Filters the multilingual plugin's adapter listings use.
             *
             * @param  LanguageAdapter  $adapter  Polylang's, WPML's, or none.
             */
            /** @var mixed $filtered */
            $filtered = apply_filters('meiliscout/listings/language_adapter', $adapter);
            self::$adapter = $filtered instanceof LanguageAdapter ? $filtered : $adapter;
        }

        return self::$adapter;
    }

    public static function current(): string
    {
        return self::adapter()->current();
    }

    /**
     * Sets the adapter (tests), null to detect it again.
     */
    public static function use(?LanguageAdapter $adapter): void
    {
        self::$adapter = $adapter;
    }

    public static function boot(): void
    {
        add_action('init', fn () => self::adapter()->boot(), 1);
    }
}
