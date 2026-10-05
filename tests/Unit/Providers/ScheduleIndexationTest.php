<?php

declare(strict_types=1);

namespace {
    if (! function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook, $args = []) {
            foreach ($GLOBALS['cron'] ?? [] as $event) {
                if ($event['hook'] === $hook && $event['args'] === $args) {
                    return $event['timestamp'];
                }
            }

            return false;
        }
    }

    if (! function_exists('wp_schedule_single_event')) {
        function wp_schedule_single_event($timestamp, $hook, $args = []) {
            $GLOBALS['cron'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args];

            return true;
        }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Providers {

    use Pollora\MeiliScout\Providers\Admin\IndexationServiceProvider;

    beforeEach(function () {
        $GLOBALS['cron'] = [];
    });

    test('a full indexation is scheduled in place, without emptying the indexes', function () {
        new IndexationServiceProvider()->scheduleIndexation();

        expect($GLOBALS['cron'])->toHaveCount(1);
        expect($GLOBALS['cron'][0]['hook'])->toBe('meiliscout_process_indexation');
        expect($GLOBALS['cron'][0]['args'])->toBe([['clear_indices' => false, 'index_posts' => true, 'index_taxonomies' => true]]);
    });

    test('a full indexation already waiting is not scheduled again', function () {
        new IndexationServiceProvider()->scheduleIndexation();
        new IndexationServiceProvider()->scheduleIndexation();

        expect($GLOBALS['cron'])->toHaveCount(1);
    });

    test('nothing is scheduled while indexing is skipped', function () {
        $GLOBALS['filters']['meiliscout/skip_indexing'] = true;

        new IndexationServiceProvider()->scheduleIndexation();

        expect($GLOBALS['cron'])->toBe([]);
    });
}
