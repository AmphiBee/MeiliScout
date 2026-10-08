# MeiliScout - Filters and Configuration

## Available Filters

### meiliscout/skip_indexing
Temporarily disable all indexing operations (useful during imports).

```php
// Disable indexing during import
add_filter('meiliscout/skip_indexing', '__return_true');
// Your import code...
remove_filter('meiliscout/skip_indexing', '__return_true');
```

### meiliscout/bulk_batch_size
Configure batch size for bulk indexing (default: the "Contents per batch" setting, 500).

```php
// Increase for servers with more RAM
add_filter('meiliscout/bulk_batch_size', fn() => 10000);
```

### meiliscout/async_indexing_delay
Delay in seconds before async queue processing (default: 300).

```php
// Reduce delay to 1 minute
add_filter('meiliscout/async_indexing_delay', fn() => 60);
```

### meiliscout/log_directory
Customize log directory location.

```php
// Use /tmp to avoid S3 issues
add_filter('meiliscout/log_directory', fn() => '/tmp/meiliscout-logs');
```

### meiliscout/indexables
Replace default indexables.

```php
add_filter('meiliscout/indexables', function($indexables) {
    return [new CustomPostIndexable()];
});
```

### meiliscout/post_single_indexer
Replace default PostSingleIndexer.

```php
add_filter('meiliscout/post_single_indexer', fn() => new CustomPostIndexer());
```

### meiliscout/register_async_queue_processor
Control async queue processor registration.

```php
// Disable default processor to use custom one
add_filter('meiliscout/register_async_queue_processor', '__return_false');
```

### meiliscout/post/displayed_attributes
Restrict which fields Meilisearch may return. Defaults to `['*']`.

```php
// Keep the columns QueryIntegration needs to rebuild WP_Post, or listings break.
add_filter('meiliscout/post/displayed_attributes', function (array $attributes, array $metaKeys) {
    return [
        'ID', 'post_title', 'post_name', 'post_excerpt', 'post_status', 'post_type',
        'post_date', 'post_modified', 'post_parent', 'menu_order', 'comment_count',
        'url', 'terms',
        ...array_map(fn ($key) => "metas.{$key}", $metaKeys),
    ];
}, 10, 2);
```

### meiliscout/reindex_on_meta_change
Decide whether a changed post meta key re-indexes the post. By default, only the
selected meta keys do; with none selected, every key except WordPress internals
(`_edit_lock`, `_wp_old_slug`...) does.

```php
// An indexable whose documents read the variation prices
add_filter('meiliscout/reindex_on_meta_change', function (bool $reindex, string $metaKey, int $postId) {
    return $reindex || $metaKey === '_price';
}, 10, 3);
```

Changes are indexed once per post, at the end of the request (or by the async
queue when `MEILISCOUT_ASYNC_INDEXING` is on), however many hooks they fire.

