<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers;

use Pollora\MeiliScout\Config\RealtimeIndexing;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Services\ActivityLog;
use Pollora\MeiliScout\Services\AsyncIndexingQueue;
use Pollora\MeiliScout\Services\IndexingTask;
use Pollora\MeiliScout\Services\ObjectCacheIsolation;
use Pollora\MeiliScout\Services\PostSingleIndexer;
use Pollora\MeiliScout\Services\TaxonomySingleIndexer;

use function add_action;
use function apply_filters;
use function get_post;
use function get_term;
use function get_ancestors;
use function get_term_by;

/**
 * Service provider for managing automatic single-item indexing operations.
 *
 * This service provider registers WordPress hooks to automatically index
 * posts and taxonomy terms when they are created, updated, or deleted.
 * It ensures the search index stays synchronized with content changes.
 *
 * @package Pollora\MeiliScout\Providers
 * @since 1.0.0
 */
class SingleIndexingServiceProvider extends ServiceProvider
{
    /**
     * After WooCommerce, which rewrites the variations of a renamed attribute term at priority 10.
     */
    public const EDITED_TERM_PRIORITY = 100;

    /**
     * Post single indexer instance.
     *
     * @var PostSingleIndexer|null
     */
    private ?PostSingleIndexer $postIndexer = null;

    /**
     * Taxonomy single indexer instance.
     *
     * @var TaxonomySingleIndexer|null
     */
    private ?TaxonomySingleIndexer $taxonomyIndexer = null;

    /**
     * Async indexing queue instance (null when in sync mode).
     *
     * @var AsyncIndexingQueue|null
     */
    private ?AsyncIndexingQueue $asyncQueue = null;

    /**
     * Term fields copied into post documents, recorded before an update.
     *
     * @var array<int, array{name: string, slug: string, parent: int}>
     */
    private array $termsBeforeEdit = [];

    /**
     * Ancestors of the terms being deleted, recorded before: their tree counts change.
     *
     * @var array<int, list<int>>
     */
    private array $ancestorsBeforeDelete = [];

    /**
     * Tasks waiting for the end of the request, one per post or term (sync mode).
     *
     * @var array<string, array{type: string, action: string, id: int, extra: array<string, mixed>}>
     */
    private array $pendingTasks = [];

