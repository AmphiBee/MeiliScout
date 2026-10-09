# Front listings

Filterable listings of posts on the front end: facets with their counts, sorts, a search, active filters, pagination. Meilisearch counts the facets; the browser asks it directly, with a token that can only search the listing's posts. Without JavaScript, everything still works: the filters are a `GET` form, the pages real links.

The module is **off by default** and needs **WordPress 6.9** (the Interactivity API's router). It loads no script or style on a page without a listing.

> This is the module's core (phase 1 of `docs/plans/2026-10-front-listings-design.md`): listings declared in PHP and rendered by MeiliScout's markup. Blocks, Blade and Twig components, facets in the path, the SEO policy and languages come in the next phases.

## Turning it on

Settings › Listings:

- **Front listings**: the switch. Disabled, with the reason, on an older WordPress.
- **Public URL of Meilisearch**: where browsers reach Meilisearch, when the instance URL (Settings › Connection) is one only the server can reach (`http://meilisearch:7700` in Docker). Empty: the instance URL.
- **Listings key**: the key the tokens are signed with, and a button to replace it (see [Tokens](#tokens-and-the-listings-key)).

The screen also lists the declared listings with the errors of those that cannot be served, and the fields browsers can read.

A theme's code keeps working while the module is off: `meiliscout_register_listing()` and `meiliscout_listing()` exist, and a listing renders nothing.

## Declaring a listing

On `init` or earlier, in the theme or a plugin:

```php
add_action('init', function () {
    meiliscout_register_listing('projects', [
        'post_types' => ['project'],
        'per_page' => 12,
        'route' => ['page' => 42],
        'facets' => [
            'type' => ['source' => 'taxonomy:project_type', 'label' => 'Type'],
            'sector' => ['source' => 'meta:client_sector', 'label' => 'Sector'],
            'materials' => ['source' => 'meta:materials', 'logic' => 'and'],
            'price' => ['source' => 'meta:_price', 'type' => 'range', 'label' => 'Price'],
            'featured' => ['source' => 'meta:featured', 'type' => 'boolean', 'label' => 'Featured only'],
        ],
        'sorts' => [
            'recent' => ['label' => 'Newest first', 'orderby' => 'date', 'order' => 'DESC'],
            'price' => ['label' => 'Lowest price', 'orderby' => 'meta_value_num', 'meta_key' => '_price', 'order' => 'ASC'],
        ],
        'public_metas' => ['_price', 'client_sector'],
    ]);
});
```

Then, where it goes (a template, `the_content`, a shortcode of your own):

```php
meiliscout_listing('projects');                 // prints it
$html = meiliscout_get_listing('projects');     // returns it
```

A definition is checked once, on first use. One that cannot be served (an unindexed post type or meta key, a reserved parameter, an unknown option...) renders nothing for visitors, and its errors for administrators, here and in Settings › Listings.

### Arguments

| Argument | Default | |
|---|---|---|
| `post_types` | required | Indexed post types (Content › Post types) |
| `base` | `[]` | `tax_query` and `meta_query` every post of the listing matches, as `WP_Query` takes them |
| `per_page` | `12` | |
| `sorts` | newest first | `key => [label, orderby, order, meta_key]`; `orderby` is one of `date`, `modified`, `title`, `menu_order`, `comment_count`, `ID`, `meta_value`, `meta_value_num` (with a `meta_key`) |
| `default_sort` | the first sort | Left out of the URL |
| `facets` | `[]` | `key => facet` (below), in the order they show and appear in the URL |
| `transport` | `fragment` | How the results change: `fragment`, `client` or `page` ([Transports](#transports)) |
| `apply` | `instant` | `instant`: a change applies at once (typing waits for a pause); `button`: on the Apply button |
| `public_metas` | `[]` | Meta keys browsers may read: the client transport's cards show them ([Public fields](#public-fields)) |
| `personalised` | `false` | Cards that depend on the visitor: the fragment is asked for with the visitor's session, and never cached |
| `route` | the current URL | `['page' => $id]` or `['archive' => $postType]`: the listing's first page. Required for the [canonical redirect](#urls) |
| `sort_param`, `search_param` | `sort`, `q` | Names of these parameters in the URL |

A **facet**:

| Option | Default | |
|---|---|---|
| `source` | required | `taxonomy:<name>`, or `meta:<key>` for an indexed meta key (Content › Meta keys) |
| `type` | `list` | `list`, `range` (numbers between two bounds) or `boolean` (one checkbox); ranges and booleans read a meta key |
| `logic` | `or` | How the values of a list combine. `or`: the counts of the others ignore the facet's own selection |
| `hierarchy` | `tree` | A hierarchical taxonomy's terms are counted with their descendants and shown as a tree; `flat`: each term alone |
| `label` | the taxonomy's name, else the key | The facet's title |
| `labels` | `[]` | A meta facet's labels, by value (`['fr' => 'France']`) |
| `param` | the key | Its name in the URL |
| `decimals` | `0` | A range's precision |
| `value` | `'1'` | The meta value a boolean facet counts as yes |

Facets need the posts index in schema 5 (`wp meiliscout status`), which counts a term with its descendants: until a full indexation rebuilds it, listings are served without counts, with the `page` transport.

### What `meiliscout_listing()` takes

```php
meiliscout_listing('projects', [
    'card' => fn (WP_Post $post) => '<h3>'.esc_html(get_the_title($post)).'</h3>',
    'search' => false,
]);
```

| Argument | |
|---|---|
| `card` | The card of the `fragment` and `page` transports: a callable receiving the `WP_Post`, or the name of a template part. Default: the theme's `meiliscout/card.php` (it gets `$args['post']`), else a title, a date and an excerpt |
| `client_card` | The card of the `client` transport: markup bound to `context.hit` ([below](#the-client-transports-card)). Default: the theme's `meiliscout/client-card.php`, else a title, a date and an excerpt |
| `search` | Whether the listing has a search field (default `true`) |

## URLs

One state, one URL:

```
/projects/page/2/?type=refonte,site-vitrine&sector=Culture&price=1000..5000&featured=1&sort=price&q=shop
```

- The facets in the definition's order, then the sort (left out when it is the default), then the search.
- A list's values unique and sorted; terms by slug. A comma inside a value is written `%2C`.
- A range is `min..max`, either side may be empty (`..5000`).
- The page in the path, as WordPress paginates.

On the listing's `route`, a URL in another form (a form sent without JavaScript, values in another order, a default sort...) is redirected (301) to its canonical form; other parameters (`utm_*`...) are kept after the listing's. WordPress's own `redirect_canonical()` is not run there: it would write the comma between two values as `%2C`.

Parameter names WordPress, WooCommerce or page caches read (`p`, `page`, `orderby`, `utm_*`, `filter_*`, the public query vars...) are refused.

The browser writes the same URLs: the PHP and JavaScript halves share their test cases (`tests/fixtures/listings`).

## Transports

The facets' counts always come straight from Meilisearch: the browser sends one multi-search with the listing's token. The results come by one of three transports:

| `transport` | Results | When |
|---|---|---|
| `fragment` (default) | The listing's results and pagination as HTML, from `GET /wp-json/meiliscout/v1/listings/{id}/fragment?url=…`, put in place by the Interactivity API's router | Cards made by PHP, as on the page |
| `client` | Cards made in the browser from the documents Meilisearch returns with the counts: one request, no PHP | Cards made of [public fields](#public-fields) only |
| `page` | The whole page is loaded | Anything else |

A listing whose facets cannot be counted (Meilisearch unreachable, a clause it cannot translate) is served with the `page` transport, as the server rendered it. When anything fails in the browser, the page of the new state is loaded.

The results of the `fragment` and `page` transports come from a `WP_Query` asking for Meilisearch: translated, or run on MySQL like any other query ([WP_QUERY.md](WP_QUERY.md)). The fragment is public and cacheable (`Cache-Control: public, max-age=60`), except for a `personalised` listing (`private, no-store`).

### The client transport's card

Each card is bound to `context.hit` with the Interactivity API's directives; the server renders the first page with the same markup.

```php
meiliscout_listing('projects', [
    'client_card' => '<article class="card">'
        .'<h3><a data-wp-bind--href="context.hit.url" data-wp-text="context.hit.title"></a></h3>'
        .'<p><time data-wp-bind--datetime="context.hit.date" data-wp-text="context.hit.dateLabel"></time>'
        .' · <span data-wp-text="context.hit.metas._price"></span> €</p>'
        .'<p data-wp-text="context.hit.excerpt"></p>'
        .'</article>',
]);
```

| `context.hit.` | |
|---|---|
| `id`, `url`, `type` | |
| `title` | Text, entities decoded |
| `excerpt` | The excerpt, else the beginning of the content (`excerpt_length` words; around the words searched for, with a search) |
| `date`, `dateLabel` | `Y-m-d H:i:s`, and the date in the site's format and language (Settings › General) |
| `terms.<taxonomy>` | `[{ name, slug }]` |
| `metas.<key>` | The listing's `public_metas`: text, or a list of texts for a key with several values |

Everything is text: bind it with `data-wp-text`, never as HTML. A card that needs more (an image, a price formatted by PHP) uses the `fragment` transport.

## Markup and styles

The module prints plain markup with stable classes and **no styles**: the theme styles it. (Default styles that follow the theme's `theme.json` come with the blocks, in a later phase.)

```
.meiliscout-listing
  form.meiliscout-listing__filters
    .meiliscout-search  (__label, __input)
    fieldset.meiliscout-facet.meiliscout-facet--list|range|boolean  [data-facet]
      legend.meiliscout-facet__title
      ul.meiliscout-facet__options > li.meiliscout-facet__option[data-depth]
        label.meiliscout-facet__label > input.meiliscout-facet__input, .meiliscout-facet__text, .meiliscout-facet__count
      .meiliscout-range > label.meiliscout-range__bound (__label, __input)
    .meiliscout-listing__toolbar
      p.meiliscout-listing__total            (aria-live)
      ul.meiliscout-active > li.meiliscout-active__item > button.meiliscout-active__remove
      label.meiliscout-sort (__label, __select)
      button.meiliscout-listing__apply       (hidden when apply is instant)
      a.meiliscout-listing__reset
  .meiliscout-listing__results              (the router region; aria-busy while loading)
    ul.meiliscout-results > li.meiliscout-result > article.meiliscout-card (__title, __meta, __excerpt)
    p.meiliscout-results__empty
    nav.meiliscout-pagination > .meiliscout-pagination__link (__previous, __number, __next), .meiliscout-pagination__dots
```

A value no post of the current selection has (count 0) is hidden, unless it is selected.

## Public fields

Browsers search the posts index with a token, so while the module is on the index returns **public fields only** (its `displayedAttributes`): `ID`, `post_type`, `post_title`, `post_name`, `post_excerpt`, `post_parent`, `content_text`, `url`, `post_date`, `post_date_ts`, `terms`, `taxonomies`, `card`, and `metas.<key>` for the meta keys a listing declares in `public_metas`. A post's author, its other metas, its raw content are not returned to anyone. MeiliScout's own queries read nothing else.

- `meiliscout/post/displayed_attributes` keeps the last word: the list is its default.
- A changed list (a listing's `public_metas`, the module turned on or off) is a settings update of the index, no re-indexation, sent in the background by the next request (WP-Cron).
- `meiliscout/hydrate_from_documents` is ignored while the index returns only some fields: posts are loaded from the database (Settings › Listings says so).
- Only published posts without a password are searched: the token adds that filter, and the listing's `post_types` and `base`, to every search.

## Tokens and the listings key

- A key of its own, made by MeiliScout with the admin key the first time a listing renders: search only, on the posts index only. The admin key must be allowed to manage keys.
- Each listing has a tenant token signed with it, carrying the listing's filter. It is the same for every visitor and expires on the hour, a day later (`meiliscout/listings/token_lifetime`, in seconds, at least an hour): pages cached within the same hour carry the same token.
- An expired or refused token is asked for again once (`GET /wp-json/meiliscout/v1/listings/{id}/token`, cacheable 5 minutes), then the page is loaded.
- **Replace the key** (Settings › Listings) makes a new key and deletes the previous one: every token given so far is refused at once, browsers ask for a new one.

## Filters

| Filter | |
|---|---|
| `meiliscout/listings/token_lifetime` | How long a listing's token lasts, in seconds (default a day, at least an hour). Receives the `ListingDefinition` |
| `meiliscout/post/displayed_attributes` | The fields the posts index returns; while listings are on, its default is the list of public fields |
| `excerpt_length` | WordPress's: the length of a client card's excerpt |

## Testing

- `vendor/bin/pest tests/Unit/Listings`: definitions, the URL codec, the facets' plan, cards, against the shared cases of `tests/fixtures/listings`.
- `npm run test:js`: the browser's codec, plan and cards against the same cases.
- `composer test:integration` (on the demo site): the URL cases with WordPress's own functions, the builders on real terms, the cards' dates against `mysql2date()`.