### meiliscout/http_client_options
Options of the Symfony HttpClient that sends the Meilisearch requests
(defaults to `['timeout' => 10]`, the timeout being the admin's setting).

```php
add_filter('meiliscout/http_client_options', fn (array $options) => [
    ...$options,
    'timeout' => 5,
    'proxy' => 'http://proxy.internal:3128',
]);
```

### meiliscout/index_prefix
Prefix of the index names (`{prefix}_posts`, `{prefix}_taxonomies`). Defaults to
`MEILI_INDEX_PREFIX`, or to the site's domain (plus the blog id on multisite), so
that sites sharing a Meilisearch instance, a staging copy included, never write
to the same indexes. Return `''` for no prefix.

```php
add_filter('meiliscout/index_prefix', fn () => 'shop');
```

Changing the prefix calls for a full indexation: until then, searches keep
reading the previous indexes (see "Upgrading to 2.0").

## Actions

### meiliscout/reindex_post
Re-indexes a post whose document changed while the post itself was not saved,
such as a product whose variations changed. Runs at the end of the request, or
on the async queue, like any real-time task.

```php
do_action('meiliscout/reindex_post', $productId);
```

### meiliscout/schedule_indexation
Schedules one full indexation on WP-Cron, in place (the indexes are not
emptied). A second call while one is waiting does nothing.

```php
do_action('meiliscout/schedule_indexation');
```

## Dependent documents

An indexable can bring documents of its own along with each item, such as one
document per product variant: implement `Pollora\MeiliScout\Contracts\HasDependentDocuments`.

- `dependentDocuments(array $document, mixed $item): array` returns them, given
  the item's finished document.
- `dependentDocumentsFilter(array $itemIds): ?string` returns a Meilisearch filter
  matching every dependent document of these items (null when none can have any).

They are written with the item's document, and the ones the item no longer
brings are deleted. Dependent documents must carry the `taxonomies` field like
any post document, and the attributes the filter uses (`ID` included) must be
filterable, or stale ones are never deleted.

## Query Variables

| Variable | Description |
|----------|-------------|
| `use_meilisearch` | Run this `WP_Query` on Meilisearch |
| `meilisearch_facets` | Facets to compute, e.g. `['taxonomies.category.slug']`; the distribution lands in `$query->facet_distribution`. None by default: facets cost on every search |

## Admin settings

Set in MeiliScout › Settings and Content, stored as `meiliscout/<name>` options:

| Option | Description |
|--------|-------------|
| `realtime_indexing` | `shutdown` (default), `async` or `off`: only full indexations update the indexes |
| `http_timeout` | Seconds to wait for Meilisearch (default: 10) |
| `bulk_batch_size` | Contents sent per request by full indexations (default: 500) |
| `searchable_attributes` | Fields searched, most important first. Unset: every field (`*`) |
| `meili_index_prefix` | Prefix of the index names, unless `MEILI_INDEX_PREFIX` is set |

Documents carry `content_text`, the post content without markup, block
comments or shortcodes: the field to search rather than `post_content`.

## Upgrading to 2.0

2.0 changes the documents and the index names:

- Terms are grouped by taxonomy, in `taxonomies.<taxonomy>`, and taxonomy
  filters use them. The flat `terms` list matched a term's taxonomy and slug
  independently: a tag named `news` satisfied a `category = news` filter. It
  stays in the documents, but is no longer filterable.
- A `tax_query` on a hierarchical taxonomy includes the child terms, as in
  WordPress (`'include_children' => false` to opt out).
- Indexes are prefixed: `posts` becomes `{prefix}_posts`.

Nothing breaks on update. Searches keep reading the previous indexes, in the
previous format, and saved content is written to both, until a full
indexation (admin, or `wp meiliscout index`) builds the new indexes and moves
searches to them. An admin notice says so until then, then offers to delete
the previous indexes. If your search API key is restricted to some indexes,
give it access to the new names first.

Facets are no longer computed on every query: ask for them with
`meilisearch_facets`.

## Environment Variables

| Variable | Description |
|----------|-------------|
| `MEILISCOUT_ASYNC_INDEXING` | Real-time indexing on the async queue (`true`) or at the end of the request (`false`); locks the admin's setting |
| `MEILI_HOST`, `MEILI_KEY`, `MEILI_SEARCH_KEY` | Connection; each one locks its admin field |
| `MEILI_INDEX_PREFIX` | Prefix of the index names (default: the site's domain) |

## WP-CLI Commands

```bash
# Standard indexing (also migrates the indexes when needed)
wp meiliscout index

# Rebuild the indexes from scratch, searches staying available meanwhile
wp meiliscout index --clear

# Indexes read and written, pending migration
wp meiliscout status

# Delete the indexes searches no longer read since a migration
wp meiliscout delete-legacy-indexes

# Chunked indexing for large sites
wp meiliscout index --chunk-size=50000 --clear

# Purge indices
wp meiliscout index --purge
```

## High-Volume Site Configuration

```php
// In your theme's functions.php or a mu-plugin
add_filter('meiliscout/bulk_batch_size', fn() => 10000);
add_filter('meiliscout/log_directory', fn() => '/tmp/meiliscout-logs');
```

Enable async mode in `.env`:
```
MEILISCOUT_ASYNC_INDEXING=true
```
