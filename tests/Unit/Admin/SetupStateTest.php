<?php

declare(strict_types=1);

use Pollora\MeiliScout\Admin\SetupState;

beforeEach(function () {
    $GLOBALS['wp_options'] = [];
});

test('the assistant has no state until it is finished or dismissed', function () {
    expect(SetupState::get())->toBeNull();

    SetupState::set(SetupState::COMPLETED);
    expect(SetupState::get())->toBe('completed');

    SetupState::set(SetupState::DISMISSED);
    expect(SetupState::get())->toBe('dismissed');

    SetupState::set(null);
    expect(SetupState::get())->toBeNull();
});

test('an unknown state is no state', function () {
    SetupState::set('anything');

    expect(SetupState::get())->toBeNull();
});

test('the checks tell what the site can run', function (string $php, string $wordpress, bool $phpOk, bool $wpOk, bool $listings) {
    $checks = SetupState::checks($php, $wordpress);

    expect($checks['php']['ok'])->toBe($phpOk)
        ->and($checks['wordpress']['ok'])->toBe($wpOk)
        ->and($checks['listings'])->toBe($listings);
})->with([
    'current' => ['8.3.1', '6.9.1', true, true, true],
    'WordPress without the router' => ['8.2.0', '6.7', true, true, false],
    'WordPress too old' => ['8.2.0', '6.4', true, false, false],
    'PHP too old' => ['8.1.9', '6.9', false, true, true],
]);