    /**
     * Meta keys WordPress writes on its own, which never change a document.
     *
     * @var list<string>
     */
    public const INTERNAL_META_KEYS = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_old_date',
        '_encloseme',
        '_pingme',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_wp_desired_post_slug',
    ];

    /**
     * Register the service provider.
     *
     * This method sets up all WordPress hooks for automatic indexing
     * of posts and taxonomy terms.
     *
     * @return void
     */
    public function register(): void
    {
        // Initialize indexers lazily
        $this->postIndexer = new PostSingleIndexer();
        $this->taxonomyIndexer = new TaxonomySingleIndexer();

        $mode = RealtimeIndexing::mode();

        // Also when real-time indexing was turned off since: what was queued still goes out
        if ($mode !== RealtimeIndexing::SHUTDOWN) {
            $this->asyncQueue = new AsyncIndexingQueue($this->postIndexer, $this->taxonomyIndexer);
            // A custom provider may have registered its own processor
            // with the correct indexer. In that case skip registering the default one.
            if (apply_filters('meiliscout/register_async_queue_processor', true)) {
                add_action('meiliscout_process_async_queue', [$this->asyncQueue, 'process']);
            }
        }

        // Only full indexations update the indexes
        if ($mode === RealtimeIndexing::OFF) {
            return;
        }

        $this->registerPostHooks();
        $this->registerTaxonomyHooks();
    }

    /**
     * Registers WordPress hooks for post indexing operations.
     *
     * This method sets up hooks for post creation, updates, deletions,
     * and status changes to keep the search index synchronized.
     *
     * @return void
     */
    private function registerPostHooks(): void
    {
        // Hook for post saves (create and update)
        add_action('save_post', [$this, 'handlePostSave'], 10, 3);

        // Hook for post deletions
        add_action('delete_post', [$this, 'handlePostDelete'], 10, 1);

        // Hook for post status transitions
        add_action('transition_post_status', [$this, 'handlePostStatusChange'], 10, 3);

        // Hook for post meta updates (for indexed meta fields)
        add_action('updated_post_meta', [$this, 'handlePostMetaUpdate'], 10, 4);
        add_action('added_post_meta', [$this, 'handlePostMetaUpdate'], 10, 4);
        add_action('deleted_post_meta', [$this, 'handlePostMetaUpdate'], 10, 4);

        // Hook for a post another plugin says changed, such as the parent of a product variation
        add_action('meiliscout/reindex_post', [$this, 'handlePostReindex'], 10, 1);
    }

    /**
     * Registers WordPress hooks for taxonomy term indexing operations.
     *
     * This method sets up hooks for term creation, updates, and deletions
     * to keep the taxonomy search index synchronized.
     *
     * @return void
     */
    private function registerTaxonomyHooks(): void
    {
        // Hook for term creation and updates
        add_action('created_term', [$this, 'handleTermCreate'], 10, 3);
        add_action('edit_terms', [$this, 'rememberTermBeforeEdit'], 10, 2);
        add_action('edited_term', [$this, 'handleTermSave'], self::EDITED_TERM_PRIORITY, 3);

        // Hook for term counts, updated when a post gets or loses the term (wp_update_term_count_now())
        add_action('edited_term_taxonomy', [$this, 'handleTermCountUpdate'], 10, 2);

        // Hook for the children of a deleted term, given its parent
        add_action('edited_term_taxonomies', [$this, 'handleTermsReparented'], 10, 1);

        // Hook for term deletions
        add_action('pre_delete_term', [$this, 'rememberAncestorsBeforeDelete'], 10, 2);
        add_action('delete_term', [$this, 'handleTermDelete'], 10, 4);

        // Hook for term meta updates
        add_action('updated_term_meta', [$this, 'handleTermMetaUpdate'], 10, 4);
        add_action('added_term_meta', [$this, 'handleTermMetaUpdate'], 10, 4);
        add_action('deleted_term_meta', [$this, 'handleTermMetaUpdate'], 10, 4);
    }

    /**
     * Handles post save operations (create and update).
     *
     * This method is called when a post is saved and determines whether
     * it should be indexed, updated, or removed from the index.
     *
     * @param int $postId The ID of the post being saved
     * @param \WP_Post $post The post object being saved
     * @param bool $update Whether this is an update (true) or new post (false)
     * @return void
     */
    public function handlePostSave(int $postId, \WP_Post $post, bool $update): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        // Skip if this is an autosave, revision, or auto-draft
        if ($this->shouldSkipPostOperation($postId, $post)) {
            return;
        }

        $this->queue('post', 'index', $postId);
    }

    /**
     * Handles post deletion operations.
     *
     * This method removes the post from the search index when it's deleted.
     *
     * @param int $postId The ID of the post being deleted
     * @return void
     */
    public function handlePostDelete(int $postId): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        // The post is gone once the task runs: the activity log gets its title now
        $title = get_post($postId)?->post_title;
        $this->queue('post', 'remove', $postId, is_string($title) && $title !== '' ? ['label' => $title] : []);
    }

    /**
     * Handles post status transitions.
     *
     * This method is called when a post's status changes (e.g., draft to publish)
     * and ensures the post is properly indexed or removed based on its new status.
     *
     * @param string $newStatus The new post status
     * @param string $oldStatus The old post status
     * @param \WP_Post $post The post object
     * @return void
     */
    public function handlePostStatusChange(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        // Skip if this is an autosave, revision, or auto-draft
        if ($this->shouldSkipPostOperation($post->ID, $post)) {
            return;
        }

        $this->queue('post', 'index', $post->ID);
    }

    /**
     * Handles post meta updates.
     *
     * This method re-indexes a post when its metadata is updated,
     * ensuring the search index reflects the latest meta values.
     *
     * @param int|array $metaId The ID of the meta entry
     * @param int $postId The ID of the post
     * @param string $metaKey The meta key being updated
     * @param mixed $metaValue The new meta value
     * @return void
     */
    public function handlePostMetaUpdate(int|array $metaId, int $postId, string $metaKey, mixed $metaValue): void
    {
        if ($this->metaKeyChangesDocument($metaKey, $postId)) {
            $this->handlePostReindex($postId);
        }
    }

    /**
     * Whether a meta key is copied into post documents, so that changing it calls for a re-index.
     *
     * With meta keys selected, only those are. Without, documents carry every
     * meta key, except the ones WordPress keeps for itself (edit lock, old slug...).
     * The `meiliscout/reindex_on_meta_change` filter has the last word, for
     * indexables whose documents depend on other meta keys.
     */
    private function metaKeyChangesDocument(string $metaKey, int $postId): bool
    {
        $indexedMetaKeys = Settings::get('indexed_meta_keys', []);

        $changesDocument = ! empty($indexedMetaKeys)
            ? in_array($metaKey, $indexedMetaKeys, true)
            : ! in_array($metaKey, self::INTERNAL_META_KEYS, true);

        return (bool) apply_filters('meiliscout/reindex_on_meta_change', $changesDocument, $metaKey, $postId);
    }

    /**
     * Re-indexes a post through the same path as a save: skipped, queued or indexed alike.
     *
     * Fired with `do_action('meiliscout/reindex_post', $postId)` by code that knows a post's
     * document changed while the post itself was not saved.
     *
     * @param int $postId The ID of the post to re-index
     * @return void
     */
    public function handlePostReindex(int $postId): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        $post = get_post($postId);

        if (!$post instanceof \WP_Post || $this->shouldSkipPostOperation($postId, $post)) {
            return;
        }

        $this->queue('post', 'index', $postId);
    }

    /**
     * Handles taxonomy term creation.
     *
     * Only the term itself is indexed: a term that was just created has no
     * posts yet, so there is nothing to re-index.
     *
     * @param int $termId The ID of the created term
     * @param int $ttId The term taxonomy ID
     * @param string $taxonomy The taxonomy name
     * @return void
     */
    public function handleTermCreate(int $termId, int $ttId, string $taxonomy): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        $this->queue('term', 'index', $termId);
    }

    /**
     * Records the fields post documents copy from a term, before it is updated.
     *
     * handleTermSave() compares them with the saved term to decide whether the
     * term's posts need re-indexing.
     *
     * @param int $termId The ID of the term about to be updated
     * @param string $taxonomy The taxonomy name
     * @return void
     */
    public function rememberTermBeforeEdit(int $termId, string $taxonomy): void
    {
        $term = get_term($termId, $taxonomy);

        if ($term instanceof \WP_Term) {
            $this->termsBeforeEdit[$termId] = $this->termFieldsInPostDocuments($term);
        }
    }

    /**
     * Handles taxonomy term updates.
     *
     * The term is always re-indexed. Its posts are re-indexed only when a field
     * their documents copy (name, slug, parent) changed: editing a description
     * or an SEO field must not rewrite every post that carries the term.
     *
     * @param int $termId The ID of the term being saved
     * @param int $ttId The term taxonomy ID
     * @param string $taxonomy The taxonomy name
     * @return void
     */
    public function handleTermSave(int $termId, int $ttId, string $taxonomy): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        $before = $this->termsBeforeEdit[$termId] ?? null;
        $reindexPosts = $this->termFieldsChanged($termId, $taxonomy);

        $this->queue('term', 'index', $termId);

        // Moved: the tree counts of its former and new ancestors change
        $term = get_term($termId, $taxonomy);
        if ($before !== null && $term instanceof \WP_Term && (int) $term->parent !== $before['parent']) {
            foreach ([$before['parent'], ...get_ancestors($before['parent'], $taxonomy, 'taxonomy'), ...get_ancestors($termId, $taxonomy, 'taxonomy')] as $ancestor) {
                if ((int) $ancestor > 0) {
                    $this->queue('term', 'index', (int) $ancestor);
                }
            }
        }

        if ($reindexPosts) {
            $this->queue('posts_for_term', 'reindex', $termId, ['taxonomy' => $taxonomy]);
        }
    }

    /**
     * Whether a field copied into post documents changed during the update.
     *
     * Unknown previous state (no edit_terms before edited_term) counts as a
     * change, so the posts are re-indexed as before.
     */
    private function termFieldsChanged(int $termId, string $taxonomy): bool
    {
        $before = $this->termsBeforeEdit[$termId] ?? null;
        unset($this->termsBeforeEdit[$termId]);

        $term = get_term($termId, $taxonomy);

        if ($before === null || !$term instanceof \WP_Term) {
            return true;
        }

        return $this->termFieldsInPostDocuments($term) !== $before;
    }

    /**
     * The term fields PostIndexable copies into each post document.
     *
     * @return array{name: string, slug: string, parent: int}
     */
    private function termFieldsInPostDocuments(\WP_Term $term): array
    {
        return [
            'name' => $term->name,
            'slug' => $term->slug,
            'parent' => (int) $term->parent,
        ];
    }

    /**
     * Re-indexes a term whose post count changed: term queries filter (hide_empty) and sort on it.
     *
     * Fired for each term of a post that is published, unpublished or gets
     * other terms, and by wp_update_term() too. The tasks wait for the end of
     * the request, where a term changed several times is indexed once.
     *
     * @param int $ttId The term taxonomy ID
     * @param string $taxonomy The taxonomy name
     * @return void
     */
    public function handleTermCountUpdate(int $ttId, string $taxonomy): void
    {
        if ($this->shouldSkipIndexing() || ! in_array($taxonomy, (array) Settings::get('indexed_taxonomies', []), true)) {
            return;
        }

        $term = get_term_by('term_taxonomy_id', $ttId, $taxonomy);

        if ($term instanceof \WP_Term) {
            $this->queueWithAncestors((int) $term->term_id, $taxonomy);
        }
    }

    /**
     * Re-indexes the children a deleted term left to its parent: their parent changed.
     *
     * @param  array<int>  $ttIds  Term taxonomy IDs
     */
    public function handleTermsReparented(array $ttIds): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        foreach ($ttIds as $ttId) {
            $term = get_term_by('term_taxonomy_id', (int) $ttId);

            if ($term instanceof \WP_Term && in_array($term->taxonomy, (array) Settings::get('indexed_taxonomies', []), true)) {
                $this->queue('term', 'index', (int) $term->term_id);
            }
        }
    }

    /**
     * Records the ancestors of a term about to be deleted, whose tree counts will change.
     */
    public function rememberAncestorsBeforeDelete(int $termId, string $taxonomy): void
    {
        $this->ancestorsBeforeDelete[$termId] = array_map('intval', get_ancestors($termId, $taxonomy, 'taxonomy'));
    }

    /**
     * Queues a term, and its ancestors: their tree counts (tree_count) include its posts.
     */
    private function queueWithAncestors(int $termId, string $taxonomy): void
    {
        $this->queue('term', 'index', $termId);

        foreach (get_ancestors($termId, $taxonomy, 'taxonomy') as $ancestor) {
            $this->queue('term', 'index', (int) $ancestor);
        }
    }

    /**
     * Handles taxonomy term deletion operations.
     *
     * This method removes the term from the search index. Its posts are not
     * re-indexed here: delete_term fires once the term's relationships are
     * gone, so a query for the term's posts would come back empty.
     *
     * @param int $termId The ID of the term being deleted
     * @param int $ttId The term taxonomy ID
     * @param string $taxonomy The taxonomy name
     * @param \WP_Term $deletedTerm The term object before deletion
     * @return void
     */
    public function handleTermDelete(int $termId, int $ttId, string $taxonomy, \WP_Term $deletedTerm): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        $this->queue('term', 'remove', $termId, ['label' => $deletedTerm->name]);

        foreach ($this->ancestorsBeforeDelete[$termId] ?? [] as $ancestor) {
            $this->queue('term', 'index', $ancestor);
        }
        unset($this->ancestorsBeforeDelete[$termId]);
    }

    /**
     * Handles term meta updates.
     *
     * This method re-indexes a term when its metadata is updated,
     * ensuring the search index reflects the latest meta values.
     *
     * @param int|array $metaId The ID of the meta entry
     * @param int $termId The ID of the term
     * @param string $metaKey The meta key being updated
     * @param mixed $metaValue The new meta value
     * @return void
     */
    public function handleTermMetaUpdate(int|array $metaId, int $termId, string $metaKey, mixed $metaValue): void
    {
        if ($this->shouldSkipIndexing()) {
            return;
        }

        $term = get_term($termId);

        if (!$term instanceof \WP_Term) {
            return;
        }

        $this->queue('term', 'index', $termId);
    }

    /**
     * Determines if a post operation should be skipped.
     *
     * This method checks for various conditions where indexing should be skipped,
     * such as autosaves, revisions and auto-drafts.
     *
     * An AJAX request is not one of them. Quick edit, bulk edit and every
     * Action Scheduler job run through admin-ajax.php, so skipping them leaves
     * the index holding the values the database no longer has.
     *
     * @param int $postId The post ID
     * @param \WP_Post $post The post object
     * @return bool True if the operation should be skipped, false otherwise
     */
    private function shouldSkipPostOperation(int $postId, \WP_Post $post): bool
    {
        // Skip autosaves
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return true;
        }

        // Skip revisions
        if (wp_is_post_revision($postId)) {
            return true;
        }

        // Skip auto-drafts
        if ($post->post_status === 'auto-draft') {
            return true;
        }

        return false;
    }

    /**
     * Queues an indexing task.
     *
     * In async mode, the task goes to the WP-Cron queue. Otherwise it waits for
     * the end of the request: saving a post fires save_post, transition_post_status
     * and one hook per meta key, and each used to send its own requests to
     * Meilisearch. Kept per item, the last task wins, and runs once.
     *
     * @param  array<string, mixed>  $extra
     */
    private function queue(string $type, string $action, int $id, array $extra = []): void
    {
        if ($this->isAsyncMode()) {
            $this->asyncQueue->enqueue($type, $action, $id, $extra);

            return;
        }

        if ($this->pendingTasks === []) {
            add_action('shutdown', [$this, 'runPendingTasks'], 0);
        }

        // Re-added at the end: tasks run in the order of their last change
        $key = IndexingTask::key($type, $id);
        unset($this->pendingTasks[$key]);
        $this->pendingTasks[$key] = IndexingTask::make($type, $action, $id, $extra);
    }

    /**
     * Runs the tasks queued during the request. Hooked on shutdown.
     *
     * A failing task is logged and never breaks the request that saved the content.
     */
    public function runPendingTasks(): void
    {
        $pendingTasks = $this->pendingTasks;
        $this->pendingTasks = [];

        if ($pendingTasks === []) {
            return;
        }

        $tasks = new IndexingTask(
            $this->postIndexer ??= new PostSingleIndexer(),
            $this->taxonomyIndexer ??= new TaxonomySingleIndexer()
        );

        ObjectCacheIsolation::run(function () use ($pendingTasks, $tasks): void {
            foreach ($pendingTasks as $task) {
                try {
                    $tasks->run($task);
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        'MeiliScout: Failed to %s %s %d: %s',
                        $task['action'],
                        $task['type'],
                        $task['id'],
                        $e->getMessage()
                    ));
                }
            }
        });

        ActivityLog::flush();
    }

    /**
     * Determines if all indexing should be skipped globally.
     *
     * Allows external code to disable indexing in specific contexts
     * (e.g., during imports) via the 'meiliscout/skip_indexing' filter.
     *
     * @return bool True if indexing should be skipped, false otherwise
     */
    private function shouldSkipIndexing(): bool
    {
        return (bool) apply_filters('meiliscout/skip_indexing', false);
    }

    /**
     * Determines whether asynchronous indexing mode is enabled.
     *
     * Set in the admin, or by MEILISCOUT_ASYNC_INDEXING (see RealtimeIndexing).
     *
     * @return bool True if async mode is active, false for synchronous (default)
     */
    private function isAsyncMode(): bool
    {
        return RealtimeIndexing::mode() === RealtimeIndexing::ASYNC;
    }
}
