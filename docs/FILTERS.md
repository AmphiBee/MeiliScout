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

An indexable's `getIndexSettings()` is what queries rely on. Start from the parent's settings and add to them: queries read back what the index pushed last, and fall back to MySQL when it lacks a field they need. Keep `ID`, `post_parent` and `taxonomies` in `displayedAttributes` (`get_terms()` with `object_ids`, `fields => 'id=>parent'`), `post_title`, `post_excerpt` and `content_text` in `searchableAttributes` (`search_columns`), and `pagination.maxTotalHits` no lower than `IndexSettings::maxTotalHits()`: a query without LIMIT stops at the lower of the two.

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

## Queries

How a `WP_Query` is translated, and when it runs on MySQL instead: [WP_QUERY.md](WP_QUERY.md).

### meiliscout/skip_query_integration
Keeps a query the settings cover on MySQL (`use_meilisearch` wins either way).

```php
add_filter('meiliscout/skip_query_integration', fn ($skip, WP_Query $query) => $skip || $query->get('post_type') === 'event', 10, 2);
```

### meiliscout/integrate_query
The last word on whether Meilisearch serves a query that did not ask (default: Settings › Queries).

```php
add_filter('meiliscout/integrate_query', fn ($integrate, WP_Query $query) => $integrate || $query->is_author(), 10, 2);
```

### meiliscout/supported_query_vars
Query vars Meilisearch can serve a query with. A query var MeiliScout does not
know sends the query to MySQL; add a plugin's var when the plugin only uses it
to build a `tax_query` or a `meta_query`.

```php
add_filter('meiliscout/supported_query_vars', fn (array $vars) => [...$vars, 'lang']);
```

### meiliscout/ignored_sql_filters

The SQL filters of `WP_Query` that do not stop Meilisearch from serving a query. A plugin changing a query's SQL through `posts_where`, `posts_clauses`... sends it to MySQL (`sql_filter:<hook>`), unless its callback is listed here: a hook name (every callback on it), a callback's name (`'my_function'`, `'My_Class::method'`) or the closure itself. List a callback whose change the site translates in `meiliscout/search_params`. Default: empty.

```php
add_filter('meiliscout/ignored_sql_filters', fn (array $ignored) => [...$ignored, 'My_Plugin::posts_where']);
```

### meiliscout/skip_term_query_integration, meiliscout/integrate_term_query

As `meiliscout/skip_query_integration` and `meiliscout/integrate_query`, for `get_terms()` calls that do not ask with `use_meilisearch`. They get the `WP_Term_Query`. See [TERM_QUERY.md](TERM_QUERY.md).

### meiliscout/ignored_term_sql_filters

As `meiliscout/ignored_sql_filters`, for `terms_clauses`, `list_terms_exclusions`, `get_terms_orderby` and `get_terms_fields`.

### meiliscout/term/ranking_rules

The ranking rules of the taxonomies index. Default: `['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness']`, `sort` first so that an order is followed strictly.

### meiliscout/term/document

The document of a term, before it is sent. Gets the document and the `WP_Term`.

### meiliscout/search_params
The parameters of the Meilisearch search a query becomes.

```php
add_filter('meiliscout/search_params', function (array $params) {
    $params['showRankingScore'] = true;

    return $params;
});
```

### meiliscout/hydrate_from_documents
Builds the `WP_Post` objects from the documents instead of loading them from the
database (default: false). Saves a query on the primary key, but the posts are
as fresh as the index, protected posts lose their content, and only displayed
attributes are there.

```php
add_filter('meiliscout/hydrate_from_documents', '__return_true');
```

### meiliscout/search_memo
Keeps Meilisearch's answers for the rest of the request (default: true): the
same search asked twice, such as a Query Loop's query run again by each of its
pagination blocks, reaches Meilisearch once. Forgotten when a post or its terms
change during the request (`clean_post_cache`, `clean_object_term_cache`).
`$query->meiliscout['memo']` counts the answers a query took from it.

```php
add_filter('meiliscout/search_memo', '__return_false');
```

### meiliscout/max_total_hits
Results a search can reach (default: Settings › Advanced › Maximum results per
query, 10,000). Sent with the index settings: changed by the next indexation.

### meiliscout/php_order_limit
Results put in order in PHP for `orderby` `rand`, `post__in`, `post_name__in` and
`post_parent__in` (default: 1000). Beyond, the query runs on MySQL.

### meiliscout/post/ranking_rules
Ranking rules of the posts index (default: `sort` first, then Meilisearch's
own). With `sort` first, a search with an explicit `orderby` follows it
strictly; a search without one is ranked by relevance as before.

```php
// Sorts only break ties between equally relevant posts
add_filter('meiliscout/post/ranking_rules', fn () => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness']);
```

### meiliscout/debug_header
Whether the `X-MeiliScout: served | fallback:<reason>` header is sent with the
main query (default: `WP_DEBUG`, or an administrator).

```php
add_filter('meiliscout/debug_header', '__return_false');
```

### meiliscout/indexable_post_statuses
Statuses of the posts sent to the index (default: `publish`, plus `private` with
Content › Index private content). A status added here is used by queries once a
full indexation sent its posts.

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
| `use_meilisearch` | Run this `WP_Query` on Meilisearch (`true`), or keep it on MySQL whatever the settings (`false`). What happened is in `$query->meiliscout` |
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
| `query_integration` | Queries served without asking: `search`, `archives`, `rest_search`, `admin` (all off by default) |
| `max_total_hits` | Results a search can reach (default: 10,000) |
| `contains_filter` | Whether `meta_query` `LIKE` uses Meilisearch's `CONTAINS` (follows the instance's experimental feature) |
| `index_private` | Whether private posts are indexed (default: off) |

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

Documents also carry fields for `WP_Query` arguments (posts schema 3: ids,
authors, parents, dates as timestamps and parts, `post_title_sort`; schema 4:
every value of a meta key, empty ones included, and the title and comment
statuses filterable). Queries using them run on MySQL until the full
indexation is done. Each index has its own format version: `wp meiliscout
status` shows them, and a full indexation moves only the indexes it rebuilt. A query Meilisearch cannot
answer as MySQL would now runs on MySQL instead of returning other posts: see
[WP_QUERY.md](WP_QUERY.md). Posts are loaded from the database rather than
built from the documents (`meiliscout/hydrate_from_documents` to go back).

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

# Run queries on MySQL and on Meilisearch, and compare (exit code 1 on a difference)
wp meiliscout check-queries
wp meiliscout check-queries --case=tax_query
wp meiliscout check-queries --args='{"post_type":"page","orderby":"menu_order"}'
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
