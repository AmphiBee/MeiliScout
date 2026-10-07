<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Admin\Rest;

use Pollora\MeiliScout\Providers\Admin\IndexationServiceProvider;
use Pollora\MeiliScout\Services\ActivityLog;
use Pollora\MeiliScout\Services\Indexer;
use Pollora\MeiliScout\Services\IndexingLogger;
use Pollora\MeiliScout\Services\IndexingTask;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\PostSingleIndexer;
use Pollora\MeiliScout\Services\TaxonomySingleIndexer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function _get_cron_array;
use function spawn_cron;
use function wp_schedule_single_event;

/**
 * Full indexations, their progress, and the activity log.
 */
final class IndexationController extends Controller
{
    public const MODES = ['update', 'rebuild'];

    /**
     * A run whose log was not written for this long is taken as dead.
     */
    private const STALLED_AFTER = 300;

    /**
     * Log lines the app shows under the progress bar.
     */
    private const LOG_TAIL = 8;

    public function registerRoutes(): void
    {
        $this->route('/indexation', 'GET', [$this, 'show']);
        $this->route('/indexation', 'POST', [$this, 'start'], [
            'mode' => ['type' => 'string', 'enum' => self::MODES, 'default' => 'update'],
        ]);
        $this->route('/activity', 'GET', [$this, 'activity']);
        $this->route('/activity/(?P<id>[a-f0-9]{12})/retry', 'POST', [$this, 'retry']);
        $this->route('/legacy-indexes/delete', 'POST', [$this, 'deleteLegacyIndexes']);
    }

    public function show(): WP_REST_Response
    {
        $indexer = new Indexer;

        return $this->respond([
            'totals' => ['posts' => $indexer->countPosts(), 'terms' => $indexer->countTerms()],
            'run' => self::currentRun(),
        ]);
    }

    /**
     * Schedules a full indexation on WP-Cron, and wakes WP-Cron up.
     */
    public function start(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (is_wp_error($client = $this->client())) {
            return $client;
        }

        if (in_array(self::currentRun()['status'], ['waiting', 'running'], true)) {
            return new WP_Error('meiliscout_indexation_running', __('An indexation is already running.', 'meiliscout'), ['status' => 409]);
        }

        $rebuild = $request->get_param('mode') === 'rebuild';

        // The app follows this log until the run opens its own
        $logger = IndexingLogger::getInstance();
        $logger->initializeSession();
        $logger->log('info', 'Indexation will start soon...');
        $logger->progress(['mode' => $rebuild ? 'rebuild' : 'update', 'indexes' => []]);

        wp_schedule_single_event(time(), IndexationServiceProvider::PROCESS_INDEXATION, [[
            'clear_indices' => $rebuild,
            'index_posts' => true,
            'index_taxonomies' => true,
        ]]);
        spawn_cron();

        return $this->respond(self::currentRun());
    }

    public function activity(WP_REST_Request $request): WP_REST_Response
    {
        $kind = $request->get_param('kind');
        $status = $request->get_param('status');
        $limit = max(1, min(ActivityLog::MAX_ENTRIES, (int) ($request->get_param('limit') ?: 50)));

        $entries = array_filter(
            ActivityLog::all(),
            static fn (array $entry) => (! is_string($kind) || $kind === '' || $entry['kind'] === $kind)
                && (! is_string($status) || $status === '' || $entry['status'] === $status)
        );

        return $this->respond(array_map([self::class, 'present'], array_slice(array_values($entries), 0, $limit)));
    }

    /**
     * Runs a failed real-time task again, now.
     */
    public function retry(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $entry = ActivityLog::find((string) $request->get_param('id'));

        if ($entry === null || ! is_array($entry['task'] ?? null)) {
            return new WP_Error('meiliscout_not_retryable', __('This operation cannot be run again.', 'meiliscout'), ['status' => 404]);
        }

        if (is_wp_error($client = $this->client())) {
            return $client;
        }

        ActivityLog::markRetried($entry['id']);

        try {
            (new IndexingTask(new PostSingleIndexer, new TaxonomySingleIndexer))->run($entry['task']);
        } catch (\Throwable) {
            // Recorded in the log, which the app shows
        }

        ActivityLog::flush();

        return $this->respond(self::present(ActivityLog::all()[0]));
    }

    public function deleteLegacyIndexes(): WP_REST_Response|WP_Error
    {
        if (IndexNames::migrationPending()) {
            return new WP_Error('meiliscout_migration_pending', __('Searches still read the previous indexes.', 'meiliscout'), ['status' => 409]);
        }

        try {
            $deleted = (new Indexer)->deleteLegacyIndexes();
        } catch (\Throwable $e) {
            return new WP_Error('meiliscout_delete_failed', $e->getMessage(), ['status' => 502]);
        }

        return $this->respond(['deleted' => $deleted]);
    }

    /**
     * Where the last full indexation is, as the app shows it.
     *
     * @return array<string, mixed>
     */
    public static function currentRun(): array
    {
        $log = IndexingLogger::getInstance()->getCurrentLog();
        $scheduled = self::isScheduled();

        if ($log === null) {
            return ['status' => $scheduled ? 'waiting' : 'idle'];
        }

        $progress = $log['progress'] ?? [];
        $started = ($progress['indexes'] ?? []) !== [];
        $lastWrite = strtotime((string) ($log['end_time'] ?? $log['start_time'] ?? '')) ?: 0;
        $entries = (array) ($log['entries'] ?? []);

        $status = match ($log['status'] ?? 'pending') {
            'completed' => 'completed',
            'error' => 'error',
            // current_time('mysql') is local time, as the log's dates
            default => current_time('timestamp') - $lastWrite > self::STALLED_AFTER
                ? 'stalled'
                : ($started ? 'running' : 'waiting'),
        };

        $errors = array_values(array_filter($entries, static fn ($entry) => ($entry['type'] ?? '') === 'error'));

        return [
            'status' => $status,
            'mode' => $progress['mode'] ?? null,
            'indexes' => array_map(
                static fn (string $name, array $index) => ['name' => $name, ...$index],
                array_keys($progress['indexes'] ?? []),
                $progress['indexes'] ?? []
            ),
            'started_at' => $log['start_time'] ?? null,
            'ended_at' => in_array($status, ['completed', 'error'], true) ? ($log['end_time'] ?? null) : null,
            'error' => $errors !== [] ? end($errors)['message'] : null,
            'entries' => array_slice($entries, -self::LOG_TAIL),
        ];
    }

    /**
     * An activity entry as the app reads it; the task stays on the server.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function present(array $entry): array
    {
        $retryable = is_array($entry['task'] ?? null);
        unset($entry['task']);

        return [...$entry, 'retryable' => $retryable];
    }

    private static function isScheduled(): bool
    {
        foreach ((array) _get_cron_array() as $hooks) {
            if (isset($hooks[IndexationServiceProvider::PROCESS_INDEXATION])) {
                return true;
            }
        }

        return false;
    }
}
