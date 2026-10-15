# Plan: search fields with autosuggestion

Status: **phases 0 (measures), 1 (thumbnail, schema 6), 2 (definition, key, token) and 3 (the field on a desktop) 4 (mobile sheet, recent searches) and 5 (the template engines: HTML, callable, class, Blade, Twig, theme part, JavaScript) built; 6 (the block, the core Search block and the REST transport) and 7 (the admin screen, `check-suggest`, the guide with screenshots) built; the release is next.** Written 2026-10-15 from the code of 2.4.0. Decisions marked **(proposed)** wait for the owner's answer ([Open questions](#open-questions)).

## Goals (from the brief)

1. As fast as possible: results as the visitor types, Meilisearch called **directly** from the browser.
2. Suggestion **templates**: configurable, and codeable (Blade, Timber/Twig, JS template, PHP).
3. Mobile first-class: the phone keyboard, little visible space.
4. Clean, commented code and an **extensible developer API**.

## What the plugin already gives

| Need | Already there | Gap |
|---|---|---|
| Browser → Meilisearch | Tenant tokens signed by the listings key (`Listings\Transport\TenantTokens`, `ListingsKey`), public host (`listings_public_host`), refresh endpoint | Tokens are **per listing** and the key lives in the *Listings* module; a suggest needs its own scope (base filter = published, no password, chosen post types) |
| Public fields | `Listings\PublicFields` allowlist (title, url, excerpt, date, terms, `card`…) | **No image field** in the documents (`grep thumbnail src` finds nothing): thumbnails need a new indexed field, hence a schema bump |
| Client-side rendering | `client_card`: markup bound to `context.hit` with Interactivity directives; `resources/listings/hits.js` turns a document into a card | Tied to a listing's store |
| Server-side card forms | `Render\Cards` (callable, part, `blade:`, `twig:`, `CardRenderer`), `Template\Blade`, `Template\TwigExtension` | Run per request, not per keystroke |
| Mobile | `<dialog>` filters panel, `matchMedia(max-width:781.98px)`, UI kit tokens `--meiliscout-*` | A search sheet |
| Validation | `ListingDefinition` (every error given), `DefinitionRegistry`, admin screen *Listings* | Same pattern for suggests |
| Taxonomy index | `TaxonomyIndexable`, `IndexNames` | A key/token for it |

Measured earlier (`bench-listings`, demo, 2026-10-09): a Meilisearch multi-search answers in **15–27 ms**, a WordPress fragment in **150–230 ms**, an empty `/wp-json/` in 180 ms. A suggest through PHP cannot be fast; direct is 7–10× quicker before network.

## Options

### A. Where the request goes

| | Latency | Cost | Verdict |
|---|---|---|---|
| **A1. Browser → Meilisearch with a tenant token** | ~20 ms + network | Needs a public Meilisearch URL with CORS; a token in the page | **Recommended** (the listings already require this) |
| A2. Browser → WP REST → Meilisearch | 150–250 ms | Works behind a private Meilisearch | Opt-in **fallback** when no public host is set; also what keeps working with a page cache that cannot carry a token |
| A3. `admin-ajax` | slower than A2 | none | Rejected |

Details for A1 that decide the feel: `Authorization` triggers a CORS **preflight** per request unless cached (`Access-Control-Max-Age`, which Meilisearch sets; verify on the demo and document the proxy case); keep the HTTP connection alive (one origin, HTTP/2); send the minimum: `limit` 5–8, `attributesToRetrieve` = fields the template uses, `attributesToHighlight` only the title, `attributesToCrop` none, `showRankingScore` off. Multi-search for several groups = one round trip.

### B. Templates ("render once, bind in the browser")

| | How | Per keystroke cost | Verdict |
|---|---|---|---|
| **B1. Compile once at page render into a `<template>` carrying bindings** | The dev writes the item in Blade/Twig/PHP/JS-ish markup against a small **binding vocabulary**; PHP compiles it once to markup with `data-wp-text`/`data-wp-bind` on `context.hit`; the client clones it per hit | zero PHP | **Recommended** default and only path for the direct transport |
| B2. JS function `(hit, helpers) => html` | Registered by the theme (`MeiliScout.suggest.template('id', fn)`) | zero PHP | Include: the "JS template" of the brief, escape by default via a tagged template `html\`\`` |
| B3. Server fragment per hit list | `Cards` forms rendered by a REST fragment | one WP request | Opt-in for rich markup (images from `wp_get_attachment_image`, shortcodes); forces transport A2 |
| B4. Raw HTML string from the hit | `innerHTML` | zero | **Rejected**: titles are data, XSS |

