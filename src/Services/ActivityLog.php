<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use function get_option;
use function update_option;

/**
 * The last indexing operations, for the admin: full indexations and real-time tasks.
 *
 * Entries are kept in memory and written once, by flush(): a request saving
 * a post runs several tasks, and should not write the option once per task.
 * A failed real-time task keeps the task, so that the admin can run it again.
 */
final class ActivityLog
{
    private const OPTION = 'meiliscout/activity';

    /**
     * Entries kept, newest first.
     */
    public const MAX_ENTRIES = 100;

    /**
     * Entries recorded during this request, not written yet.
     *
     * @var list<array<string, mixed>>
     */
    private static array $pending = [];

    /**
     * Records a real-time task.
     *
     * @param  array{type: string, action: string, id: int, extra: array<string, mixed>}  $task
     */
    public static function recordTask(array $task, string $label, bool $succeeded, int $items, float $durationMs, ?string $error = null): void
    {
        self::$pending[] = [
            'kind' => 'realtime',
            'operation' => $task['type'].'.'.$task['action'],
            'label' => $label,
            'status' => $succeeded ? 'success' : 'error',
            'items' => $items,
            'duration_ms' => (int) round($durationMs),
            'error' => $error,
            'task' => $succeeded ? null : $task,
        ];
    }

    /**
     * Records a full indexation, and writes the log at once: it ends a long run.
     *
     * @param  'update'|'rebuild'|'chunk'  $mode
     * @param  list<string>  $indexes
     */
    public static function recordFullIndexation(string $mode, bool $succeeded, int $items, float $durationMs, array $indexes, ?string $error = null): void
    {
        self::$pending[] = [
            'kind' => 'full',
            'operation' => 'full.'.$mode,
            'label' => '',
            'status' => $succeeded ? 'success' : 'error',
            'items' => $items,
            'duration_ms' => (int) round($durationMs),
            'indexes' => $indexes,
            'error' => $error,
            'task' => null,
        ];

        self::flush();
    }

    /**
     * Writes the entries recorded during this request.
     */
    public static function flush(): void
    {
        if (self::$pending === []) {
            return;
        }

        $time = time();
        $entries = self::all();

        foreach (self::$pending as $entry) {
            array_unshift($entries, ['id' => self::newId(), 'time' => $time, ...$entry]);
        }

        self::$pending = [];

        // Not autoloaded: only the admin reads it
        update_option(self::OPTION, array_slice($entries, 0, self::MAX_ENTRIES), false);
    }

    /**
     * The entries, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $entries = get_option(self::OPTION, []);

        return is_array($entries) ? array_values($entries) : [];
    }

    /**
     * An entry by id, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $entry) {
            if (($entry['id'] ?? null) === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Marks a failed entry as retried, so that it cannot be run twice.
     */
    public static function markRetried(string $id): void
    {
        $entries = array_map(
            static fn (array $entry) => ($entry['id'] ?? null) === $id ? [...$entry, 'task' => null, 'retried' => true] : $entry,
            self::all()
        );

        update_option(self::OPTION, $entries, false);
    }

    /**
     * Forgets the entries recorded during this request and not written. For tests.
     */
    public static function reset(): void
    {
        self::$pending = [];
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(6));
    }
}
