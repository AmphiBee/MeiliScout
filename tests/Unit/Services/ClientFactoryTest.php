<?php

declare(strict_types=1);

namespace {
    if (! function_exists('get_transient')) {
        function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
    }
    if (! function_exists('set_transient')) {
        function set_transient($key, $value, $ttl = 0) { $GLOBALS['transients'][$key] = $value; return true; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Services {

    use Pollora\MeiliScout\Services\ClientFactory;

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        $GLOBALS['transients'] = [];
        foreach (['MEILI_HOST', 'MEILI_KEY', 'MEILI_SEARCH_KEY'] as $name) {
            putenv($name);
        }
        ClientFactory::reset();
        $this->errorLog = ini_set('error_log', '/dev/null');
    });

    /**
     * The Meilisearch SDK needs a PSR-18 HTTP client, which the plugin does not
     * ship: the site has to provide one.
     */
    function skipWithoutHttpClient(): void
    {
        try {
            \Http\Discovery\Psr18ClientDiscovery::find();
        } catch (\Throwable) {
            test()->markTestSkipped('No PSR-18 HTTP client installed.');
        }
    }

    afterEach(function () {
        ini_set('error_log', (string) $this->errorLog);
    });

    test('nothing is configured until a host and an admin key are set', function () {
        expect(ClientFactory::isConfigured())->toBeFalse();

        update_option('meiliscout/meili_host', 'http://localhost:7700');
        expect(ClientFactory::isConfigured())->toBeFalse();

        update_option('meiliscout/meili_key', 'admin-key');
        expect(ClientFactory::isConfigured())->toBeTrue();
    });

    test('an invalid host gives no client instead of a fatal error', function () {
        update_option('meiliscout/meili_host', 'not a url');
        update_option('meiliscout/meili_key', 'admin-key');

        expect(ClientFactory::getClient())->toBeNull()
            ->and(ClientFactory::isReachable())->toBeFalse();
    });

    test('a host that answered recently is trusted without a new call', function () {
        skipWithoutHttpClient();

        update_option('meiliscout/meili_host', 'http://127.0.0.1:7700');
        update_option('meiliscout/meili_key', 'admin-key');
        $GLOBALS['transients']['meiliscout_probe_'.md5('http://127.0.0.1:7700|admin-key')] = 'available';

        expect(ClientFactory::getClient())->not->toBeNull();
    });

    test('searches use the search key when one is set, the admin key otherwise', function () {
        skipWithoutHttpClient();

        update_option('meiliscout/meili_host', 'http://127.0.0.1:7700');
        update_option('meiliscout/meili_key', 'admin-key');
        $GLOBALS['transients']['meiliscout_probe_'.md5('http://127.0.0.1:7700|admin-key')] = 'available';
        $GLOBALS['transients']['meiliscout_probe_'.md5('http://127.0.0.1:7700|search-key')] = 'available';

        expect(ClientFactory::getReadClient())->toBe(ClientFactory::getClient());

        update_option('meiliscout/meili_search_key', 'search-key');
        ClientFactory::reset();

        expect(ClientFactory::getReadClient())->toBe(ClientFactory::getSearchClient())
            ->and(ClientFactory::getReadClient())->not->toBe(ClientFactory::getClient());
    });
}
