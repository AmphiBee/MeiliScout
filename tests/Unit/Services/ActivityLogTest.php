<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Services {

    use Pollora\MeiliScout\Services\ActivityLog;
    use Pollora\MeiliScout\Services\IndexingTask;
    use Pollora\MeiliScout\Services\PostSingleIndexer;
    use Pollora\MeiliScout\Services\TaxonomySingleIndexer;

    /** Answers like the post indexer, without reaching an engine. */
    final class ScriptedPostIndexer extends PostSingleIndexer
    {
        public bool|\Throwable $outcome = true;

        public function __construct() {}

        public function indexPost(int|\WP_Post $post): bool
        {
            if ($this->outcome instanceof \Throwable) {
                throw $this->outcome;
            }

            return $this->outcome;
        }

        public function removePost(int $postId): bool
        {
            // Like removeItem(): the failure is only logged
            $this->lastError = 'Failed to remove item: timeout';

            return false;
        }

        public function reindexPostsForTerm(int $termId, string $taxonomy): int
        {
            return 41;
        }
    }

    final class IdleTaxonomyIndexer extends TaxonomySingleIndexer
    {
        public function __construct() {}
    }

    function tasks(ScriptedPostIndexer $posts): IndexingTask
    {
        return new IndexingTask($posts, new IdleTaxonomyIndexer);
    }

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        $GLOBALS['posts'] = [];
        ActivityLog::reset();
    });

    test('tasks are written once, at flush, newest first', function () {
        $run = tasks(new ScriptedPostIndexer);

        $run->run(IndexingTask::make('post', 'index', 7, ['label' => 'First']));
        $run->run(IndexingTask::make('post', 'index', 8, ['label' => 'Second']));

        expect(ActivityLog::all())->toBe([]);

        ActivityLog::flush();
        $entries = ActivityLog::all();

        expect($entries)->toHaveCount(2)
            ->and($entries[0]['label'])->toBe('Second')
            ->and($entries[1])->toMatchArray([
                'kind' => 'realtime',
                'operation' => 'post.index',
                'label' => 'First',
                'status' => 'success',
                'items' => 1,
                'task' => null,
            ]);
    });

    test('a task that throws is recorded with what to run it again, then thrown again', function () {
        $posts = new ScriptedPostIndexer;
        $posts->outcome = new \RuntimeException('Meilisearch timed out');
        $task = IndexingTask::make('post', 'index', 7, ['label' => 'Shop launch']);

        expect(fn () => tasks($posts)->run($task))->toThrow(\RuntimeException::class);

        ActivityLog::flush();

        expect(ActivityLog::all()[0])->toMatchArray([
            'status' => 'error',
            'error' => 'Meilisearch timed out',
            'task' => $task,
        ]);
    });

    test('a failure the indexer only logged is recorded as a failure', function () {
        tasks(new ScriptedPostIndexer)->run(IndexingTask::make('post', 'remove', 7, ['label' => 'Gone']));
        ActivityLog::flush();

        expect(ActivityLog::all()[0])->toMatchArray([
            'operation' => 'post.remove',
            'status' => 'error',
            'error' => 'Failed to remove item: timeout',
        ]);
    });

    test('re-indexing the posts of a term counts them', function () {
        tasks(new ScriptedPostIndexer)->run(IndexingTask::make('posts_for_term', 'reindex', 3, ['taxonomy' => 'category', 'label' => 'News']));
        ActivityLog::flush();

        expect(ActivityLog::all()[0]['items'])->toBe(41);
    });

    test('a retried entry cannot be run a second time', function () {
        $posts = new ScriptedPostIndexer;
        $posts->outcome = new \RuntimeException('down');
        try {
            tasks($posts)->run(IndexingTask::make('post', 'index', 7));
        } catch (\RuntimeException) {
        }
        ActivityLog::flush();
        $id = ActivityLog::all()[0]['id'];

        ActivityLog::markRetried($id);

        expect(ActivityLog::find($id))->toMatchArray(['task' => null, 'retried' => true]);
    });

    test('the log keeps the last entries only', function () {
        $run = tasks(new ScriptedPostIndexer);

        foreach (range(1, ActivityLog::MAX_ENTRIES + 5) as $id) {
            $run->run(IndexingTask::make('post', 'index', $id, ['label' => "Post {$id}"]));
        }
        ActivityLog::flush();

        expect(ActivityLog::all())->toHaveCount(ActivityLog::MAX_ENTRIES)
            ->and(ActivityLog::all()[0]['label'])->toBe('Post '.(ActivityLog::MAX_ENTRIES + 5));
    });

    test('a full indexation is written at once', function () {
        ActivityLog::recordFullIndexation('rebuild', true, 327, 1200.4, ['a_posts', 'a_taxonomies']);

        expect(ActivityLog::all()[0])->toMatchArray([
            'kind' => 'full',
            'operation' => 'full.rebuild',
            'items' => 327,
            'duration_ms' => 1200,
            'indexes' => ['a_posts', 'a_taxonomies'],
        ]);
    });
}
