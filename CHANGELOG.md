# Changelog

## Unreleased

### WP_Query

- **Posts schema 5:** each term of a hierarchical taxonomy carries the ids of its ancestors (`taxonomies.<taxonomy>.tree`, the term first). `tax_query` with `include_children` (`cat`, `category_name`, archives…) is one `IN` on the tree instead of the list of every descendant, and a facet on the tree counts a parent with its children. `AND` keeps the list WordPress builds. A term moved to another parent, or whose parent is deleted, re-indexes the posts of its subtree.
- The same search asked twice in a request reaches Meilisearch once (`meiliscout/search_memo`): a Query Loop's pagination blocks run its query again, 3 searches out of 4 on a paginated loop. Forgotten when a post or its terms change during the request.

### Front listings

- **A new module, off by default** (Settings › Listings, WordPress 6.9): filterable listings of posts declared with `meiliscout_register_listing()` and printed with `meiliscout_listing()`. Facets on taxonomies (OR, AND, a term counted with its descendants) and meta keys (lists, ranges, booleans), sorts, a search, active filters, pagination; a `GET` form and real links without JavaScript, one canonical URL per state (301 to it). See [docs/LISTINGS.md](docs/LISTINGS.md).
- The browser counts the facets straight on Meilisearch, with a tenant token per listing signed by a key of their own (search only, posts index only), replaced from Settings › Listings. Results as an HTML fragment (default), as cards made in the browser from public fields (`transport => 'client'`), or by loading the page.
- **Developer mode:** the parts of a listing printed one by one, anywhere on the page (`meiliscout_listing_part()`, `meiliscout_facet()`, `meiliscout_active_filters()`, `meiliscout_listing_results()`, `meiliscout_pagination()`), Blade components (`<x-meiliscout::facet>`…) and Twig functions (Timber). Cards are part of the definition: a callable, a template part, a Blade view, a Twig template or a `CardRenderer`. DOM events `meiliscout:change` and `meiliscout:results`.
- **Editor mode:** the *Filterable listing* block holds the core's Post Template and pagination, *Facet* blocks and *Listing part* blocks (search, sort, total, active filters, apply, reset); its settings and errors in the editor, its definition saved with the post or template.
- **Default styles** that follow the theme (its fonts, its `theme.json` palette and spacing), colors set in Settings › Listings (automatic from the palette, a palette color or one of its own), off with a switch.
- While the module is on, the posts index returns public fields only: the ones a card shows, and the meta keys listings declare public (`meiliscout/post/displayed_attributes` keeps the last word). `meiliscout/hydrate_from_documents` is ignored while the index returns only some fields.
- **SEO:** a listing's page says what search engines may index (the listing, and facets of the path with one value each, two at most, enough results), with its canonical, adjacent pages and structured data (`ItemList`, `FAQPage`, breadcrumb), written through Yoast SEO, Rank Math, SEOPress, All in One SEO or WordPress alone; `noindex, follow` elsewhere, 404 past the last page. **SEO rules** give views a title, a description, a heading, an introduction and questions: MeiliScout › SEO rules, CSV import and export, `wp meiliscout seo-rules`.
- **Facets in the path** (`'path' => 'type'`: `/projects/type-refonte/`), without rewrite rules; 301 to the canonical form, 404 on a value that is no term. An OR facet's value shows what it adds to the selection (`+3`).
- **Languages:** Polylang and WPML. A listing's page, posts, counts and terms in each language, path prefixes per language, the language switcher and `hreflang` on indexable views, SEO rules copied to translations.
- **MeiliScout › Listings:** each declared listing, from PHP or a block, with its address in each language, facets, prefixes, parameters, its checks (indexed sources, a route without translation, a post hiding a view of a prefix, terms of several languages sharing a slug, reserved parameters, two listings on one page) and its counts compared with MySQL. `wp meiliscout check-listings` runs them, and asks each listing's URLs over HTTP (200, 301, 404, fragment); `wp meiliscout bench-listings` times a listing's page, fragment and counts.
- **Author facets** (`'source' => 'author'`): the posts' authors, by slug in the URL, counted on `post_author`; in the editor too.
- **A facet's search field** (`'search' => true`): typing narrows a long list's values, in the browser.
- **Sitemap:** the indexable views (each term of a facet of the path, the views of two facets an SEO rule names) in the sitemap of WordPress, Yoast SEO, Rank Math, SEOPress or All in One SEO, and at `/meiliscout-listings-sitemap.xml`.
- While the module runs, the posts index counts 1,000 values per facet instead of 100 (`meiliscout/post/max_values_per_facet`): a facet with more values offered only the first 100.
- The Facet block's settings offered no taxonomy on WordPress 6.9 (the listing block around it was not found).
- **Accessibility:** after a page link, the focus goes to the results, named after their page (*Results, page 2 of 12*), scrolled into view; a value's link leaves the tab order once its box is the control.

### Upgrading

The posts documents change (schema 5): until a full indexation rebuilds the posts index, queries list the descendants as before.

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
