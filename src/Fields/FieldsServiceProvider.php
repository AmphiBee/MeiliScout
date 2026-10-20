<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Fields;

use Pollora\MeiliScout\Foundation\ServiceProvider;

/**
 * Named custom fields. The function themes call always exists, connected or not.
 */
final class FieldsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        require_once __DIR__.'/functions.php';
    }
}
