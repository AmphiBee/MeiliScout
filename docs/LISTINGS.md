# Front listings

Filterable listings of posts on the front end: facets with their counts, sorts, a search, active filters, pagination. Meilisearch counts the facets; the browser asks it directly, with a token that can only search the listing's posts. Without JavaScript, everything still works: the filters are a `GET` form, the pages real links.

The module is **off by default** and needs **WordPress 6.9** (the Interactivity API's router). It loads no script or style on a page without a listing.

> Phases 1 to 3 of `docs/plans/2026-10-front-listings-design.md`: listings declared in PHP and printed whole or part by part from PHP, Blade or Twig, or built in the block editor. Facets in the path, the SEO policy and languages come in the next phases.

## Turning it on

Settings › Listings:

- **Front listings**: the switch. Disabled, with the reason, on an older WordPress.
- **Public URL of Meilisearch**: where browsers reach Meilisearch, when the instance URL (Settings › Connection) is one only the server can reach (`http://meilisearch:7700` in Docker). Empty: the instance URL.
- **Listings key**: the key the tokens are signed with, and a button to replace it (see [Tokens](#tokens-and-the-listings-key)).
- **Default styles** and their colors (see [Styles](#styles)).

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
| `apply` | `instant` | `instant`: a change applies at once (typing waits for a pause). `button`: a change only counts, the button says *See N results*, and the results, the URL and the history change when it is pressed |
| `public_metas` | `[]` | Meta keys browsers may read: the client transport's cards show them ([Public fields](#public-fields)) |
| `personalised` | `false` | Cards that depend on the visitor: the fragment is asked for with the visitor's session, and never cached |
| `route` | the current URL | `['page' => $id]` or `['archive' => $postType]`: the listing's first page. Required for the [canonical redirect](#urls) |
| `sort_param`, `search_param` | `sort`, `q` | Names of these parameters in the URL |
| `card` | the theme's `meiliscout/card.php`, else a title, a date and an excerpt | The card of the `fragment` and `page` transports ([Cards](#cards)) |
| `client_card` | the theme's `meiliscout/client-card.php`, else a title, a date and an excerpt | The card of the `client` transport: markup bound to `context.hit` ([below](#the-client-transports-card)) |

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
| `limit` | `0` | Past that many values shown, the others fold behind a *Show more* button (a selected value never folds; without JavaScript, all show). `0`: no limit |
| `decimals` | `0` | A range's precision |
| `value` | `'1'` | The meta value a boolean facet counts as yes |

Facets need the posts index in schema 5 (`wp meiliscout status`), which counts a term with its descendants: until a full indexation rebuilds it, listings are served without counts, with the `page` transport.

### Cards

A listing's cards are part of its definition, so that the page and the fragments the browser asks for render the same ones:

```php
'card' => fn (WP_Post $post, ListingDefinition $listing) => '<h3>'.esc_html(get_the_title($post)).'</h3>',
'card' => 'partials/project-card',     // a template part of the theme (it gets $args['post'])
'card' => 'blade:partials.project-card', // a Blade view, with $post and $listing
'card' => 'twig:partials/project-card.twig', // a Twig template, with post (Timber's) and listing
'card' => new ProjectCards,             // a Pollora\MeiliScout\Listings\Render\CardRenderer
```

Blade cards use the application's view factory (Pollora, Acorn), Twig cards Timber; `meiliscout/listings/blade` and `meiliscout/listings/twig` hand over others. When the engine is missing, the default card is used and the reason logged. `meiliscout/listings/card` filters every card's HTML.

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
meiliscout_register_listing('projects', [
    // ...
    'transport' => 'client',
    'public_metas' => ['_price'],
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

## Editor mode: the Filterable listing block

In the block editor, insert **Filterable listing**. It starts with a search, a facet and the apply button in a narrow column, and the total, the active filters, a **Post Template** and the core's **pagination** in a wide one: the core's blocks, styled and arranged as anywhere else.

- **The block's settings**: the content types (the indexed ones), items per page, whether filters apply at once or with a button, whether results change in place or by loading the page, the sorts.
- **Facet** blocks, anywhere inside the listing: what each one filters on (a taxonomy of the chosen types, or an indexed field), how its values combine, its title, how many values show before *Show more*, its name in the URL. Their order in the document is the URL's order. Block supports (colors, typography, spacing, border) apply to each one.
- **Listing part** blocks: Listing search, Listing sort, Listing total, Active filters, Apply filters, Reset filters.
- A listing that cannot be served says why in the editor (an unindexed field, a reserved name...). A warning names the blocks of the Post Template that would make the results load the whole page (blocks that do not declare `interactivity.clientNavigation`).
- The listing's definition is saved with the post (`save_post`, site editor templates included): the fragment and token endpoints, the public fields and Settings › Listings know it. A listing in a page or a post has it as its route; in a template, the current URL.
- The Post Template's query is the listing's; the core pagination's links are the listing's canonical URLs (`/page/2/?…`), loaded in place.
- A facet taking its name from a taxonomy that WordPress reads as a query var (most do) is named after its title in the URL (`?type-de-projet=…`), unless given one.

Not in this version: the `client` transport (cards come from the Post Template, rendered by PHP), and a Post Template inheriting the main query (archive templates): the block runs its own query.

While the module is off, the blocks print nothing.

## Developer mode: parts

`meiliscout_listing()` prints the whole listing in MeiliScout's layout. A template that wants its own prints the parts one by one, anywhere on the page, in any order:

```php
<aside>
    <?php meiliscout_listing_part('projects', 'search'); ?>
    <?php meiliscout_facet('projects', 'type'); ?>
    <?php meiliscout_facet('projects', 'price'); ?>
    <?php meiliscout_listing_part('projects', 'apply'); ?>
</aside>
<main>
    <?php meiliscout_listing_part('projects', 'total'); ?>
    <?php meiliscout_listing_part('projects', 'sort'); ?>
    <?php meiliscout_active_filters('projects'); ?>
    <?php meiliscout_listing_results('projects'); ?>
    <?php meiliscout_pagination('projects'); ?>
</main>
```

| Part | |
|---|---|
| `search` | The search field |
| `facet` | One facet: `meiliscout_facet($id, $key)`, or `['facet' => $key]` |
| `facets` | Every facet, in the definition's order |
| `sort` | The sort select (when the listing has two sorts or more) |
| `total` | The number of results, announced to screen readers |
| `active` | The active filters, each a button removing it: `meiliscout_active_filters()` |
| `apply` | The button sending the filters: needed by a page without JavaScript, hidden by the client when changes apply at once |
| `reset` | The link back to the listing without filters |
| `results` | The cards: `meiliscout_listing_results()` |
| `pagination` | `meiliscout_pagination()` |

`meiliscout_get_listing_part()` returns a part instead of printing it. The listing runs once per page, whatever its parts. Every field belongs to the listing's one form (`form="…"`), printed with the first part: without JavaScript, a form spread over the page still sends every field — print the `apply` part for it to be sent.

The listing's state is printed by `wp_footer()`: a template without it does not hydrate (`WP_DEBUG` reports it).

### Blade

On a site running Laravel's Blade (Pollora, Acorn), the components are registered on `init`:

```blade
<x-meiliscout::listing id="projects" />

<x-meiliscout::facet listing="projects" facet="type" />
<x-meiliscout::part listing="projects" part="sort" />
<x-meiliscout::active-filters listing="projects" />
<x-meiliscout::results listing="projects" />
<x-meiliscout::pagination listing="projects" />
```

Another Blade compiler: `Pollora\MeiliScout\Listings\Template\Blade::register($compiler)`.

### Twig

With Timber, the functions are added to its environment (`timber/twig`); another one: `$twig->addExtension(new Pollora\MeiliScout\Listings\Template\TwigExtension)`. Their HTML is not escaped.

```twig
{{ meiliscout_listing('projects') }}

{{ meiliscout_facet('projects', 'type') }}
{{ meiliscout_listing_part('projects', 'sort') }}
{{ meiliscout_active_filters('projects') }}
{{ meiliscout_listing_results('projects') }}
{{ meiliscout_pagination('projects') }}
```

While the module is off, the functions and components print nothing.

### Events

The listing's form sends DOM events, which bubble to the document; `event.detail.listing` is the listing's id:

| Event | `detail` | |
|---|---|---|
| `meiliscout:change` | `url`, `state` | A state is asked for: its counts and results are on their way |
| `meiliscout:results` | `url`, `total` | Its results are in place |

```js
document.addEventListener( 'meiliscout:results', ( event ) => {
	if ( event.detail.listing === 'projects' ) {
		document.getElementById( 'projects' ).scrollIntoView();
	}
} );
```

## Markup and styles

### Styles

The module ships a light default look (`build/listings/style.css`), loaded with the listings, that follows the theme:

- it inherits the theme's fonts and sizes, uses its `theme.json` presets when there are some (`--wp--preset--color--*`, `--wp--preset--spacing--*`) and `currentColor` otherwise;
- the look is under `:where()`, no specificity: any rule of the theme wins; the structure (lists without bullets, a link-like *Show more*) has one class, enough against a theme's rules on bare `ul`, `button` or `fieldset`, not against its rules on these classes;
- native controls (checkboxes, number fields, select), with `accent-color` and a visible focus; no font, no reset.

Its colors are tokens a theme sets in a line, or Settings › Listings: **accent**, **text on the accent**, **text**, **background of the fields**. Automatic by default: the theme palette's `primary`, `accent` or `brand` for the accent, else its text color (`contrast`, `foreground`…), `base`/`background` for the background. Each can be a color of the palette or one of its own.

```css
.my-theme {
	--meiliscout-color-accent: #0a7;
	--meiliscout-color-accent-contrast: #fff;
	--meiliscout-border-radius: 0;
	--meiliscout-spacing: 1.25rem;
}
```

Turn the default look off (Settings › Listings › Default styles, or `meiliscout/listings/load_styles`) and the markup below is the theme's to style. A listing printed from PHP loads the stylesheet in the footer; enqueue `meiliscout-listings` on `wp_enqueue_scripts` to have it in the `<head>`. Blocks load it in the `<head>` themselves.

### Markup

```
.meiliscout-listing
  form.meiliscout-listing__filters
    .meiliscout-search  (__label, __input)
    fieldset.meiliscout-facet.meiliscout-facet--list|range|boolean  [data-facet]
      legend.meiliscout-facet__title
      ul.meiliscout-facet__options > li.meiliscout-facet__option[data-depth]
        label.meiliscout-facet__label > input.meiliscout-facet__input, .meiliscout-facet__text, .meiliscout-facet__count
      button.meiliscout-facet__more          (past the facet's limit; aria-expanded)
      .meiliscout-range > label.meiliscout-range__bound (__label, __input)
    .meiliscout-listing__toolbar
      p.meiliscout-listing__total            (aria-live)
      ul.meiliscout-active > li.meiliscout-active__item > button.meiliscout-active__remove
      label.meiliscout-sort (__label, __select)
      button.meiliscout-listing__apply       (hidden when apply is instant; See N results in button mode)
      a.meiliscout-listing__reset
  .meiliscout-listing__results              (the router region; aria-busy while loading)
    ul.meiliscout-results > li.meiliscout-result > article.meiliscout-card (__title, __meta, __excerpt)
    p.meiliscout-results__empty
  nav.meiliscout-pagination > a.meiliscout-pagination__link (__previous, __number, __dots, __next; the current page and the dots without href)
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
| `meiliscout/listings/card` | A card's HTML (`fragment` and `page` transports), with the `WP_Post` and the `ListingDefinition` |
| `meiliscout/listings/blade` | The Blade view factory of `blade:` cards (default: the application's `view`) |
| `meiliscout/listings/twig` | The `Twig\Environment` of `twig:` cards (default: Timber's) |
| `meiliscout/listings/load_styles` | Whether the default look is loaded (default: Settings › Listings › Default styles, on) |
| `meiliscout/listings/theme_colors` | The default look's colors by token (`accent`, `accent-contrast`, `text`, `muted`, `background`, `border`): any CSS color |

## Testing

- `vendor/bin/pest tests/Unit/Listings`: definitions, the URL codec, the facets' plan, cards, against the shared cases of `tests/fixtures/listings`.
- `npm run test:js`: the browser's codec, plan and cards against the same cases.
- `composer test:integration` (on the demo site): the URL cases with WordPress's own functions, the builders on real terms, the cards' dates against `mysql2date()`; the parts, cards of every kind, Blade components and Twig functions (`ListingTemplatesTest`); the listing block, its saved definition, its regions and URLs, its fragment (`ListingBlocksTest`).
