# get_terms() and Meilisearch

MeiliScout answers a `get_terms()` call (`WP_Term_Query`) from the taxonomies index when the call asks for it (`'use_meilisearch' => true`) or when the settings cover it (Settings › Queries › Term queries). It returns what MySQL would have returned: the same terms, in the same order, in the same shape, down to the array keys.

**Translate or fall back**, as for [WP_Query](WP_QUERY.md): every argument is translated faithfully, or the whole query runs on MySQL and the reason is recorded.

```php
$query = new WP_Term_Query([
    'use_meilisearch' => true,
    'taxonomy' => 'product_cat',
    'child_of' => 12,
    'meta_query' => [['key' => 'featured', 'value' => 1]],
]);

$query->meiliscout; // ['served' => true, 'reason' => null, 'params' => [...], 'index' => '...', 'time' => 2.1, 'found' => 8]
```

## Which term queries Meilisearch serves

| Query | Served when |
|---|---|
| `get_terms()` / `WP_Term_Query` with `'use_meilisearch' => true` | Always asked; served when every argument is translated |
| With `'use_meilisearch' => false` | Never |
| The classic editor's tag suggestions (`ajax-tag-search`) | Settings › Queries › Term searches of the classic editor |
| REST API term searches (`/wp/v2/categories?search=`, other indexed taxonomies), with their `X-WP-Total` count | Settings › Queries › REST API term searches |
| The admin's term lists (`edit-tags.php`) | Settings › Queries › Admin term lists |

All three are off on a new install. Filters: `meiliscout/skip_term_query_integration` and `meiliscout/integrate_term_query`, as for posts.

The taxonomies must be turned on in Content › Taxonomies, and indexed by a full indexation in schema 4 (`wp meiliscout status`). Until then, term queries run on MySQL (`schema_too_old`, `unindexed_taxonomy:<taxonomy>`).

## How

WordPress builds its SQL, then hands the query to `terms_pre_query`; when a plugin answers there, WordPress skips everything it does after its SQL. So MeiliScout does it too, the same way:

- The SQL's `WHERE` becomes the filter, its `ORDER BY` the sort, and its `LIMIT` the page, when WordPress sets one (a flat taxonomy, no `child_of`, no `parent`).
- Otherwise WordPress filters and pages in PHP: every matching term comes back from Meilisearch as a light hit (ids, parent, counts), and WordPress' own functions do the rest on them: `_get_term_children()` for `child_of`, `_pad_term_counts()` for `pad_counts`, the empty terms of a hierarchical taxonomy, the page. Only the terms returned are loaded, from the database, as WordPress loads them.
- The exclusions (`exclude`, `exclude_tree`, `childless`) are the ones WordPress worked out.

## Coverage

| Argument | State | Notes |
|---|---|---|
| `taxonomy` (one, several, none) | ✅ | None: every taxonomy the site has terms in must be indexed |
| `include`, `exclude`, `exclude_tree`, `childless` | ✅ | `include` wins over `exclude` and `exclude_tree`, as in WordPress |
| `name`, `slug`, `term_taxonomy_id` | ✅ | Names compared as MySQL's collation compares them (case and accents aside) |
| `parent`, `child_of` | ✅ | `parent` wins over `child_of` |
| `hide_empty` | ✅ | On a hierarchical taxonomy, an empty term stays when one of its descendants has posts (`tree_count`) |
| `hierarchical`, `pad_counts`, `get => 'all'` | ✅ | |
| `number`, `offset` | ✅ | With WordPress' quirks: a hierarchical query is paged after the empty terms are dropped; with `parent` and `hierarchical => false`, `number` is ignored |
| `fields` | ✅ | `all`, `ids`, `tt_ids`, `names`, `slugs`, `count`, `id=>name`, `id=>slug`, `id=>parent`. `count` is a numeric string, as `wp_count_terms()` gets it |
| `fields => 'all_with_object_id'` with `object_ids` | ❌ | MySQL: one row per post and term |
| `orderby` | ✅ | `name`, `slug`, `term_group`, `term_id`/`id`, `term_taxonomy_id`, `taxonomy`, `description`, `parent`, `count`, `none`, `include` and `slug__in` (in PHP), `meta_value`, `meta_value_num`, the meta key, a named `meta_query` clause. An unknown value is the name |
| `orderby => 'term_order'` with `object_ids` | ❌ | MySQL |
| `object_ids` | ✅ | The posts' terms, read in the posts index, when every post is indexed, of a type the taxonomies are for, with a status the index serves; otherwise MySQL (`unindexed_object:<id>`) |
| `search` | ✅ / ⚙️ | With Settings › Advanced › Partial filters on fields (`CONTAINS`): `name` or `slug` containing the text, as MySQL, same order and totals. Without: Meilisearch's search on the name and the slug, ranked by relevance |
| `name__like`, `description__like` | ⚙️ | With Partial filters on fields; MySQL otherwise (`unsupported_compare:LIKE`) |
| `meta_query`, `meta_key`, `meta_value`, `meta_compare`, `meta_type` | ✅ | The term meta keys selected in Content › Term fields; as for posts' metas (several values, serialized values, numbers: see [WP_QUERY.md](WP_QUERY.md#custom-fields)) |
| `cache_domain`, `cache_results`, `update_term_meta_cache` | ✅ | No effect on the terms returned |

