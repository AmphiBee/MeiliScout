# Search fields with suggestions

> **In progress** (design: [plans/2026-10-autosuggest.md](plans/2026-10-autosuggest.md)). Built: the declaration of a field, its key and token, the field itself on a desktop (markup, client, default suggestion). The phone's sheet and recent searches are built. Next: the template engines.

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

## Printing a field

```php
meiliscout_suggest('site', ['placeholder' => 'Search the site…']);   // prints
$html = meiliscout_get_suggest('site');                              // returns
```

Arguments: `placeholder`, `label` (the field's accessible name), `value` (the first value, the current search by default) and `class`. A shortcode or a block is up to the theme for now.

The field is a **real search form**: `GET` to `results_url` (the site's search page by default) with the query in `search_param`, so it works without JavaScript, and a visitor who presses Enter without choosing a suggestion gets the results page. With JavaScript it becomes a combobox (ARIA 1.2): the focus stays in the input, the arrows walk the suggestions (`aria-activedescendant`), Enter follows the active one, Escape closes, and a polite live region announces the count.

What the client does to be quick: one multi-search per keystroke (one search per source), a short debounce, the previous request aborted, the previous suggestions kept on screen until the new ones come, the last 20 answers remembered, the connection opened when the field takes the focus, and only the fields the template reads asked for.

### On a phone

Under 782 px (the listings' breakpoint) a tap on the field opens it as a **full-screen sheet**: the field at the top with a *Close* button, the suggestions filling the rest and scrolling, the page behind locked. It is the same input (the keyboard never closes while it opens), and the sheet:

- follows the **visual viewport**, so the keyboard never covers the suggestions;
- is closed by *Close*, Escape, or the **back button** (it pushes a history entry and takes it back);
- shows **3 suggestions per group** (`Field::MOBILE_LIMIT`), titles on two lines, 48 px targets and a 16 px input (iOS does not zoom);
- stays open when scrolling its list takes the keyboard away (the field is blurred on touch scroll, the sheet is not closed by it).

### Recent searches

Kept in the visitor's browser (`localStorage`, `meiliscout-suggest-recent:<id>`), five per field, last one first. A search is remembered when the form is submitted or a suggestion followed. They show when the field is empty (above the suggestions on a phone, under the field on a desktop), with *Clear recent searches*. Nothing is sent anywhere. `'recent' => false` turns them off; without storage (a private window) the field simply has none.

### A suggestion's markup

The default suggestion is an image, the title with what was typed in bold, and the type's label. It is bound to `context.hit` with the Interactivity API's directives and cloned for each result: it is compiled once, when the page is rendered, and no PHP runs while the visitor types. `meiliscout/suggest/template` replaces it per source (`['markup' => …, 'fields' => […]]`; the engines — Blade, Twig, PHP callables, JS — come next). What a template may bind:

| `context.hit.` | |
|---|---|
| `title`, `titleParts` | The title as text, and cut in `{text, match}` (`match` is what was typed) |
| `url`, `meta` | Its address, the type's label |
| `image`, `imageAlt` | The featured image (the `thumbnail` field of the index) |
| `count` | A term's number of posts |
| `excerpt` | When the template asks for `post_excerpt` |
| `metas.<key>` | A key of `public_metas` |

Everything is text: bind it with `data-wp-text`, never as HTML.

### Look

`build/suggest/style.css` uses the front listings' UI kit tokens (the theme's presets, `--meiliscout-*`), under `:where()`: any rule of the theme wins. `meiliscout/suggest/load_styles` turns it off.

## Key and token

- **Its own Meilisearch key**, made by MeiliScout with the admin key the first time a field needs it: search only, on the posts and taxonomies indexes only. It is apart from the site's search key and from the listings' key: a leak or a rotation of one never touches the others.
- A **token** per field (and per language when a language plugin runs) carries the filter the browser cannot widen: the field's post types, published, without a password; the field's taxonomies. It expires on the hour, a day later (`meiliscout/suggest/token_lifetime`).
- `GET /wp-json/meiliscout/v1/suggest/{id}/token` gives a fresh one (`{value, exp, host}`), cacheable for five minutes.
- Browsers reach Meilisearch at its **public URL** (the one set for the listings, Settings › Listings). Meilisearch answers the CORS preflight once a day per browser (`access-control-max-age: 86400`); a reverse proxy in front of it must pass `authorization` and `content-type`.

## What browsers can read

While a field is declared (or the listings run), the posts index returns its **public fields** only (see [Public fields](LISTINGS.md#public-fields)): `thumbnail` is one. The taxonomies index returns a term's own fields (`term_id`, `term_taxonomy_id`, `taxonomy`, `name`, `slug`, `description`, `parent`, `count`, `tree_count`, `url`), never its metas. `meiliscout/term/displayed_attributes` has the last word.

A multilingual site: the posts token carries the request's language. The taxonomies index has no language field yet, so terms of every language are suggested.
