# Search fields with suggestions

> **In progress** (design: [plans/2026-10-autosuggest.md](plans/2026-10-autosuggest.md)). What exists today is the declaration of a field, its key and its token; the field itself (markup, client, templates) comes next.

A search field with suggestions asks Meilisearch **directly from the browser**, with a token that carries what the field may read. WordPress is not involved while the visitor types. It does not depend on the [front listings](LISTINGS.md) module.

## Declaring a field

```php
meiliscout_register_suggest('site', [
    'sources' => [
        'posts' => ['post_types' => ['post', 'page'], 'limit' => 5, 'label' => 'Pages'],
        'topics' => ['type' => 'terms', 'taxonomies' => ['category'], 'limit' => 3, 'label' => 'Topics'],
    ],
    'min_chars' => 2,
    'public_metas' => ['price'],
]);
```

Declare it from `functions.php` or a plugin, at any time before `wp_loaded`. A definition is validated once, when first used: every error is given at once (an unindexed post type, taxonomy or meta key, an unknown option).

| Option | Default | |
|---|---|---|
| `sources` | required | Groups of suggestions, in display order, by key (lowercase letters, digits, `-`, `_`) |
| `min_chars` | `2` | Characters before the first search (1 to 5) |
| `debounce` | `120` | Milliseconds waited after a keystroke (0 to 1000) |
| `search_param` | `s` | Parameter of the results page's address |
| `results_url` | the site's search page | Where "all results" leads |
| `recent` | `true` | Recent searches, kept in the visitor's browser only |
| `public_metas` | `[]` | Meta keys browsers may read (a template shows them); they must be indexed |

A source:

| Option | Default | |
|---|---|---|
| `type` | `posts` | `posts` or `terms` |
| `post_types` / `taxonomies` | required | What the group reads; must be indexed |
| `limit` | `5` for posts, `3` for terms | 1 to 20 |
| `label` | | The group's heading |
| `template` | the default one | A template reference or a callable (see the design) |

## Key and token

- **Its own Meilisearch key**, made by MeiliScout with the admin key the first time a field needs it: search only, on the posts and taxonomies indexes only. It is apart from the site's search key and from the listings' key: a leak or a rotation of one never touches the others.
- A **token** per field (and per language when a language plugin runs) carries the filter the browser cannot widen: the field's post types, published, without a password; the field's taxonomies. It expires on the hour, a day later (`meiliscout/suggest/token_lifetime`).
- `GET /wp-json/meiliscout/v1/suggest/{id}/token` gives a fresh one (`{value, exp, host}`), cacheable for five minutes.
- Browsers reach Meilisearch at its **public URL** (the one set for the listings, Settings › Listings). Meilisearch answers the CORS preflight once a day per browser (`access-control-max-age: 86400`); a reverse proxy in front of it must pass `authorization` and `content-type`.

## What browsers can read

While a field is declared (or the listings run), the posts index returns its **public fields** only (see [Public fields](LISTINGS.md#public-fields)): `thumbnail` is one. The taxonomies index returns a term's own fields (`term_id`, `term_taxonomy_id`, `taxonomy`, `name`, `slug`, `description`, `parent`, `count`, `tree_count`, `url`), never its metas. `meiliscout/term/displayed_attributes` has the last word.

A multilingual site: the posts token carries the request's language. The taxonomies index has no language field yet, so terms of every language are suggested.
