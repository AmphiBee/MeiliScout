<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Config {

    use Pollora\MeiliScout\Config\Config;

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        putenv('MEILISCOUT_TEST_FROM_ENV');
    });

    test('a constant defined in wp-config.php is read by its upper-case name', function () {
        define('MEILISCOUT_TEST_FROM_CONSTANT', 'http://meilisearch:7700');

        expect(Config::get('meiliscout_test_from_constant'))->toBe('http://meilisearch:7700')
            ->and(Config::isReadOnly('meiliscout_test_from_constant'))->toBeTrue();
    });

    test('an environment variable wins over the database', function () {
        putenv('MEILISCOUT_TEST_FROM_ENV=from-env');
        update_option('meiliscout/meiliscout_test_from_env', 'from-db');

        expect(Config::get('meiliscout_test_from_env'))->toBe('from-env')
            ->and(Config::isReadOnly('meiliscout_test_from_env'))->toBeTrue();
    });

    test('the database is read when nothing overrides it', function () {
        update_option('meiliscout/meiliscout_test_from_db', 'from-db');

        expect(Config::get('meiliscout_test_from_db'))->toBe('from-db')
            ->and(Config::isReadOnly('meiliscout_test_from_db'))->toBeFalse();
    });
}
