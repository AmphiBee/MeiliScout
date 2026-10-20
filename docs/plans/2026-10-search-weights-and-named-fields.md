# Plan: search weights and named custom fields

Status: **phases 1 (named fields), 2 (weights engine), 3 (the interface) and 4 (suggest) and 5 (the guide) built.** Written 2026-10-19 from the code of 2.5.0. Decisions marked **(owner)** were taken by the owner; the ones marked **(proposed)** wait for an answer ([Open questions](#open-questions)).

## Goals (from the brief)

1. **Weights on the fields a search looks into**, as ElasticPress does: title, content, excerpt, taxonomies, custom fields… The interface must be ergonomic.
2. **Custom fields: never list them all.** The admin *defines what to add*, with a search, as WP Grid Builder does.
3. **Named custom fields.** Fields declared through ACF, Meta Box, WooCommerce (or anything else) appear under their **name** (“ACF › Project › Budget”, “WooCommerce › Price”) instead of their raw key. Same system as WP Grid Builder.
4. That naming and picking is also how the admin **selects what is indexed** (Content › Custom fields), not only how it weights.

## Decisions

| | Decision | |
|---|---|---|
| Weight model | **Real weights**, a number per field (0 = not searched), done with Meilisearch's federated search: one query per weight tier, merged in a single call | **(owner)** |
| Scope | **Per post type from v1**: each type has its own fields and weights | **(owner)** |
| Named-field sources, v1 | **ACF, WooCommerce, Meta Box**, and an **open filter** (`meiliscout/meta_fields`) for any other plugin or theme | **(owner)** |

## What was studied

### WP Grid Builder 2.3.6 (`amphibee/wp-grid-builder`, downloaded from the Ruche, read only)

- **One registry, one filter.** `wp_grid_builder/custom_fields` receives `[]` and each integration adds a group: `['Advanced Custom Fields' => ['acf/field_abc' => 'ACF > Group > Field'], 'WooCommerce' => ['_price' => 'WC > Price', …]]`. The filter takes a second argument, `key` or `name`, to ask for either form of the identifier.
- **ACF** reads `acf_get_field_groups()` / `acf_get_fields()` (filters suppressed, so every language is seen), walks sub fields of groups and repeaters into a hierarchical key (`acf/<field key>/<sub field key>`) and label (`Group > Repeater > Field`), and skips flexible content.
- **WooCommerce** is a fixed list of known keys with names (price, sale price, regular price, on sale, stock status, product type, featured, average rating).
- **Meta Box** is a separate add-on that feeds the same filter; the core excludes `meta-box/…` and `acf/…` ids from the raw search.
- **Never the whole table.** The REST route `GET metadata` has two modes: `registered` (searches the registry by key, label or source) and a **raw key search**: `SELECT DISTINCT meta_key … WHERE meta_key LIKE %term% AND NOT IN (registered keys, _edit_*, oembed) ORDER BY CHAR_LENGTH(meta_key) LIMIT 20`.
- **Values go through the plugin's own API** (ACF's formatted values: choice labels, relations, dates) via a `wp_grid_builder/metadata` filter.

### What MeiliScout already has

- `Config\SearchableAttributes`: the fields searched, **in order** (Meilisearch ranks an earlier attribute higher), saved as the index's `searchableAttributes`; the admin has up/down arrows (`Relevance` in `resources/admin/screens/Content.js`).
- `Services\MetaKeyCatalog` + `ContentController::metaKeys`: the meta keys of the indexable posts with a guessed type, a search and an “add” action; the picker already lists only what is asked for.
- `Query\Builders\SearchQueryBuilder` sets `q` and `attributesToSearchOn`; `QueryIntegration::search()` is the one place WP_Query searches reach Meilisearch.
- No names for meta keys, no weights, no per type setting.

### Meilisearch: no weights per field, but federation has them (measured 2026-10-19, demo, v1.54.3)

An index only orders its searchable attributes. **Federated search** (`/multi-search` with `federation`) multiplies each query's ranking score by `federationOptions.weight`. Two queries on the same index, each limited by `attributesToSearchOn`:

- hits are **not duplicated** (a post found by both queries appears once);
- title ×3 / content ×1 puts title matches first (score 0.997 → 0.957), the content-only matches follow (0.747); weights 1 / 3 invert them;
- cost, 344 documents, median of 25 runs: **plain 2 ms engine / 6 ms round trip; 2 queries 3 ms / 7 ms; 4 queries 4 ms / 8 ms**.

To confirm in phase 2: which score a post found by several queries keeps (the best, as `best_fields` in Elasticsearch, is expected), and that facets and totals stay right (they are asked apart, see below).

## Design

### 1. The field registry (`src/Fields/`)

- `MetaField` (value object): `key` (the meta key as stored, what `metas.<key>` is named in the index), `label` (“Project › Budget”), `group` (“Advanced Custom Fields”), `source` (`acf`, `metabox`, `woocommerce`, `custom`), optional `type` hint (`number`, `date`, `boolean`, `list`, `text`) that feeds the existing type guess.
- `FieldProvider` interface (`fields(): iterable<MetaField>`), implementations `AcfProvider`, `MetaBoxProvider`, `WooCommerceProvider`; each active only when its plugin is. `FieldRegistry::all()` merges them and the filter **`meiliscout/meta_fields`**, cached per request and in a transient flushed when a field group changes.
- A **PHP helper** `meiliscout_register_meta_field( $key, [ 'label' => …, 'group' => …, 'type' => … ] )` for themes (sugar over the filter).
- **ACF details** (as WPGB): the field's meta key is its `name`, hierarchical for groups (`group_sub`); repeaters store `rows_0_sub`, so a repeater's sub field is offered as a **pattern** (`rows_*_sub`, all rows indexed as one multi value key); flexible content skipped in v1. Fields are read with filters suppressed (every language).
- **Where names show**: Content (selected fields: name, raw key under it in mono), Relevance/weights, the Search preview (facets and hits), Overview, the Listings and Suggest screens (a facet `meta:budget` shows “Budget”), the editor blocks' field pickers. One lookup: `FieldRegistry::label( $key )`, falling back to the raw key.

### 2. Choosing what is indexed (Content › Custom fields)

- Replaces the free list with an **“Add a field” combobox**: (1) the **registered fields**, grouped by source and searchable by name, key or source, (2) below, a **raw key search** limited to 20 results that excludes registered keys (WPGB's two modes). Nothing is listed until the admin searches or opens a group.
- Quick adds: “WooCommerce › add price, sale price, stock” (a known set) when the plugin is active.
- Selected fields show their **name and source badge**; the existing type guess, remove and “found in your queries” hints stay.
- The same combobox is the source of the **weight rows** (below): adding a field to the weights also adds it to the indexed fields.

### 3. Weights

- **Setting** `search_weights`: `{ post_type: { field: weight } }`, weight a number 0–10 (0 = field not searched), fields `post_title`, `post_content` (`content_text`), `post_excerpt`, `terms.name` (all taxonomies; per taxonomy later), `post_name`, `metas.<key>`. Defaults per type (**proposed**): title 4, terms 2, excerpt 1.5, content 1, nothing else.
- **`Search\SearchWeights`**: resolves a type's profile. **`Search\WeightedSearch`**: turns the params of a search into a federated multi-search:
  1. for every type asked (from the filter's `post_type`), its profile; types with the same `(fields, weight)` tier share a query whose filter adds `post_type IN [...]`;
  2. one query per tier, `attributesToSearchOn` = the tier's fields, `federationOptions.weight` = the tier's weight, the other params (filter, `limit`, `offset`, `attributesToRetrieve`, highlight) copied;
  3. **no weighting** (plain search, today's behaviour) when there is no `q`, when `sort` is set (an order other than relevance), when every field of every type has the same weight, or when the engine refuses (falls back, logs the reason).
- **Totals and facets stay plain queries**: the count query and the facets already use `hitsPerPage => 0`; they search the **union of the enabled fields**, which is exactly what the index's `searchableAttributes` becomes (fields with weight 0 leave it), so counts agree with the weighted hits.
- **Index settings**: `searchableAttributes` = the union of enabled fields across types, sorted by their highest weight (so a plain search follows the same intent); a change is detected by the existing `Indexer` structure check (the “searchable” diff).
- **Meilisearch limits to design around**: `attributesToSearchOn` must be a subset of `searchableAttributes` (guaranteed by the union); federation does not take `sort`; the number of queries is *types × tiers*, kept low by merging identical tiers and by capping tiers (round weights to 0.5, at most 5 tiers per type — **proposed**).

### 4. Where weights apply

| Surface | How |
|---|---|
| WP_Query searches (site search, REST, `use_meilisearch`) | `QueryIntegration::search()` goes through `WeightedSearch`; `$query->meiliscout` records the tiers used |
| Admin Search preview | Same, plus an **explanation** per hit (`showRankingScore`, `_rankingScoreDetails`, the matched attributes) |
| Front listings | The hits query of `FacetPlan` becomes federated; `PlanTemplate::VERSION` bumps (the client replays a plan) |
| Suggest | Each source's search takes the weights of its post types (the token's search rules already allow the posts index) |
| Terms index | Not concerned (names/slugs only) |

### 5. The interface (Content › Relevance becomes “Search weights”)

- **One tab per post type** (only the indexed ones). In a tab, a table: *field* (name + raw key in mono + source badge) · **on/off switch** · **slider 0–10 with a number box** (keyboard and screen reader friendly: an `input[type=range]` and a number input kept in sync) · a bar showing the field's share of the total.
- **Presets** at the top of a tab (*Balanced*, *Title first*, *Content heavy*), “Reset to default”, “Copy to…” (copy this type's weights to others).
- **Add a field** (the combobox of §2) at the bottom; custom fields can be removed.
- **Live test** on the right: type a query, see the top 10 for that type with the **reason** of each rank (“Title ×4 · matched ‘woocommerce’”), computed with the *unsaved* weights, so the owner sees the effect before saving.
- Unsaved changes bar, a clear note when saving rebuilds the searchable attributes (Meilisearch re-indexes internally; no document rebuild).
- Follows the admin's existing look (tokens `--ms-*`, cards, `ms-switch`); French strings with non-breaking spaces; no new library (the slider is native).

### 6. API, CLI, portability

- Filters: `meiliscout/meta_fields`, `meiliscout/search_weights` (the resolved weights), `meiliscout/search_weights_tiers` (the planned queries).
- WP-CLI: `wp meiliscout weights get|set` (type, field, weight), `wp meiliscout fields list [--source=]`.
- Config export/import: `search_weights` joins the *Indexing* section (`Config\Portability\Sections\IndexingSection`), fields absent from the target are reported, not silently dropped.
- REST: `GET/POST /content/weights`, `GET /content/fields?search=&source=` (registered + raw), `POST /content/weights/preview`.

## Phases

| | Scope | Notes |
|---|---|---|
| 1 | **Registry and names** (`Fields/`, three providers, the filter, the helper); Content › Custom fields combobox with names; labels shown in the other screens | Needs ACF, Meta Box and WooCommerce installed on the demo (not there today) |
| 2 | **Weights engine** (`SearchWeights`, `WeightedSearch`, setting, index settings, `QueryIntegration`), REST, CLI; no UI yet; integration tests on the demo | Settles the open measures (merge semantics, facets, totals) |
| 3 | **The interface** (tabs, sliders, presets, live test with explanation), unsaved-changes handling | Real screenshots at desktop and 390 px before review |
| 4 | **Other surfaces**: suggest (done: one search per weight in the plan, merged by the client, plan version 2). Listings: decided against, they only sort explicitly (date, title, meta) so there is no relevance to weigh; `PlanTemplate::VERSION` stays 4 | Bumps `Suggest\Render\Plan::VERSION` |
| 5 | **Docs, guide with screenshots, French translations, CHANGELOG**, config export/import | House style |

One PR per phase, stacked, as for the autosuggest. Tests as usual: Pest unit (providers with stubs, tier planning), integration against the demo (a weighted ranking differs from a plain one in the expected direction, counts agree, fallbacks), JS tests for the field combobox and the slider logic.

## Risks and open points

- **Queries per search** = types × tiers; a site with many types and varied weights pays more (measured: +2 ms at 4 queries on 344 documents; to re-measure on a large index in phase 2).
- **ACF repeaters and flexible content** are partial in v1 (patterns for repeaters, flexible content skipped).
- **ACF formatted values** (choice labels instead of stored values) are *not* in v1: we index the stored value, as today. WPGB reads through ACF's API; to decide later per field.
- **Languages**: the registry reads ACF with filters suppressed (every language); weights are not per language.
- **A field removed from a post type's weights** but still indexed stays filterable; only its search weight goes to 0.
- **Documents format** is unchanged (`metas.<key>` exists), so no schema bump is expected; to confirm for `terms.name` per taxonomy if added.

## Open questions

1. **Default weights** per type (**proposed**: title 4, taxonomies 2, excerpt 1.5, content 1; WooCommerce products: SKU high). Same defaults for every type, or product-specific ones?
2. **Scale**: 0–10 (**proposed**) or 0–100 as ElasticPress? Fine-grained 0–100 invites false precision; tiers are rounded anyway.
3. **Meta Box and ACF versions** to support (ACF 6 + SCF? Meta Box 5+?).
4. **Taxonomies**: one weight for all taxonomies' terms (**proposed**) or one per taxonomy in v1?
5. **Releases**: one version with all phases, or ship phases 1 (names) and 2–3 (weights) separately? Phase 1 is useful alone.
