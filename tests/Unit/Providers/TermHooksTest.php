<?php

declare(strict_types=1);

namespace {
    if (! function_exists('add_action')) {
        function add_action($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['actions'][$hook][] = ['callback' => $callback, 'priority' => $priority]; return true; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Providers {

    use Pollora\MeiliScout\Providers\SingleIndexingServiceProvider;
    use ReflectionMethod;

    function termHooks(): array
    {
        $GLOBALS['actions'] = [];
        $provider = new SingleIndexingServiceProvider;
        (new ReflectionMethod($provider, 'registerTaxonomyHooks'))->invoke($provider);

        return $GLOBALS['actions'];
    }

    test('an edited term is indexed at its own priority, after the plugins that rewrite its posts on the default one', function () {
        $defaultPriority = 10;
        [$termEdit] = termHooks()['edited_term'];

        expect($termEdit['priority'])->toBe(SingleIndexingServiceProvider::EDITED_TERM_PRIORITY);
        expect(SingleIndexingServiceProvider::EDITED_TERM_PRIORITY)->toBeGreaterThan($defaultPriority);
    });
}
