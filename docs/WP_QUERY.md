# WP_Query and Meilisearch

MeiliScout answers a `WP_Query` from Meilisearch when the query asks for it (`'use_meilisearch' => true`) or when the settings cover it (Settings › Queries). It returns the posts MySQL would have returned, in the same order, with the same `found_posts` and `max_num_pages`.

**Translate or fall back.** Every argument of the query is either translated faithfully, or the whole query runs on MySQL and the reason is recorded. A query is never answered by Meilisearch with an argument dropped.

```php
$query = new WP_Query([
    'use_meilisearch' => true,
    'post_type' => 'product',
    'tax_query' => [['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => 'shoes']],
    'meta_query' => [['key' => 'price', 'value' => 100, 'compare' => '<=', 'type' => 'NUMERIC']],
    'orderby' => 'meta_value_num',
    'meta_key' => 'price',
]);

$query->meiliscout; // ['served' => true, 'reason' => null, 'params' => [...], 'index' => '...', 'time' => 3.2]
```

`get_terms()` is served the same way, from the taxonomies index: see [TERM_QUERY.md](TERM_QUERY.md).

## Which queries Meilisearch serves

| Query | Served when |
|---|---|
| Any query with `'use_meilisearch' => true` | Always asked; served when every argument is translated |
| Any query with `'use_meilisearch' => false` | Never |
| The site search (main query) | Settings › Queries › Site search |
| Post type archives, categories, tags, custom taxonomies (main query) | Settings › Queries › Archives |
| REST API searches (`/wp/v2/posts?search=`, other indexed types) | Settings › Queries › REST API searches |
| Admin lists of posts (main query) | Settings › Queries › Admin lists |
| AJAX queries | Only with `use_meilisearch` |

All four settings are off on a new install. Filters: `meiliscout/skip_query_integration` (true keeps a query on MySQL) and `meiliscout/integrate_query` (the last word).

## Coverage

Arguments marked *v3* (*v4*) need the posts index in schema 3 (4): until a full indexation has rebuilt it after the update, queries using them run on MySQL (`schema_too_old`). `wp meiliscout status` shows each index's format.

### Types and statuses

| Argument | State | Notes |
|---|---|---|
| `post_type` (string, array, `any`) | ✅ | Empty: as WordPress (posts; the types of a custom taxonomy on its archive; every searchable type for a search). `any`: every type not excluded from search. A type that is not indexed and has posts → MySQL (`unindexed_type:<type>`) |
| `post_status` | ✅ | Indexed statuses only: `publish`, and `private` when Content › Index private content is on. Drafts, pending and scheduled posts → MySQL (`unindexed_status:<status>`). Default statuses as WordPress works them out: a logged-in user who can see private posts gets them, the admin's "All" list gets drafts; when the index lacks them and the site has some, MySQL |
| `comment_status`, `ping_status` | ✅ *(v4)* | |
| `title` | ✅ *(v4)* | The whole title, case and accents aside, as MySQL's collation compares it |
| `post_mime_type` | ✅ *(v4)* | As `wp_post_mime_type_where()` reads it: a group (`image`, `image/*`), a whole type, a subgroup (`*/svg+xml`), lists. Another wildcard → MySQL |
| `perm`, `post_password` | ❌ | MySQL |

### Posts, slugs, parents, authors *(v3)*

| Argument | State | Notes |
|---|---|---|
| `p`, `page_id`, `name`, `pagename` | ✅ | `pagename` is the page WordPress resolved from the path. A single post that exists with a status the query does not get from the index (a draft, a private post) → MySQL (`unindexed_status:singular`) |
| `post__in`, `post__not_in`, `post_name__in` | ✅ | WordPress' precedence: `p`, then `post__in`, then `post__not_in` |
| `post_parent` (0 included), `post_parent__in`, `post_parent__not_in` | ✅ | |
| `author` (list, negative ids), `author__in`, `author__not_in`, `author_name` | ✅ | `author__not_in` wins over `author__in`, as in WordPress |
| `has_password`, `comment_count` (number or `value`/`compare`), `menu_order` | ✅ | |
| `attachment`, `attachment_id`, `subpost`, `subpost_id` | ✅ | When media are indexed (Content › Post types › Media) |
| `withcomments` (a comment feed of several posts) | ❌ | MySQL |

