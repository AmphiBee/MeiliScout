<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Config {

    use Pollora\MeiliScout\Config\RealtimeIndexing;
    use Pollora\MeiliScout\Providers\SingleIndexingServiceProvider;

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        $GLOBALS['actions'] = [];
        putenv('MEILISCOUT_ASYNC_INDEXING');
    });

    afterEach(function () {
        putenv('MEILISCOUT_ASYNC_INDEXING');
    });

    test('content is indexed at the end of the request by default', function () {
        expect(RealtimeIndexing::mode())->toBe(RealtimeIndexing::SHUTDOWN)
            ->and(RealtimeIndexing::isLocked())->toBeFalse();
    });

    test('the mode set in the admin is used', function () {
        RealtimeIndexing::save(RealtimeIndexing::OFF);

        expect(RealtimeIndexing::mode())->toBe(RealtimeIndexing::OFF);
    });

    test('the environment wins over the admin, and locks it', function () {
        RealtimeIndexing::save(RealtimeIndexing::OFF);
        putenv('MEILISCOUT_ASYNC_INDEXING=true');

        expect(RealtimeIndexing::mode())->toBe(RealtimeIndexing::ASYNC)
            ->and(RealtimeIndexing::isLocked())->toBeTrue();

        putenv('MEILISCOUT_ASYNC_INDEXING=false');

        expect(RealtimeIndexing::mode())->toBe(RealtimeIndexing::SHUTDOWN);
    });

    test('an unknown mode is refused', function () {
        RealtimeIndexing::save('sometimes');
    })->throws(\InvalidArgumentException::class);

    test('turned off, saving content indexes nothing, but what was queued still goes out', function () {
        RealtimeIndexing::save(RealtimeIndexing::OFF);

        (new SingleIndexingServiceProvider)->register();

        expect($GLOBALS['actions'])->not->toHaveKey('save_post')
            ->and($GLOBALS['actions'])->not->toHaveKey('edited_term')
            ->and($GLOBALS['actions'])->toHaveKey('meiliscout_process_async_queue');
    });

    test('on, saving content is hooked', function () {
        (new SingleIndexingServiceProvider)->register();

        expect($GLOBALS['actions'])->toHaveKey('save_post')
            ->and($GLOBALS['actions'])->not->toHaveKey('meiliscout_process_async_queue');
    });
}
