# Plan: exporting and importing MeiliScout's configuration

Status: built for 2.3 (core, REST, admin screen, WP-CLI, tests) and documented in [CONFIGURATION.md](../CONFIGURATION.md#11-export-and-import-the-configuration). Not built: `--resync-blocks`.

What differs from the proposal below, found by reading the code:

- The allow-list also carries `index_private`, the term meta keys (`TaxonomyIndexable::META_KEYS_SETTING`) and `query_integration` / `term_query_integration`.
- The *Partial filters (LIKE)* setting (`ContainsFilter`) is not carried: it mirrors whether an experimental feature is on for the Meilisearch **instance**, so writing it blindly on another server would put the option and the instance out of step. The `missed meta keys` lists, the listings key and the connection are not carried either.
- Sections write through the savers the admin uses (`SearchableAttributes::save`, `RealtimeIndexing::save`, `AutoIntegration::save`, `ListingsSettings::save`, `RulesCsv::import`), in the admin's order, and the preview simulates it (a searchable field is judged against the *incoming* meta keys).
- `import` is a dry run unless `--apply` is given (there is no `--dry-run` flag).
- The preview is stored for 15 minutes in a transient keyed by token and user; `apply` plans the stored file again before writing.

## Problem

Moving a site (staging → production, one client to another, a developer's machine → a server) means redoing MeiliScout's configuration by hand: the indexed types, taxonomies and meta keys, the fields searched, the real-time mode, the listings' options, the SEO rules. The content moves with the database or an importer; the **configuration** does not move reliably, and it is easy to forget a meta key, which then fails later as an unindexed facet.

The goal: one file that carries what is worth carrying, applied with a preview, from the admin or WP-CLI, safely on a server where Meilisearch, keys and content are different.

## What is worth carrying

Read from the code, not from memory: the options stored by `Settings::save()` (`meiliscout/<key>`) and the tables MeiliScout owns.

| Section | Source | Carry? | Notes |
|---|---|---|---|
| `indexing` | `indexed_post_types`, `indexed_taxonomies`, `indexed_meta_keys`, `searchable_attributes`, `realtime_indexing`, `indexing.posts_per_page`, `bulk_batch_size` | Yes | The core of it. Must exist on the target to be valid (see *Validation*) |
| `queries` | the Settings › Queries switches (`AutoIntegration`, `TermAutoIntegration`), `max_total_hits`, `http_timeout` | Yes | Plain values |
| `listings` | `listings_enabled`, `listings_styles`, `listings_colors` | Yes | `listings_public_host` is **environment**: opt-in only, off by default |
| `seo_rules` | the `{prefix}meiliscout_seo_rules` table | Yes | `RulesCsv` and `SeoRulesService` already import and export it; reuse them, do not rewrite |
| `block_listings` | option `meiliscout/block_listings` | **No** | It is a cache rebuilt on `save_post` from the blocks in the content. It travels with the content. The import only offers to **resync** it (below) |
| listings declared in PHP | the theme or plugin code | **No** | Already in Git |
| `languages` | Polylang / WPML | **No** | Their own tools |
| Documents of the indexes | Meilisearch | **No** | Reindex on the target: safer than copying |

Never carried, whatever the options say:

- `meili_host`, `meili_key`, `meili_search_key`, `meili_index_prefix` (connection: the target has its own);
- the **listings key** (it signs the tenant tokens: each server generates its own);
- `active_indexes`, `legacy_indexes`, `index_settings_hash/*`, `schema_version`, `last_indexing_structure`, `indexes_activated`, the activity log, the async queue, the search fallbacks (local state of one install);
- anything marked as a secret by a `SECRET_KEYS` list that the exporter checks against, so that a new secret added later is excluded by default (the exporter works from an **allow-list** of keys, never from "every `meiliscout/*` option").

## File format

One JSON file, versioned, readable and diff-able in Git.

```json
{
  "meiliscout_export": 1,
  "plugin_version": "2.3.0",
  "exported_at": "2026-10-20T10:00:00Z",
  "source": { "site": "https://staging.example.com", "wordpress": "6.9" },
  "sections": {
    "indexing": {
      "indexed_post_types": ["post", "project"],
      "indexed_taxonomies": ["category", "project_type"],
      "indexed_meta_keys": ["_price", "client_sector"],
      "searchable_attributes": ["post_title", "terms.name", "post_excerpt"],
      "realtime_indexing": "shutdown"
    },
    "queries": { "...": "..." },
    "listings": { "listings_enabled": true, "listings_styles": true, "listings_colors": { "...": "..." } },
    "seo_rules": { "format": "csv", "data": "..." }
  }
}
```

- `meiliscout_export` is the **format** version, bumped only when the file's shape changes; `plugin_version` is informative. An import of a newer format is refused with a clear message; an older one is upgraded by a small migration table.
- Each section is independent: the import takes `--only=indexing,seo_rules` or the checkboxes of the screen.
- Unknown keys in a section are **ignored with a warning**, never written (a file edited by hand or from a newer version cannot plant arbitrary options).
- SEO rules stay CSV inside the JSON (`RulesCsv`'s format) so one format covers the screen, the CLI and this file. Terms and pages in a rule are written by **slug**, not id (see *Identifiers*).

## Identifiers

Ids differ between servers; slugs are stable. The exporter writes slugs, the importer resolves them on the target:

- SEO rules key on a listing id and facet values (term slugs): already slug-based; the `route` page of a listing is a page id in code, which is not exported (it is in the theme).
- Anything that references a page or a term is exported as `{type, slug}` and resolved on import; an unresolved reference is reported, and that item is skipped, not guessed.

## Validation (before anything is written)

The importer builds a **plan**: for each section, what would change, what is invalid, what is skipped. Nothing is written until the plan is accepted (`--dry-run` is the default of `import`; `--apply` writes).

Checks against the target site:

- a post type, taxonomy or meta key in `indexing` that does not exist (a plugin not installed there) → warning, the entry is **kept** in the plan but marked "not registered here" (it may be registered later); `--strict` makes it an error;
- `realtime_indexing` outside `RealtimeIndexing::MODES` → error;
- a searchable attribute outside what the document has (`SearchableAttributes`'s own validation) → error;
- an SEO rule pointing at an unknown listing id or term slug → skipped with the reason, as `SeoRulesService`'s import already reports;
- a section from a newer plugin version than the target → refused for that section.

The diff shown is the point of the feature: *before → after* for each setting, and counts for the rules (n created, n updated, n unchanged).

## After the import

Changing what is indexed changes the index. The importer **does not reindex on its own**; it says what is needed and offers it:

- the indexed types, taxonomies or meta keys changed, or the searchable attributes: *"Run a full indexation (MeiliScout › Indexation, or `wp meiliscout index`)"*. The import records that the index structure is out of date the same way a manual settings change does (`last_indexing_structure`), so the existing "reindex needed" notice appears with no new mechanism;
- listing blocks: `--resync-blocks` re-runs `BlockListings::save` over the posts containing a listing block, so `meiliscout/block_listings` matches the imported content (useful when the content was imported by a tool that skips `save_post`).

## Surfaces

**WP-CLI** (the primary one, scriptable in a deploy):

```bash
wp meiliscout export [--sections=<list>] [--include-env] [--file=<path>]   # stdout by default
wp meiliscout import <file> [--only=<list>] [--strict] [--dry-run] [--apply] [--resync-blocks]
```

Exit code 1 on an invalid file or, with `--strict`, on a warning; 0 otherwise. `--dry-run` prints the plan. `--include-env` adds the opt-in environment keys (`listings_public_host`), off by default.

**Admin**: Settings › *Export / Import* (a section of the existing Settings screen, `SettingsController`-style REST controller, `manage_options`): download button with the sections as checkboxes; an upload that shows the plan, then an *Apply* button. The REST import is two calls, `POST /config/import/preview` and `POST /config/import/apply`, the second only taking the token returned by the first so the applied plan is the one that was shown. The file is never stored; the preview keeps the parsed plan in a transient for a few minutes.

**Code**: `Config\Portability\Exporter` and `Importer` (pure: build the file / the plan from arrays, no I/O), a `Sections` registry (one class per section implementing `ConfigSection`: `export()`, `plan(array $data)`, `apply(Plan $plan)`), so a plugin can add its own section with a filter (`meiliscout/config_sections`).

## Tests

- Unit: each section's export from stand-in options, the plan of an import (unchanged, changed, invalid, unknown key), the secret allow-list (a test fails if an exported key matches `key`, `token`, `secret`, `password`, or the listings key), format migrations.
- A **round trip**: export, wipe, import, export again: identical files.
- Integration (the demo site): import a file with a meta key that does not exist, check the plan; apply, check the "reindex needed" notice; `--resync-blocks` on a page with a listing block.
- Security: the REST routes refuse non-admins; an uploaded file with extra keys, an oversized file (limit 1 MB) or invalid JSON is rejected before any write.

## Order of work

1. `Exporter`/`Importer`, the `indexing`, `queries` and `listings` sections, the CLI, the round-trip test. Useful alone.
2. The SEO rules section on top of `RulesCsv`.
3. The admin screen (export, upload, preview, apply).
4. `--resync-blocks`, documentation (`docs/CONFIG_IMPORT_EXPORT.md`), CHANGELOG.

Each step is a PR of its own; none changes existing behavior.

## Decisions

Taken on 2026-10-14 (delegated by the owner, weighing users and developers):

1. **Environment keys** (`listings_public_host`): excluded by default, `--include-env` (and a checkbox in the admin, unticked) adds them. A file meant to be shared or committed must be safe by default, yet a developer cloning one server onto an identical one can still opt in.
2. **A post type, taxonomy or meta key missing on the target**: warn and **keep** the entry, marked "not registered here"; `--strict` turns the warning into an error (for a deploy script that must fail loudly). Refusing by default would block the normal case of importing before the plugin that registers the type is active.
3. **Reindexing**: the import never starts one by itself. `--reindex` starts it explicitly after a successful `--apply` (a deploy script wants one command), and the plan always says whether one is needed.
4. **Scope**: what `Settings::save()` writes today. Synonyms and stop words are not managed by the plugin (no screen, no option), and the ranking rules are code (`meiliscout/post/ranking_rules`), so they stay out. A site that changes them directly in Meilisearch moves them with Meilisearch's own dump or settings API.
