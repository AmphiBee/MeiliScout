# Search fields with suggestions

A step-by-step tutorial, with screenshots, is in [AUTOSUGGEST_GUIDE.md](AUTOSUGGEST_GUIDE.md); this page is the reference.

> **In progress** (design: [plans/2026-10-autosuggest.md](plans/2026-10-autosuggest.md)). Built: the declaration of a field, its key and token, the field itself on a desktop (markup, client, default suggestion). Built: the field, the phone's sheet, recent searches, the template engines, the block, the core Search block, the REST transport and the admin screen.

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
| `transport` | `direct` | `direct`: the browser asks Meilisearch. `rest`: it asks WordPress ([below](#a-meilisearch-browsers-cannot-reach)) |

A source:

| Option | Default | |
|---|---|---|
| `type` | `posts` | `posts` or `terms` |
| `post_types` / `taxonomies` | required | What the group reads; must be indexed |
| `limit` | `5` for posts, `3` for terms | 1 to 20 |
| `label` | | The group's heading |
| `template` | the default one | How a suggestion is drawn ([below](#drawing-a-suggestion)) |
| `fields` | `[]` | Public document fields the template reads, besides the default suggestion's |

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

### Drawing a suggestion

A source's `template` says how its suggestions are drawn. It is compiled **once, when the page is rendered**, into markup the client clones for every result: no PHP runs while the visitor types. Everything a template prints is *a binding*, never the data, and everything is bound as **text** (never as HTML).

| `template` | |
|---|---|
| *(none)* | The default: an image, the title with what was typed in bold, the type's label |
| `'<a …>…</a>'` | A string of HTML, bound with Interactivity directives by hand |
| `'blade:suggest.hit'` | A Blade view, with `$hit`, `$source`, `$suggest` |
| `'twig:suggest/hit.twig'` | A Twig template (Timber, or the environment given to `meiliscout/listings/twig`), with `hit`, `source`, `suggest` |
| `'meiliscout/suggest-hit'` | A template part of the theme (`meiliscout/suggest-hit.php`), with `$args['hit']`, `$args['source']`, `$args['suggest']` |
| `fn ($source, $suggest, $hit) => '…'` | A callable returning the markup (or `['markup' => …, 'fields' => […]]`) |
| `new MyHit` | A class implementing `Suggest\Template\SuggestTemplate` |
| `'js:my-hit'` | Drawn in the browser by a function the theme registers ([below](#a-javascript-template)) |

The engines (Blade, Twig) are the ones the front listings use: `meiliscout/listings/blade` and `meiliscout/listings/twig` hand over a factory or an environment.

`$hit` is the binder. Its methods print the attributes and elements that bind a suggestion:

| | |
|---|---|
| `$hit->option()` | The attributes of the root: an option of the list box, active under the keyboard. A template that leaves them out is wrapped in an element that has them |
| `$hit->href()` | The suggestion's address as a link's `href` |
| `$hit->text('title')` | A field as the element's text |
| `$hit->attr('src', 'image')` | A field as an attribute (never an `on…` handler) |
| `$hit->show('image')`, `$hit->hide('count')` | Show or hide the element on a field's value |
| `$hit->toggle('is-new', 'count')` | A class while a field has a value |
| `$hit->title()` | The title with its matches marked: a `span` per part, `meiliscout-suggest__match` on the typed ones |

```php
// PHP (a callable; the same in a theme part)
'template' => fn ($source, $suggest, $hit) => '<a class="hit" '.$hit->option().$hit->href().'>'
    .$hit->title().'<span '.$hit->text('metas._price').'></span></a>',
```

```blade
{{-- resources/views/suggest/hit.blade.php --}}
<a class="hit" {{ $hit->option() }} {{ $hit->href() }}>
    <img {{ $hit->attr('src', 'image') }} {{ $hit->show('image') }} alt="">
    {{ $hit->title() }}
    <small {{ $hit->text('meta') }}></small>
</a>
```

```twig
{# suggest/hit.twig #}
<a class="hit" {{ hit.option() }} {{ hit.href() }}>{{ hit.title() }} <small {{ hit.text('meta') }}></small></a>
```

A suggestion holds: `title`, `titleParts` (`{text, match}`), `url`, `type`, `meta` (the type's label), `image`, `imageAlt`, `count` (a term's posts), `excerpt`, `date`, `metas.<key>` (a key of `public_metas`) and `doc.<field>` (a field of the document as Meilisearch returns it). The document fields a template reads must be asked for: the default suggestion's, plus the source's `fields` (`post_excerpt`, `post_date`... for posts; `description`... for terms: the public fields only). A template asking for a field that is not a suggestion's is refused with an error naming what it holds.

A template that fails, or an engine the site does not have, falls back to the default suggestion (and logs why). `meiliscout/suggest/template` has the last word on a compiled template.

#### A JavaScript template

```js
// Registered by the theme, before or after the page's scripts: the field reads it when it draws.
( window.MeiliScout ||= {} ).suggestTemplates = {
    ...( window.MeiliScout.suggestTemplates || {} ),
    'my-hit': ( hit, { html } ) =>
        html`<a class="hit" href="${ hit.url }">
            ${ hit.titleParts.map( ( p ) => ( p.match ? html`<strong>${ p.text }</strong>` : p.text ) ) }
            <small>${ hit.meta }</small></a>`,
};
```

```php
'posts' => ['post_types' => ['post'], 'template' => 'js:my-hit', 'fields' => ['post_date']],
```

The function receives the suggestion and `{ html, escape }` and returns `html` markup, an element, or plain text (a link to the suggestion). `html` escapes everything it interpolates, unless it is `html` itself (or a list of them): a title never becomes markup. The returned element becomes the option (its `id`, `role`, `aria-selected` and `is-active` are set for it); a link without an `href` gets the suggestion's. A function that throws, or one not registered yet, shows the title.

### Look

`build/suggest/style.css` uses the front listings' UI kit tokens (the theme's presets, `--meiliscout-*`), under `:where()`: any rule of the theme wins. `meiliscout/suggest/load_styles` turns it off.

## In the editor

- **The *Search with suggestions* block** (MeiliScout category): the post types and taxonomies to suggest and how many of each, the label and placeholder, the characters before suggesting, recent searches, the results page and the transport, in the block's panel. Its field is made from those attributes and **saved with the post** (like the listing blocks) so that the token endpoint and the public fields know it on a request that does not render the block. Two blocks with the same settings are one field.
- **The core *Search* block**: when a field is declared in PHP, a *Suggestions (MeiliScout)* panel names it, and the block is printed as that field (its label and placeholder kept). Not declared (or the setting left on *None*): the block as WordPress prints it.

## A Meilisearch browsers cannot reach

`'transport' => 'rest'` (or the block's *Search through › WordPress*) sends the field's searches to `GET /wp-json/meiliscout/v1/suggest/{id}/search?q=` instead: WordPress asks Meilisearch with the same filters a token would add, and returns Meilisearch's own answers (cacheable for a minute per query). No token, no key and no public URL are needed, at the price of WordPress's start on every keystroke (about 150 ms against about 5 ms for the engine, see the plan's measures): reserve it for a private Meilisearch.

## Key and token

- **Its own Meilisearch key**, made by MeiliScout with the admin key the first time a field needs it: search only, on the posts and taxonomies indexes only. It is apart from the site's search key and from the listings' key: a leak or a rotation of one never touches the others.
- A **token** per field (and per language when a language plugin runs) carries the filter the browser cannot widen: the field's post types, published, without a password; the field's taxonomies. It expires on the hour, a day later (`meiliscout/suggest/token_lifetime`).
- `GET /wp-json/meiliscout/v1/suggest/{id}/token` gives a fresh one (`{value, exp, host}`), cacheable for five minutes.
- Browsers reach Meilisearch at its **public URL** (the one set for the listings, Settings › Listings). Meilisearch answers the CORS preflight once a day per browser (`access-control-max-age: 86400`); a reverse proxy in front of it must pass `authorization` and `content-type`.

## What browsers can read

While a field is declared (or the listings run), the posts index returns its **public fields** only (see [Public fields](LISTINGS.md#public-fields)): `thumbnail` is one. The taxonomies index returns a term's own fields (`term_id`, `term_taxonomy_id`, `taxonomy`, `name`, `slug`, `description`, `parent`, `count`, `tree_count`, `url`), never its metas. `meiliscout/term/displayed_attributes` has the last word.

A multilingual site: the posts token carries the request's language. The taxonomies index has no language field yet, so terms of every language are suggested.
