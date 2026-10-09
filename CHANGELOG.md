# Changelog

## Unreleased

### Indexing

- A full indexation without `--clear` removes the documents of posts and terms the database no longer has (deleted by an import, a restore, a direct SQL query), and of post types and taxonomies no longer indexed. They stayed searchable and counted.
- `wp meiliscout index --chunk-size` sent only the first post type: the chunk offset applied to each type. Every type is sent now, and `--clear --chunk-size` no longer leaves pages or custom types out of the index.
- `wp meiliscout index --chunk-size` failed on its first chunk when WP-CLI ran with `--path` or through a wrapper (`ddev wp`): each chunk now gets the run's global parameters.

## 2.0.0 (unreleased)

### WP_Query

- **Translate or fall back.** A query Meilisearch cannot answer as MySQL would now runs on MySQL, with the reason recorded, instead of returning other posts. About thirty arguments used to be ignored (`p`, `post__in`, `author`, `cat`, `year`, `offset`...): a category archive served by Meilisearch listed every post. See [docs/WP_QUERY.md](docs/WP_QUERY.md).
- Every `WP_Query` argument is translated but a few (`REGEXP`, `exact`, `perm`, `post_mime_type`, `title`...): posts by id, slug, parent and author, `date_query` and the date shortcuts, every `orderby` (`rand` and list orders in PHP), `meta_query` `LIKE` through Meilisearch's `CONTAINS` (Settings › Advanced), `sentence`, `search_columns`.
- Exact `found_posts` and `max_num_pages`, `fields` `ids` and `id=>parent` included; `posts_per_page => -1` up to Settings › Advanced › Maximum results per query (10,000, Meilisearch's default was 1,000).
- Posts are loaded from the database: the same objects MySQL gives (`meiliscout/hydrate_from_documents` to build them from the documents).
- A search with an explicit `orderby` follows it strictly (`sort` first in the ranking rules).
- Settings › Queries serves the site search, archives, REST searches and admin lists without `use_meilisearch` (all off by default).
- Content › Index private content (off by default): logged-in users get the private posts they may read.
- Debugging: `$query->meiliscout`, the `X-MeiliScout` header, a Query Monitor panel, Search preview › WP_Query arguments, fallbacks by reason on the overview, `wp meiliscout check-queries`.
- A plugin restricting posts through `posts_where`, `posts_clauses` and the other SQL filters sends the query to MySQL (`sql_filter:<hook>`), unless declared with `meiliscout/ignored_sql_filters`.
- `title`, `comment_status` and `ping_status` are translated (schema 4). Every value of a meta key is indexed, empty ones included; a comparison Meilisearch would make otherwise on a key's values (a negation over several values, serialized values, text in a numeric comparison) runs on MySQL.
- Media can be indexed (Content › Post types): attachments with their `inherit` status, their parent's status and their mime group; `post_mime_type`, `attachment`, `attachment_id` and the media library are served.
- A single post's query gets every status the index holds, as WordPress checks the status after the query.
- The integration suite checks that every `WP_Query` argument is translated or falls back with a case of its own.

### get_terms()

- `get_terms()` (`WP_Term_Query`) is served from the taxonomies index with `'use_meilisearch' => true`, or for the editor's term searches, REST term searches and admin term lists (Settings › Queries, off by default). Translate or fall back, as for posts: every argument, `child_of`, `pad_counts`, `hide_empty` on hierarchical taxonomies, `fields`, `object_ids` (through the posts index), metas, the plugins' SQL filters. See [docs/TERM_QUERY.md](docs/TERM_QUERY.md).
- Term meta keys are selected apart from the posts' (Content › Term fields); they used to take the posts' selection.
- Term counts are kept fresh, with their ancestors' tree counts; a full indexation no longer applies a chunk's offset to each taxonomy, nor creates the taxonomies index twice.
- `wp meiliscout check-queries --terms`, and a get_terms() mode in Search preview › Query arguments.

### Upgrading

The documents change (posts and terms: schema 4; each index now has its own version, so a later change to one index only rebuilds that one). Nothing breaks on update: queries that need the new fields run on MySQL until a full indexation rebuilds the indexes, which the admin asks for. See [docs/FILTERS.md](docs/FILTERS.md#upgrading-to-20).
