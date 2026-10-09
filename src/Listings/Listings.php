<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings;

/**
 * The module's entry points, once it runs.
 */
final class Listings
{
    public static function boot(): void {}

    /**
     * @param  array<string, mixed>  $args
     */
    public static function render(string $id, array $args = []): string
    {
        return '';
    }
}
