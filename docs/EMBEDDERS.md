# Embedders and hybrid search

Meilisearch can search by meaning as well as by keywords: an **embedder** turns each document, and each search, into a vector, and a **hybrid search** mixes both rankings. MeiliScout declares the embedders in code, pushes them with the index settings, runs hybrid searches from a `WP_Query`, and falls back to keywords when the embedder fails.

## Declaring an embedder

```php
add_action('init', function () {
    meiliscout_register_embedder('mistral', [
        'source' => 'rest',
        'url' => 'https://api.mistral.ai/v1/embeddings',
        'apiKey' => 'MISTRAL_API_KEY', // a constant or environment variable name, or a callable
        'dimensions' => 1024,
        'request' => ['model' => 'mistral-embed', 'input' => ['{{text}}', '{{..}}']],
        'response' => ['data' => [['embedding' => '{{embedding}}'], '{{..}}']],
        'documentTemplate' => '{% if doc.embedding_text %}{{ doc.embedding_text }}{% else %}{{ doc.post_title }}{% endif %}',
        'documentTemplateMaxBytes' => 2000,
    ], ['posts', 'manual_chunks']);
});
```

- The configuration is Meilisearch's own embedder setting ([documentation](https://www.meilisearch.com/docs/reference/api/settings#embedders)); MeiliScout passes it as is.
- The third argument lists **base index names**: `posts`, `taxonomies`, or another plugin's index (see below). Default: `posts`.
- The embedders go with the index settings every time they are pushed: full indexations, the first real-time write, and a real-time write after a settings change. An embedder added by hand in Meilisearch would be overwritten; one removed from the code stays in Meilisearch until deleted there (`PATCH /indexes/{uid}/settings` with `{"embedders": {"mistral": null}}`).
- An indexable may also return `embedders` in its own `getIndexSettings()`; those win over the declared ones of the same name.
- The `meiliscout/embedders` filter gets and returns every embedder, by base index name then by name.

### The API key

`apiKey` is never stored in WordPress:

- a **string** is the name of a constant or of an environment variable (`getenv()`, `$_ENV`, `$_SERVER`), read when the settings are sent;
- a **callable** is called then: `fn () => config('ai.providers.mistral.key')`.

A key that cannot be read (undefined, empty, or a callable that throws) is left out of the settings sent, and logged; Meilisearch keeps the key it has. The key is not part of the fingerprint MeiliScout keeps of the last settings pushed, so a new key alone does not push the settings again (push them with a full indexation).

The configuration export (`embedders` section) names each embedder with its source, model and template, never its `apiKey` nor its `headers`. Embedders are declared in code: an import writes none, and warns where the file and the site differ.

### Other index settings

`meiliscout/index_settings` filters every index's settings before they are pushed, for a setting no indexable sets:

```php
add_filter('meiliscout/index_settings', function (array $settings, string $base) {
    if (in_array($base, ['posts', 'manual_chunks'], true)) {
        // Since Meilisearch 1.44 it also bounds the call to a REST embedder
        $settings['searchCutOffMs'] = 1500;
    }

    return $settings;
}, 10, 2);
```

### Status

`wp meiliscout status` lists each embedder of the indexes searches read, with the documents of the index, those still **without a vector** (`numberOfDocuments - numberOfEmbeddedDocuments`), and the index's **failed tasks**, with a warning when there are some.

## Hybrid search from a WP_Query

```php
new WP_Query([
    'post_type' => 'post',
    's' => 'a light tent for two hikers',
    'use_meilisearch' => true,
    'meiliscout_hybrid' => [
        'embedder' => 'mistral',
        'semanticRatio' => 0.5,           // 0: keywords only, 1: meaning only; default 0.5
        'rankingScoreThreshold' => null,  // optional, 0 to 1: results below it are left out
    ],
]);
```

