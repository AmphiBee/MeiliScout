# Front listings

Filterable listings of posts on the front end: facets with their counts, sorts, a search, active filters, pagination. Meilisearch counts the facets; the browser asks it directly, with a token that can only search the listing's posts. Without JavaScript, everything still works: the filters are a `GET` form, the pages real links.

The module is **off by default** and needs **WordPress 6.9** (the Interactivity API's router). It loads no script or style on a page without a listing.

Listings are declared in PHP and printed whole or part by part from PHP, Blade or Twig, or built in the block editor; their SEO is written with or without an SEO plugin, with rules per view; facets may go in the path; Polylang and WPML are supported. The design behind it: `docs/plans/2026-10-front-listings-design.md`.

## Turning it on

Settings › Listings:

- **Front listings**: the switch. Disabled, with the reason, on an older WordPress.
- **Public URL of Meilisearch**: where browsers reach Meilisearch, when the instance URL (Settings › Connection) is one only the server can reach (`http://meilisearch:7700` in Docker). Empty: the instance URL.
- **Listings key**: the key the tokens are signed with, and a button to replace it (see [Tokens](#tokens-and-the-listings-key)).
- **Default styles** and their colors (see [Styles](#styles)).

The screen also lists the declared listings with the errors of those that cannot be served, and the fields browsers can read. MeiliScout › Listings says more of each one ([below](#checking-a-listing)).

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

A definition is checked once, on first use. One that cannot be served (an unindexed post type or meta key, a reserved parameter, an unknown option...) renders nothing for visitors, and its errors for administrators, here, in Settings › Listings and in MeiliScout › Listings.

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
| `route` | the current URL | `['page' => $id]` or `['archive' => $postType]`: the listing's first page. Required for the [canonical redirect](#urls) and the [SEO](#seo) |
| `seo` | `true` | `false` leaves the robots, canonical and adjacent pages of its page to the SEO plugin, and its pages past the last one unanswered; `['max_depth' => 2, 'min_results' => 3]`: how many facets of the path, and how few results, a view may have to be indexed ([SEO](#seo)) |
| `sort_param`, `search_param` | `sort`, `q` | Names of these parameters in the URL |
| `card` | the theme's `meiliscout/card.php`, else a title, a date and an excerpt | The card of the `fragment` and `page` transports ([Cards](#cards)) |
| `client_card` | the theme's `meiliscout/client-card.php`, else a title, a date and an excerpt | The card of the `client` transport: markup bound to `context.hit` ([below](#the-client-transports-card)) |

A **facet**:

| Option | Default | |
|---|---|---|
| `source` | required | `taxonomy:<name>`, or `meta:<key>` for an indexed meta key (Content › Meta keys) |
| `type` | `list` | `list`, `range` (numbers between two bounds) or `boolean` (one checkbox); ranges and booleans read a meta key |
| `logic` | `or` | How the values of a list combine: `or` (any of them: each value widens the results) or `and` (all of them: each value narrows them). In an `or` list with a selection, a value not selected shows what it adds (`+3`); in an `and` list, the results it would leave. In the editor: the Facet block's *Several values match* |
| `hierarchy` | `tree` | A hierarchical taxonomy's terms are counted with their descendants and shown as a tree; `flat`: each term alone |
| `label` | the taxonomy's name, else the key | The facet's title |
| `labels` | `[]` | A meta facet's labels, by value (`['fr' => 'France']`) |
| `param` | the key | Its name in the URL |
| `path` | none | A taxonomy list's values in the path rather than a parameter: its prefix, `'path' => 'type'` gives `/projects/type-refonte/` (`true`: its `param`). Needs a `route` ([URLs](#urls)) |
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
- Facets marked `path` go in the path, after the listing's first page and before its page, in the definition's order: `{prefix}-{a},{b}`.
- A range is `min..max`, either side may be empty (`..5000`).
- The page in the path, as WordPress paginates.

```
/projects/type-refonte/page/2/?sector=Culture
```

On the listing's `route`, a URL in another form (a form sent without JavaScript, values in another order, a facet of the path sent as a parameter, segments in another order, `page/N` before them, a prefix in capitals...) is redirected (301) to its canonical form; a value of the path that is no term is a 404; other parameters (`utm_*`...) are kept after the listing's. WordPress's own `redirect_canonical()` is not run there: it would write the comma between two values as `%2C`.

Parameter names WordPress, WooCommerce or page caches read (`p`, `page`, `orderby`, `utm_*`, `filter_*`, the public query vars...) are refused.

The browser writes the same URLs: the PHP and JavaScript halves share their test cases (`tests/fixtures/listings`).

Facets in the path need no rewrite rule: on `do_parse_request`, the segments after a listing's first page are hidden from WordPress, which resolves the page (and its `/page/N/`) with its own rules; then they are put back. Only a path made of a listing's first page, its facet segments and a page is touched, and not one that is the path of a post (a child page named `type-…` stays that page, and hides the view of that term: MeiliScout › Listings names such pages). The order of the facets is the order of the URL: changing it, or a prefix, breaks the links shared so far. `comment` cannot be a prefix: WordPress writes `comment-page-2` after a page.

In the editor, a taxonomy facet goes in the path from its block's *URL* panel (*In the path*, and its prefix).

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
| `intro` | The heading and introduction of the [SEO rule](#seo-rules) of the state (empty without one) |
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
| `faq` | The questions and answers of the SEO rule of the state |

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
		window.dataLayer?.push( { event: 'listing', url: event.detail.url } );
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
  .meiliscout-listing__intro                (a router region; empty without an SEO rule)
    h1.meiliscout-listing__heading, .meiliscout-listing__text
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
  .meiliscout-listing__results              (the router region: role=region, named after its page, tabindex=-1; aria-busy while loading)
    ul.meiliscout-results > li.meiliscout-result > article.meiliscout-card (__title, __meta, __excerpt)
    p.meiliscout-results__empty
  nav.meiliscout-pagination > a.meiliscout-pagination__link (__previous, __number, __dots, __next; the current page and the dots without href)
  .meiliscout-listing__faq                  (a router region)
    details.meiliscout-faq__item > summary.meiliscout-faq__question, .meiliscout-faq__answer
```

A value no post of the current selection has (count 0) is hidden, unless it is selected.

## Accessibility

What a listing does for keyboard and screen reader users, without anything from the theme:

- **Without JavaScript**, it all works: the filters are a `GET` form with its *Apply* button (redirected to the canonical URL), the values leading to an indexable view and the pages are links, every value of a facet shows.
- **Native controls**: checkboxes in a `fieldset` named by its `legend`, labelled number fields, a labelled `select`; the focus is visible on each (`:focus-visible`, the accent's color).
- **After a filter**, the focus stays on the control used; the total (`aria-live="polite"`) says how many results there are now. The results' region is `aria-busy` while they load.
- **After a page link**, the link is gone with the region it was in: the focus moves to the results' region, named after its page (*Results, page 2 of 12*), which screen readers read out, and the page scrolls up to it when its top went past the screen's. The router's generic *Page loaded* is not announced.
- **Active filters** are buttons named after what they remove (*Remove filter: Culture*); *Show more* says whether it is expanded (`aria-expanded`); the current page is `aria-current="page"`, the dots are hidden from screen readers.
- Values hidden because no post has them (count 0) are `hidden`, not merely invisible.

A theme that restyles the listing keeps these as long as it keeps the markup; one that prints its own (developer mode) keeps them by printing the parts.

## SEO

What a listing's page tells search engines is decided by MeiliScout and written through the SEO plugin in use: Yoast SEO, Rank Math, SEOPress, All in One SEO, or WordPress alone. It applies to the listing whose `route` is the page (the first one, when several share it), from the `wp` action on.

| View | Robots | Canonical | `prev` / `next` | Structured data |
|---|---|---|---|---|
| The listing without filters, search or sort, with results: `/projects/`, `/projects/page/3/` | `index, follow`, at every page | itself, page included | yes | `ItemList`, `FAQPage` |
| Facets of the path only, one value each, two facets at most, three results at least: `/projects/type-refonte/`, `/projects/type-refonte/level-senior/page/2/` | `index, follow`, at every page | itself | yes | `ItemList`, `FAQPage` |
| Anything else: a parameter (a facet out of the path, a range), two values of a facet, a third facet, a search, another sort (`?sector=Culture`, `/type-a,b/`, `?sort=price`) | `noindex, follow` | none | none | none |
| A view without results, or with fewer than three | `noindex, follow` | none | none | none |
| A page past the last one (`/projects/page/99/`) | 404 | | | |

- The depth and the results a view of the path needs are the listing's `seo` (`max_depth`, `min_results`). A search is never indexed (decision G), a parameter never makes a view indexable.
- A facet's value that leads to an indexable view is a link to it (`<a href>` around its label; with JavaScript, a click ticks the box, and the link leaves the tab order: the box is the control): search engines find those views, and no others (design §8.5).
- The breadcrumb ends with the facets of the path, each one at the view made of it and those before: in Yoast's (`wpseo_breadcrumb_links`), Rank Math's (when its breadcrumb is on) and All in One SEO's trail, and in MeiliScout's own `BreadcrumbList`.
- The SEO plugin's setting that puts paginated pages in `noindex` (All in One SEO's default) does not apply to an indexable view: its pages are how its posts are found. The site's other archives keep it.
- A site closed to search engines (Settings › Reading) keeps WordPress's `noindex, nofollow`.
- Other parameters (`utm_*`...) do not change the view: its canonical leaves them out.
- The structured data joins Yoast's, Rank Math's or All in One SEO's graph; without them (WordPress, SEOPress), MeiliScout prints its own, with a `BreadcrumbList` (the site, the page's ancestors, the page, the facets of the path).

| Plugin | How |
|---|---|
| WordPress | `wp_robots`, `get_canonical_url` (a page), `pre_get_document_title`; canonical (an archive), `prev`/`next`, description and structured data on `wp_head`; the adjacent posts' links taken off a post holding a listing |
| Yoast SEO | `wpseo_robots`, `wpseo_canonical`, `prev`/`next` on its presentation (`wpseo_frontend_presentation`), `wpseo_title`, `wpseo_metadesc`, Open Graph, `wpseo_schema_graph` |
| Rank Math | `rank_math/frontend/robots`, `/canonical`, `/title`, `/description`, Open Graph, `rank_math/json_ld`; its adjacent links switched off, ours printed on `rank_math/head` |
| SEOPress | `seopress_titles_noindex_bypass`, `seopress_titles_nofollow`, `seopress_titles_canonical`, `/title`, `/desc`, Open Graph; its paged links emptied, ours and the structured data on `wp_head` |
| All in One SEO | `aioseo_robots_meta`, `aioseo_canonical_url`, `aioseo_prev_link`/`aioseo_next_link`, `aioseo_title`, `aioseo_description`, `aioseo_facebook_tags`, `aioseo_schema_output` |

Another SEO plugin: an `Adapter` of your own (`Pollora\MeiliScout\Listings\Seo\Adapters\Adapter`) through `meiliscout/listings/seo_adapter`. `meiliscout/listings/seo_view` changes the view itself (a `SeoView`), `meiliscout/listings/structured_data` its structured data.

Checked on Yoast 28.6, Rank Math 1.0.280, SEOPress 10.3 and All in One SEO 5.0.3 (`tests/Integration/SeoPluginsTest.php` reads each one's `<head>`).

### SEO rules

A rule gives views of a listing a **title**, a **meta description**, a **heading** (`h1`), an **introduction** and **questions**. MeiliScout › SEO rules (while the module runs) lists them, edits them, imports and exports them as CSV, and previews what any URL of a listing tells search engines.

A rule names its views by a key: the listing without filters (empty key), or one term (or any term, `*`) of up to two taxonomy facets, in the definition's order:

```
                        the listing without filters
type=12                 the term 12 of the facet type
type=*                  any one term of type
type=12|level=*         the term 12 of type and any one term of level
```

A view with a search, a range, a meta facet or two terms of one facet has no rule. Of the rules matching a view, the most specific wins (a term before `*`, the first facet first), in the view's language before a rule for every language (empty locale). The view's language is its locale (`fr_FR`), the site's without a multilingual plugin (`meiliscout/listings/seo_locale`).

- **Variables**: `{site}`, `{title}` (the listing's page), `{page}`, `{pages}`, `{total}`, and each facet's term by the facet's key (`{type}`). Past the first page, the title says which page it is (` - Page 2`), unless it holds `{page}`.
- The **title** and **description** go through the SEO plugin, and the title into the fragments' `<title>`.
- The **heading** and **introduction** are printed by the `intro` part (first in `meiliscout_listing()`, a *Listing introduction* block in the editor), the **questions** by the `faq` part, as `FAQPage` too on an indexable view. Both are router regions: they follow the filters. The introduction and questions show on the first page only. Leave the heading empty when the page prints its own `h1`. In the `client` transport, they hide once the visitor changed the state (no fragment brings the new ones).
- Rules are stored in the `{prefix}meiliscout_seo_rules` table, created on the admin's first visit.

CSV, one rule per row, columns in any order (`key_label` is written for people, not read back):

```csv
listing,locale,key,title,description,h1,intro,faq
projects,fr_FR,,Nos projets – {site},Les {total} projets de l'agence.,Nos projets,,
projects,,type=refonte,Refontes – {site},{total} refontes.,{type},,"[{""question"":""Combien de temps ?"",""answer"":""Six semaines.""}]"
```

Terms by id or slug (slugs become ids). A row of an existing listing, language and key replaces that rule.

```sh
wp meiliscout seo-rules list [--listing=<id>]
wp meiliscout seo-rules export [<file>] [--listing=<id>]
wp meiliscout seo-rules import <file> [--dry-run]
wp meiliscout seo-rules delete <id>...
wp meiliscout seo-rules preview /projects/page/2/?type=refonte
```

## Languages

With Polylang or WPML, a listing is one listing in every language (design §9):

- **Its page** is its route's translation in the request's language: `route => ['page' => 42]` serves `/projects/` and `/en/projects-en/`. A language without a translation of the page has no listing.
- **Its posts, counts and terms** are the language's: the base filter (and the browser's token) holds the language, the fragment and token endpoints are asked with `?lang=`.
- **Prefixes per language**: `'path' => ['fr' => 'type', 'en' => 'kind']` (the first one for a language not listed). Every language's prefix is read; the canonical URL has the language's (301).
- **A term of another language** in the path: the view in that language when its page has a translation (decision D, 301: `/projets/type-redesign/` → `/en/projects/kind-redesign/`), a 404 otherwise, or when the terms are of two languages.
- **The language switcher** goes to the same view in the other language, its terms translated (the page's link when one has no translation). **hreflang** (decision C): on an indexable view only, towards the translations that are indexable too (one count per language); none on the others.
- **Listing blocks** copied with a page into its translation stay one listing: each language renders its own block (its labels), the default language's block is the definition.
- **SEO rules** are looked up in the language's locale. *Copy to translations* (MeiliScout › SEO rules, `wp meiliscout seo-rules duplicate <id>`) creates the rule in the other languages with its terms translated and its text to translate; existing rules are kept.
- Polylang needs nothing more in MeiliScout: its language is a taxonomy, already indexed. WPML needs a full indexation once (the language goes in the documents, terms are read without its adjustment); until then, and for the post types it shows in their original language when untranslated, queries run on MySQL (`wpml_not_indexed`, `wpml_display_as_translated`, see [WP_QUERY.md](WP_QUERY.md)).

Another plugin: a `Pollora\MeiliScout\Listings\Language\LanguageAdapter` through `meiliscout/listings/language_adapter`.

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

## Performance

A change in a listing is two requests in parallel: the counts, from the browser to Meilisearch (no PHP), and the results (a fragment from WordPress, or nothing more in the `client` transport, whose cards come with the counts).

`wp meiliscout bench-listings <id>` times them for a few states of a routed listing (the first page, a later one, one facet, two facets): the median time to first byte of the page, of its fragment and of the counts' multi-search as the browser sends it, with their sizes. Requests leave from where the command runs.

On the demo site (DDEV, PHP 8.3, opcache, Redis, Polylang, 70 posts in French, 4 facets; 30 requests each, from the web container, 2026-10-09):

| | Time to first byte | Size (gzip) |
|---|---|---|
| Page | 310–370 ms | 120–130 KB (18–20 KB) |
| Fragment, listing in PHP | 150–230 ms | 2–7 KB (1–2 KB) |
| Fragment, listing block | 260–280 ms | 24–27 KB (3 KB) |
| Counts (Meilisearch multi-search) | 15–27 ms | 1–2 KB (0.5 KB) |
| An empty REST request (`/wp-json/`), for comparison | 180 ms | |

- The counts are the visible part of a change: tens of milliseconds, whatever WordPress costs.
- A fragment costs WordPress's start (and REST's) plus the listing's query and cards, about 25 ms here: what makes it slow is what makes every request slow (plugins, no object cache). The block's fragment renders the whole block again, its Post Template included.
- The fragment is a `GET` on the listing's canonical URL with `Cache-Control: public, max-age=60` (except a `personalised` listing): a page cache or a CDN in front of `/wp-json/meiliscout/v1/listings/*/fragment` serves it at the cost of a static file, and so does the page itself.
- The `client` transport skips WordPress on every change: its cards come in the counts' multi-search.

## Checking a listing

**MeiliScout › Listings** (while the module runs) shows every declared listing, from PHP or a block (with a link to the post holding it):

- its address in each language, a link to see it; its transport, sorts, query string parameters, and what search engines may index of it, with its number of [SEO rules](#seo-rules);
- its facets: source, type, parameter, prefix in the path by language;
- its **checks**: the errors that keep it from being served (its definition), a hierarchical facet on a posts index older than schema 5, post types the index has fewer published posts of than the site (an indexation late or failed), a taxonomy the listing's post types do not have, a route that is not published or has no translation in a language, two listings on one page, a post living where a view of a prefix would be (`/projects/type-x/` a child page), terms of several languages sharing a slug, a parameter WordPress or a plugin reads;
- **Compare the counts with MySQL**: the total and every value's count (the first 30 of each facet), as the page shows them, against the same `WP_Query` run on MySQL, in a language.

`wp meiliscout check-listings` runs the same checks and comparisons for every listing and language, then asks each routed listing's URLs over HTTP as a visitor would: its first page and fragment (200), a view of each facet of the path (200), a value that is no term (404), two values out of order (301 to the canonical URL). It exits with 1 on an error, a count that differs, or a URL answering otherwise: a check for a deployment. `wp meiliscout bench-listings` times them ([Performance](#performance)).

```sh
wp meiliscout check-listings
wp meiliscout check-listings --listing=projects --lang=en
wp meiliscout check-listings --skip-http --format=json
```

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
| `meiliscout/listings/seo_view` | A listing's `SeoView` (indexable, canonical, adjacent pages, the rule's fields), with its `ListingResult` |
| `meiliscout/listings/seo_adapter` | The `Adapter` writing the view through the SEO plugin in use |
| `meiliscout/listings/structured_data` | The structured data of an indexable view (`ItemList`, `FAQPage`, `BreadcrumbList`), `[]` for none |
| `meiliscout/listings/seo_locale` | The language SEO rules are looked up in (default: the language's locale) |
| `meiliscout/listings/language_adapter` | The multilingual plugin's `LanguageAdapter` (Polylang's, WPML's, or none) |

## Testing

- `vendor/bin/pest tests/Unit/Listings`: definitions, the URL codec, the facets' plan, cards, against the shared cases of `tests/fixtures/listings`.
- `npm run test:js`: the browser's codec, plan and cards against the same cases; `path-cases.json`: the paths read and written, and the values' links, by both halves.
- `composer test:integration` (on the demo site): the URL cases with WordPress's own functions, the builders on real terms, the cards' dates against `mysql2date()`; the parts, cards of every kind, Blade components and Twig functions (`ListingTemplatesTest`); the listing block, its saved definition, its regions and URLs, its fragment (`ListingBlocksTest`); SEO rules, their CSV and preview (`SeoRulesTest`); languages, with Polylang or WPML set up by the demo's `scripts/languages-polylang.php` or `languages-wpml.php` (`ListingLanguagesTest`); facets in the path over HTTP: canonical forms, 301, 404, a child page, links (`PathFacetsTest`); each SEO plugin's `<head>` over HTTP (`SeoPluginsTest`, the plugins turned on in the site's options in turn).
- `composer test:integration` also runs `ListingChecksTest`: the demo listings' counts against MySQL in every language, their URLs over HTTP, and the checks a listing declared by the test trips.
- `vendor/bin/pest tests/Unit/Listings/SeoPolicyTest.php tests/Unit/Listings/RuleKeyTest.php`: the indexing decision, the rules' variables and keys, the adapters' robots.