The binding vocabulary is the real design work: `{{ hit.title }}`, `hit.url`, `hit.image`, `hit.type`, `hit.terms.<tax>`, `hit.metas.<key>`, `hit.date`, plus the highlighted title as **segments** `[{text, match}]` (rendered with text nodes and `<mark>`, never as HTML). Blade: `<x-meiliscout::hit field="title" />` or `@meili('title')`; Twig: `{{ meili('title') }}`; PHP: `meiliscout_hit('title')` echoing the placeholder. Each emits the same binding markup, so there is **one** client contract, versioned like `PlanTemplate::VERSION`. Default templates ship for posts (title, type badge, optional thumbnail, excerpt) and terms (name, count).

### C. Scope of a field

A `Suggest` definition (validated once, every error given, like `ListingDefinition`) names: `sources` (groups: `posts` by type, `terms` by taxonomy, later `listings`), a limit and template per group, `min_chars`, `debounce`, `see_all` (URL of the results page: the site search or a listing, `?s=` or the listing's search parameter), `recent` (recent searches). The field is a real `GET` form: **without JS it submits to the results page** (progressive enhancement); with JS it becomes a combobox.

### D. Mobile

- Under the breakpoint a tap opens a **full-screen `<dialog>`** (same machinery as the filters sheet): input pinned at the top, results scroll below, close button, Back button closes it (a history entry), scroll lock.
- The keyboard must never cover results: size the results area with `visualViewport` (`resize`/`scroll` listeners → `--meiliscout-vh`), and set `interactive-widget=resizes-content` in the viewport meta only if the theme allows (it is the theme's tag: document it, do not rewrite it).
- `type="search"`, `inputmode="search"`, `enterkeyhint="search"`, `autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false"`; 44 px targets; 3–5 results; do not close on `blur` (touch scrolling blurs): decide with pointer events; recent searches in `localStorage` shown on empty focus.
- Desktop: a popover under the field, same listbox.

### E. Accessibility

ARIA 1.2 combobox: `role="combobox"` on the input, `aria-expanded`, `aria-controls` → listbox, `aria-activedescendant`, options `role="option"` in `role="group"` labelled per source; Up/Down/Home/End/Enter/Esc; a polite live region announcing the count ("5 results"); focus stays in the input; highlight built from text nodes.

### F. Speed discipline (client)

Debounce 100–150 ms and fire the **first** keystroke at once after the minimum; `AbortController` cancels the previous request; keep the previous results visible while loading (no flicker, no empty state until the answer is in); in-memory cache of the last N queries (key = normalised query + source set); optional preconnect on focus (`<link rel=preconnect>` injected, or a dummy health request); never fire for an unchanged normalised string.

## Architecture (proposed)

```
src/Suggest/
  Definition/   SuggestDefinition, SuggestRegistry, InvalidSuggest
  Transport/    SuggestKey (own key), SuggestTokens (tenant token, per scope), RestController (token + A2 fallback)
  Render/       Field (form + combobox markup), Templates (compile B1), Defaults
  Template/     Blade/Twig/PHP binding emitters sharing one vocabulary
  Blocks/       "Search with suggestions" block; enhancement of the core Search block
  Admin/        screen listing/checking suggests
resources/suggest/   suggest.js (view module), client.js (search, cache, abort), a11y.js, sheet.js, hits.js (shared with listings)
```

- **Independent of the listings module (proposed):** its own key (`SuggestKey`, search on the posts and taxonomies indexes), its own token. `PublicFields` must widen when *either* module runs, so the allowlist becomes "fields needed by any browser-facing feature"; add a **`thumbnail` field** (URL of a registered size, `schema 6`, with a read path for 5: no thumbnail, graceful).
- Developer API: `meiliscout_register_suggest($id, $args)` and `meiliscout_suggest($id)` to print a field; filters `meiliscout/suggest/definition`, `…/params` (search request), `…/token_lifetime`, `…/card`; JS events `meiliscout:suggest:open|results|select|close` on the field; `window.MeiliScout.suggest.template()`.
- Tokens: a token's rules carry the sources' base filters; the browser cannot widen them. Page caching: same hourly-expiry trick as the listings; refresh endpoint on 401/403.
- Tests: a shared fixture set (`tests/fixtures/suggest`) for the binding vocabulary and highlight segmentation, run by Pest and `npm run test:js` like the listings; integration test for the token's reach; PHPStan; an a11y check by script (roles/attributes) and screenshots at desktop and 390 px.

## Phases (proposed)

0. **Spike, measure** on the demo: preflight cost, A1 vs A2 timings, request size, keystroke-to-paint. Record numbers here.
1. Core: key/token, definition + registry, field markup, JS client (debounce, abort, cache), one default posts template (B1), a11y combobox, desktop popover. Block + `meiliscout_suggest()`.
2. Mobile sheet, recent searches, "see all".
3. Templates: PHP, Blade, Twig emitters; JS template API; `thumbnail` field and schema 6.
4. Terms group, per-group templates, A2 fallback, enhancement of the core Search block / theme forms by attribute.
5. Admin screen + `check-suggest`, docs and guide with screenshots, FR translations, release.

## Phase 0: measures (demo, 2026-10-15)

Demo site (DDEV, 70 réalisations + ~180 posts), requests from the host through DDEV's router (TLS, HTTP/2), a real tenant token of the `realisations` listing, `q=web`, `limit=6`, 20 requests on one connection:

| Path | Median | Min–max |
|---|---|---|
| Meilisearch **in the container**, no proxy (engine alone) | **4.4 ms** | 3.4–7.5 |
| **A1** browser → Meilisearch with a token (through DDEV's router) | **47.5 ms** | 44.8–49.7 |
| **A2** WP REST (`/wp-json/wp/v2/search`, Meilisearch on) | **166 ms** | 122–446 |
| First request of a page (new connection + TLS) | ~130–180 ms | |

- **A1 is 3.5× faster than A2 here, and the 47 ms is mostly the local proxy:** the engine answers in about 4 ms, so on a real server the cost is network + TLS, and with a warm connection the first keystroke after focus is the only slow one. That justifies the `preconnect` on focus ([F](#f-speed-discipline-client)).
- **CORS preflight:** Meilisearch answers `OPTIONS` with `access-control-allow-origin: *`, `allow-headers: authorization,content-type` and **`access-control-max-age: 86400`**: one preflight a day per browser and origin, not one per keystroke. No proxy setting is needed on a direct Meilisearch; a reverse proxy in front must pass these headers (to document).
- **Payload:** without `attributesToRetrieve` a 6-hit answer is **18.4 KB**; with `ID, post_title, url, post_type` + a highlighted title it is **2.9 KB** (437 bytes when gzipped). The template must therefore declare the fields it reads, and the client asks for those only (decision: the compiled template carries its `attributesToRetrieve`).
- Prefix search works on the last word as typed (`w`, `we`, `web`), each keystroke being one request of a few ms of engine time: no server-side cost worth a cache beyond the in-memory one.
- Not measured: keystroke-to-paint on a phone (needs a real device / throttled CPU), left for the phase 1 client.

Decision: **A1 by default, A2 as an opt-in fallback**, as proposed.

## Decisions (owner, 2026-10-15)

1. **Scope v1:** posts + terms; opt-in per field or block (no global replacement of the site's search form).
2. **Independence:** the autosuggest has **its own Meilisearch API key** (search only, posts and taxonomies indexes), distinct from the listings key, for security: rotating or leaking one never touches the other. Works without the Front listings module.
3. **Templates:** **every engine in v1**: default, JS, PHP, Blade and Twig (all emit the one binding markup).
4. **Thumbnails:** required, and first: a `thumbnail` field in the posts documents (schema 6, read path for 5 without images), exposed as a public field. It is also useful to the listings' cards.
5. **Recent searches:** browser `localStorage` only; no server-side analytics.
6. **Private Meilisearch:** v1 requires a public host; the REST fallback (A2) stays for phase 4.

Phase order after these answers: 0 (done) → **1 thumbnail field + schema 6** → 2 key, token and definition → 3 client and default template (desktop) → 4 mobile sheet and recent searches → 5 template engines (JS, PHP, Blade, Twig) → 6 terms group, block, core Search block → 7 admin screen, CLI, docs, FR, release. The REST fallback joins phase 6 if time allows.