### Media

Attachments are indexed when Content › Post types has Media on, with their status (`inherit`), their type by group and subgroup, and their parent's status. The media library (`post_status => 'inherit,private'`), `get_children()`, attachments by type or parent are served. On a taxonomy archive that may hold media, WordPress gives an attachment whose parent has one of the statuses asked for: so does MeiliScout. A parent changing status indexes its attachments again. Without Media, a query on attachments runs on MySQL (`unindexed_type:attachment`).

### Taxonomies

| Argument | State | Notes |
|---|---|---|
| `tax_query`, every field and operator, nested relations, `include_children` | ✅ | Operators in any case |
| `cat`, `category_name`, `category__in/__not_in/__and`, `tag`, `tag_id`, `tag__in/__not_in/__and`, `tag_slug__in/__and`, `taxonomy`/`term`, a taxonomy's query var | ✅ | Read from the clauses WordPress parsed |
| A clause without `taxonomy` (term_taxonomy_id across taxonomies) | ❌ | MySQL |

### Custom fields

Only meta keys selected in Content › Custom fields are in the documents. A query on another key runs on MySQL (`unindexed_meta:<key>`), and the key is offered in the Content screen.

| Argument | State | Notes |
|---|---|---|
| `meta_key`, `meta_value`, `meta_compare`, `meta_type`, `meta_query` | ✅ | Read as `WP_Meta_Query` reads them: a key without a value is "the key exists", `meta_value_num` is no filter (only an order), `!=` and `NOT IN` need the key |
| `=`, `!=`, `>`, `>=`, `<`, `<=`, `IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`, `EXISTS`, `NOT EXISTS` | ✅ | |
| `LIKE`, `NOT LIKE` | ⚙️ | With Settings › Advanced › Partial filters on fields (Meilisearch's experimental `CONTAINS`); otherwise MySQL (`unsupported_compare:LIKE`) |
| `REGEXP`, `NOT REGEXP`, `RLIKE` | ❌ | MySQL |
| `type` `BINARY`, `compare_key`, `type_key`, a list of keys | ❌ | MySQL |

From schema 4, documents hold every value of a key: a list when a post has several, and empty values too. What the values of each key are like is noted while posts are indexed, and the comparisons Meilisearch would make otherwise run on MySQL:

| Values of the key | On MySQL | Reason |
|---|---|---|
| Several per post | `!=`, `NOT IN`, `NOT LIKE`, `BETWEEN` on text, `= ''`, and any order on the key | `multivalued_meta:<key>`, `unsupported_orderby:<field>` |
| Serialized (arrays) | Every comparison but `EXISTS` and `NOT EXISTS`, and any order: MySQL compares the serialized text | `structured_meta:<key>` |
| Some are no numbers (`''` included) | A numeric `type` (MySQL casts text to 0), `>`/`<`/`BETWEEN` against a number | `meta_not_numeric:<key>` |
| Numbers and text mixed | Any order on the key: MySQL orders them all as text | `unsupported_orderby:<field>` |
| Changed by the site (`meiliscout/post/document` altered `metas.<key>`) | Every comparison and order: the index no longer holds MySQL's values | `altered_meta:<key>` |

`BETWEEN` on numbers with several values per post matches a post one of whose values is in the range, as MySQL does.

### Dates *(v3)*

| Argument | State | Notes |
|---|---|---|
| `date_query` | ✅ | A port of `WP_Date_Query`: `after`/`before` (strings and arrays), `inclusive`, `column` (`post_date`, `post_modified`, and their `_gmt` for ranges), every part (`year`, `month`, `week`, `day`, `dayofyear`, `dayofweek`, `dayofweek_iso`, `hour`, `minute`, `second`) with every `compare`, nested relations |
| `m`, `year`, `monthnum`, `w`, `day`, `hour`, `minute`, `second` | ✅ | |
| Parts of a `_gmt` column, other tables' columns | ❌ | MySQL |

### Search

| Argument | State | Notes |
|---|---|---|
| `s` | ✅ | Ranked by relevance. MySQL matches words with `LIKE`: results differ by nature, which is why the site search moves to Meilisearch |
| `sentence` | ✅ | A phrase search |
| `search_columns` | ✅ | `attributesToSearchOn`; MySQL when the index does not search a column |
| `exact` | ❌ | MySQL |

### Order

| `orderby` | State | Notes |
|---|---|---|
| none, `date`, `relevance` | ✅ | A search is ranked by relevance unless another order is asked for |
| `title`, `name`, `author`, `modified`, `parent`, `type`, `ID`, `menu_order`, `comment_count` | ✅ *(v3)* | Titles are compared lowercase and without accents, close to MySQL's collation |
| `meta_value`, `meta_value_num`, the meta key, a named `meta_query` clause | ✅ | Indexed keys only |
| `rand`, `RAND(seed)`, `post__in`, `post_name__in`, `post_parent__in` | ✅ | Put in order in PHP over every result, up to 1000 (`meiliscout/php_order_limit`); beyond, MySQL |
| `none` | ✅ | No order, as in WordPress |
| A search with an explicit `orderby` | ✅ | The order is followed strictly (`sort` comes first in the ranking rules) and every word must match, as with MySQL's `LIKE` |

### Paging and shape

| Argument | State | Notes |
|---|---|---|
| `posts_per_page`, `paged`, `offset`, `nopaging`, `posts_per_page => -1` | ✅ | Exact totals. All posts stop at Settings › Advanced › Maximum results per query (10,000); a warning is logged when a query reaches it |
| `no_found_rows` | ✅ | No total, as in WordPress |
| `fields` `ids`, `id=>parent` | ✅ | Never read the database |
| `ignore_sticky_posts`, `cache_results`, `update_post_*_cache`, `suppress_filters` | ✅ | Handled by WordPress after the query, as for MySQL |

### Anything else

A query var MeiliScout does not know runs the query on MySQL (`unsupported_arg:<name>`): a plugin may read it in a `posts_where` filter Meilisearch never sees. When a plugin turns its own var into a `tax_query` or a `meta_query` (on `parse_query`), declare it:

```php
add_filter('meiliscout/supported_query_vars', fn (array $vars) => [...$vars, 'lang']);
```

### Plugins changing the SQL

`posts_where`, `posts_join`, `posts_clauses`, `posts_request` and the other SQL filters of `WP_Query` run before Meilisearch is asked. A multilingual, membership or shop plugin restricting the posts there would be ignored: so MeiliScout compares what each of these filters returns with what it was given, and when a plugin changed it, the query runs on MySQL (`sql_filter:<hook>`). A plugin hooked on them that changes nothing for a query costs nothing. Queries with `suppress_filters` (`get_posts()`) are not concerned.

When the site translates a plugin's change itself (adding the language to the filter in `meiliscout/search_params`, for instance), declare the callback:

```php
add_filter('meiliscout/ignored_sql_filters', fn (array $ignored) => [...$ignored, 'My_Plugin::posts_where']);
```

## Known differences with MySQL

- **Numbers.** Numeric meta values are indexed as numbers: a comparison or an order without `type` (`CHAR` for MySQL) compares them as numbers where MySQL compares text (`'9' > '10'`). Meilisearch's answer is usually the one meant.
- **Casts.** `SIGNED` truncates the value compared to; MySQL truncates the stored values too (`10.7` is `10` for MySQL, `10.7` for Meilisearch).
- **Empty and repeated metas.** Until the posts index is in schema 4, empty values are not indexed (a post with an empty value does not have the key for `EXISTS`), and only the first value of a key repeated on a post is.
- **Ties.** Posts that tie on the order come in any order, on MySQL too: a page boundary inside a tie may hold other posts.
- **Weeks.** Documents carry the week for each first day of the week WordPress can be set to: changing it needs no indexation.
- **Freshness.** Posts are loaded from the database, so they are always fresh; but which posts match is as fresh as the index (immediate at the end of the request, up to 5 minutes with the async queue). A post deleted or unpublished since it was indexed is not returned.

## Why did my query run on MySQL?

Every fallback has a reason, recorded on the query (`$query->meiliscout['reason']`), counted on the Overview (last 24 hours, by reason), sent in the `X-MeiliScout` header of the main query (with `WP_DEBUG`, or for administrators) and listed in the Query Monitor panel.

| Reason | Meaning | What to do |
|---|---|---|
| `unsupported_arg:<var>` | A query var nothing translates | See the coverage above; `meiliscout/supported_query_vars` for a plugin's var |
| `unindexed_meta:<key>` | A meta key that is not indexed | Content › Custom fields, then a full indexation |
| `unindexed_status:<status>` | A status the index lacks, with posts | Private posts: Content › Index private content. Drafts are never indexed |
| `unindexed_status:singular` | A single post that exists with another status | Nothing: WordPress shows it to whoever may read it |
| `unindexed_type:<type>` | A post type that is not indexed, with posts | Content › Post types |
| `unsupported_compare:<op>` | `LIKE` (setting off), `REGEXP` | Settings › Advanced › Partial filters |
| `multivalued_meta:<key>`, `structured_meta:<key>`, `meta_not_numeric:<key>`, `altered_meta:<key>` | A comparison Meilisearch makes otherwise on the values of this key (see Custom fields) | |
| `sql_filter:<hook>` | A plugin changed the query's SQL through this filter | `meiliscout/ignored_sql_filters`, when the site translates the change |
| `unsupported_orderby:<field>` | An order the index cannot give, or `rand`/`post__in` over more than 1000 results | |
| `unsupported_date_column:<column>` | Parts of a GMT column, another table | |
| `schema_too_old` | The indexes predate the fields the query needs | Run a full indexation |
| `engine_error` | Meilisearch answered with an error | Indexation › Log, PHP error log |
| `unreachable` | Meilisearch could not be reached | Settings › Connection |
| `build_error` | The query could not be translated (a bug: please report it) | PHP error log |

To try a query: Search preview › WP_Query arguments runs it on both engines, side by side. On the command line, `wp meiliscout check-queries` runs a set of queries picked in the site's data, and `--args='{"cat": 3}'` one of yours. The integration suite checks that every argument WordPress knows is either translated or listed as falling back, with a case of its own.

## Coming from ElasticPress

| ElasticPress | MeiliScout |
|---|---|
| `'ep_integrate' => true` | `'use_meilisearch' => true` |
| `ep_skip_query_integration` | `meiliscout/skip_query_integration` |
| `ep_elasticpress_enabled` | `meiliscout/integrate_query` |
| Search, archives, admin integration features | Settings › Queries |
| Debug Bar, "Copy as cURL" | Query Monitor panel, `X-MeiliScout` header, Search preview › WP_Query arguments |
| Protected Content feature | Content › Index private content |

Differences: ElasticPress ignores `p`, `name`, `pagename`, `page_id`, `exact`, `sentence`, `comment_count`, `has_password => true` and `perm`, and turns `REGEXP` into an equality; it falls back to MySQL only when Elasticsearch fails. MeiliScout translates these or runs the query on MySQL. `author_name` is the author's slug here, as in WordPress (ElasticPress matches the display name).
