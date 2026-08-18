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
Configure batch size for bulk indexing (default: 500).

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

## Environment Variables

| Variable | Description |
|----------|-------------|
| `MEILISCOUT_ASYNC_INDEXING` | Enable async mode (`true`/`false`) |

## WP-CLI Commands

```bash
# Standard indexing
wp meiliscout index --clear

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
