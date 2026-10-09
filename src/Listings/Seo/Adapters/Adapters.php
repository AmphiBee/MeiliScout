<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo\Adapters;

/**
 * The adapter of the SEO plugin in use: Yoast, Rank Math, SEOPress, All in
 * One SEO, else WordPress alone (the first one active).
 */
final class Adapters
{
    /**
     * @var list<class-string<Adapter>>
     */
    public const ADAPTERS = [YoastAdapter::class, RankMathAdapter::class, SeoPressAdapter::class, AioseoAdapter::class, CoreAdapter::class];

    private static ?Adapter $hooked = null;

    public static function current(): Adapter
    {
        $adapter = null;
        foreach (self::ADAPTERS as $class) {
            if ($class::active()) {
                $adapter = new $class;
                break;
            }
        }

        /**
         * Filters the adapter telling the SEO plugin in use what a listing's
         * view is: another plugin's (an Adapter of your own), or WordPress's.
         *
         * @param  Adapter  $adapter  The first active of Yoast, Rank Math, SEOPress, All in One SEO, WordPress.
         */
        /** @var mixed $filtered */
        $filtered = apply_filters('meiliscout/listings/seo_adapter', $adapter ?? new CoreAdapter);

        return $filtered instanceof Adapter ? $filtered : new CoreAdapter;
    }

    /**
     * Hooks the current adapter, once per request.
     */
    public static function hook(): void
    {
        if (self::$hooked === null) {
            self::$hooked = self::current();
            self::$hooked->hook();
        }
    }
}