Arguments `WP_Term_Query` does not know are no reason to fall back, unlike `WP_Query`'s: `wp_dropdown_categories()` and others pass their own, and a plugin can only act on them through the SQL filters, which are watched.

### Plugins changing the SQL

`terms_clauses`, `list_terms_exclusions`, `get_terms_orderby` and `get_terms_fields` run before Meilisearch is asked. When a plugin changed what one of them returns (a multilingual plugin restricting the terms to a language), the query runs on MySQL (`sql_filter:<hook>`). Declare a callback whose change the site translates itself with `meiliscout/ignored_term_sql_filters`.

## Freshness

A term is indexed again when it is saved, when its post count changes (a post published, unpublished, given or deprived of the term), and so are its ancestors, whose `tree_count` includes its posts; when a term moves or is deleted, its former ancestors and the children it leaves are too. A post whose terms change outside a save (`wp_set_object_terms()`) is indexed again, for `object_ids` and for `tax_query`. Terms are loaded from the database, so they are always fresh; which terms match is as fresh as the index.

## Known differences with MySQL

- **Keys with `pad_counts`.** WordPress returns the padded terms with gaps in their keys when it runs the query, and numbered from 0 when it reads them from its cache (a second call, or a persistent object cache). MeiliScout returns the latter.
- **Ties.** Terms that tie on the order (the same count) come in any order, on MySQL too.
- **Collation.** Names are folded (lowercase, without accents) for `name`, ordering and `LIKE`; MySQL's collation is close, not identical, for some characters.
- **Search without CONTAINS.** Ranked by relevance, words matched from their start, with typos: `press` finds PrestaShop, not WordPress. Turn Partial filters on for MySQL's matching.

## Why did my term query run on MySQL?

Reasons are recorded on the query (`$query->meiliscout['reason']`), counted on the Overview with a `terms:` prefix, and listed in the Query Monitor panel (`get_terms()` rows).

| Reason | Meaning | What to do |
|---|---|---|
| `unindexed_taxonomy:<taxonomy>` | A taxonomy the index lacks; without `taxonomy`, one the site has terms in | Content › Taxonomies, then a full indexation |
| `schema_too_old` | The taxonomies index predates schema 4 | Run a full indexation |
| `sql_filter:<hook>` | A plugin changed the query's SQL | `meiliscout/ignored_term_sql_filters` when the site translates it |
| `unsupported_compare:LIKE` | `name__like` or `description__like` without Partial filters | Settings › Advanced › Partial filters on fields |
| `unindexed_object:<id>` | A post of `object_ids` the index lacks, or a taxonomy that is not the posts' | |
| `undisplayed_attribute:<field>` | `object_ids` while the posts index does not return `ID` or `taxonomies` (an indexable narrowed `displayedAttributes`) | The plugin: keep them displayed |
| `unsupported_fields:all_with_object_id`, `unsupported_orderby:<value>` | Needs `term_relationships`, or an order on a key the index cannot sort | |
| `unindexed_meta:<key>`, `multivalued_meta:<key>`... | As for posts | Content › Term fields |
| `too_many_terms` | More matching terms than the maximum results per query, for a query WordPress filters in PHP | Settings › Advanced › Maximum results per query |
| `engine_error`, `unreachable`, `build_error` | As for posts | |

To try one: Search preview › Query arguments › get_terms(). On the command line, `wp meiliscout check-queries --terms` runs a set of term queries picked in the site's data, and `--terms --args='{"taxonomy": "category", "child_of": 3}'` one of yours.