- `meiliscout_hybrid` becomes Meilisearch's `hybrid` parameter (`embedder`, `semanticRatio`), plus `rankingScoreThreshold` when given, and `showRankingScore`.
- **Without a search term** (`s`), it is ignored: there is nothing to embed.
- An invalid value (no embedder, a ratio outside 0–1) runs the query on MySQL, with the reason `invalid_arg:meiliscout_hybrid`.
- The ranking score of each post is in `$query->meiliscout['ranking_scores'][$postId]`, and the number of hits the meaning brought in `$query->meiliscout['semantic_hits']`.
- A hybrid search is **not weighted**: a weighted search sends one query per weight tier, each embedding the search term again. See [WEIGHTS.md](WEIGHTS.md).

### When the embedder fails

Meilisearch (1.57) handles part of it itself: when it cannot embed the search term and `semanticRatio` is below 1, it retries for about 1.5 s, then answers with the keyword results alone and `semanticHitCount: 0`, without an error. MeiliScout cannot tell that apart from a search whose best hits all came from keywords: `$query->meiliscout['semantic_hits']` gives the count, for diagnosis, and such a search is not counted as a fallback. Keep `semanticRatio` below 1 where a slow answer is better than an error.

When Meilisearch answers a hybrid search with an embedder error (`vector_embedding_error`, `invalid_search_embedder`, `invalid_search_hybrid_query`) or does not answer in time, the same search runs again **by keywords alone, on Meilisearch**; it does not go to MySQL, whose `LIKE` is worse than keywords. Then:

- `$query->meiliscout['hybrid_fallback']` holds the error code (`timeout` for no answer, `failed_before` for the next searches);
- `$query->meiliscout['params']` is the keyword search actually run;
- the fallback is counted once per query under `hybrid_fallback`, apart from the queries MySQL served (`SearchFallbacks::lastDay()['hybrid']`), and only when the keyword search answered;
- a query paged by `offset` counts its results in a second search: when only that one fails, the hits stay hybrid and the total is counted by keywords;
- the embedder is not asked again for the rest of the request.

Any other error falls back to MySQL as before (`engine_error`).

## Writing to another plugin's index

Real-time indexing knows posts and terms. Another plugin's index (the passages of a manual, support contacts...) is declared with the `meiliscout/indexables` filter, rebuilt by `wp meiliscout index`, and kept up to date between two full indexations with:

```php
// Adds or replaces the documents of some items: formatForIndexing() of each
meiliscout_sync_items($manualChunks, $chunksOfAttachment);

// Deletes the documents matching a filter (its attributes must be filterable)
meiliscout_delete_items($manualChunks, 'attachment_id = 123');
```

- The index is `$indexable->getIndexName()`: name it `IndexNames::target('manual_chunks')` (prefixed). A full rebuild builds it aside and swaps it in under the same name, and `IndexNames::active('manual_chunks')` reads that name, on a site indexed before 2.0 too.
- A missing index is created with the indexable's primary key and settings (embedders included); an existing one gets its settings again only when they changed.
- Documents go in batches of `meiliscout/bulk_batch_size`.
- With the **asynchronous queue** on (`MEILISCOUT_ASYNC_INDEXING`, or Settings › Real-time indexing), the documents are formatted at once and sent by WP-Cron, as posts are; the same write queued twice runs once. The queue finds the indexable again among those of `meiliscout/indexables`: an indexable not registered there is written at once. A failed task is kept in the activity log, to retry.
- `meiliscout/skip_indexing` stops both functions, as it stops posts.
- Written at once, they throw when Meilisearch is not configured or refuses the write: catch it where a failure must not break the request.
- A write made while a full indexation rebuilds that index, from another process, goes to the index being replaced: run it again after the rebuild.

## Cost and duration

A full rebuild builds a new index: **every document is embedded again**. An update in place (real-time indexing, `meiliscout_sync_items()`, `wp meiliscout index` without `--clear`) only embeds the documents whose template's text changed.

While Meilisearch cannot reach a REST embedder, a task adding documents retries with an increasing delay before failing, and the tasks of every index wait behind it. Check `wp meiliscout status` after an indexation, and keep `meiliscout/bulk_batch_size` small for an index with an embedder.

## Browser searches

A search key or tenant token given to a browser cannot forbid the `hybrid` parameter: anyone could run hybrid searches billed on the embedder's key. On an index with an embedder, prefer the `rest` transport for suggestion fields and listings, which keeps the key on the server.
