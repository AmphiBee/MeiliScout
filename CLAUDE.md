# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Admin Assets Development
```bash
npm run start      # watch
npm run build      # build/app.js, build/app.css
npm run lint:js
npm run lint:css
npm run format
npm run i18n       # needs WP-CLI: .pot, merges into the .po files, .mo and the JSON of the script
```

### PHP Development
```bash
# Run PHP tests with PestPHP
vendor/bin/pest

# Run specific test file
vendor/bin/pest tests/Unit/Indexables/Post/QueryBuilder/BasicQueryTest.php

# Run PHPUnit (alternative)
vendor/bin/phpunit

# Run PHPStan static analysis
ddev exec --dir /var/www/html/public/content/plugins/meiliscout composer test:types

# Run all tests (lint + types + unit)
composer test

# Integration tests: a real WordPress and Meilisearch (the demo site), every
# WP_Query parity case and every filter the unit tests build
ddev exec --dir /var/www/html/public/wp-content/plugins/meiliscout composer test:integration

# Compare WP_Query (--terms: get_terms()) results on MySQL and Meilisearch (exit 1 on a DIFF)
ddev wp meiliscout check-queries [--terms] [--case=<label>] [--args='<json>']
```

### DDEV Environment
When working in the DDEV environment, prefix commands with:
```bash
ddev exec --dir /var/www/html/public/content/plugins/meiliscout [command]
```

Example:
```bash
ddev exec --dir /var/www/html/public/content/plugins/meiliscout composer test:types
```

### Build System
- @wordpress/scripts, one entry: `resources/admin/index.js` → `build/app.js` and `build/app.css`
- The entry is not named `admin`: `wp i18n make-json` turns a file name ending in `min.js` into a wrong one, and the translations of the script are never loaded

## Architecture Overview

### Core Framework
**MeiliScout** is a modern WordPress plugin that integrates Meilisearch with a sophisticated, modular architecture:

- **Service Container**: Custom PSR-11 compliant dependency injection container
- **Service Provider Pattern**: Modular service registration system in `src/Providers/`
- **Domain-Driven Design**: Business logic organized in `src/Domain/`
- **Query Builder Pattern**: Fluent interface for building Meilisearch queries

### Key Components

#### Foundation Layer (`src/Foundation/`)
- `Application.php`: Main bootstrapper that registers all service providers
- `Container.php`: PSR-11 dependency injection container with singleton support
- Entry point bootstraps through service providers defined in `Application::$providers`

#### Query System (`src/Query/`)
**Translate or fall back**: an argument is either translated faithfully or the whole query runs on MySQL, with a reason (`unsupported_arg:author`, `unindexed_meta:price`, `schema_too_old`...). Never drop a clause. Coverage and reasons: `docs/WP_QUERY.md`.
- `QueryIntegration`: `posts_pre_query`; check, build (in the `try`), search, load the posts from the database (ids only from Meilisearch), set `found_posts` as `set_found_posts()` does; `$query->meiliscout` says what happened
- `AutoIntegration`: which queries are served without `use_meilisearch` (Settings › Queries, all off by default)
- `QuerySupport`: whitelist of query vars (`UNTRANSLATED` lists the ones falling back, each a harness case); statuses and types the index lacks, counted against the site's posts
- `SqlFilters`: a plugin changing the SQL through `posts_where`, `posts_clauses`... sends the query to MySQL
- `QueryVars`: what WordPress parsed (`$query->tax_query->queries`, `meta_query->queries`, implied post types): read that, not the raw vars
- `MeiliQueryBuilder` + `Builders/`: pure (never modify the query); a clause that cannot be translated throws `UnsupportedQuery`
- `PhpOrder`: `rand` and list orders, applied in PHP over every result
- `SearchMemo`: Meilisearch's answers kept for the request (a Query Loop's pagination blocks run its query again), forgotten on `clean_post_cache`
- `QueryLog`, `DebugHeader`, `Integrations/QueryMonitor`: debugging
- `Diagnostics/QueryParity`: the differential harness behind `check-queries`, the integration tests and the admin's WP_Query tester; its cases pick their data in the site
- `Terms/`: `get_terms()` on `terms_pre_query` (`TermQueryIntegration`): `TermQuerySupport`, `TermQueryBuilder` (WHERE/ORDER BY/LIMIT as `WP_Term_Query` builds them) into a `TermQueryPlan`, `TermResults` (what WordPress does after its SQL, with its own functions, array keys included), `ObjectTerms` (`object_ids` through the posts index), `TermSqlFilters`, `TermAutoIntegration`. Coverage: `docs/TERM_QUERY.md`; harness `Diagnostics/TermQueryParity` (`check-queries --terms`)

#### Indexables (`src/Indexables/`)
- `PostIndexable`: WordPress post indexing implementation
- `TaxonomyIndexable`: Taxonomy indexing implementation (schema 4: `name_sort`, `description_fold`/`description_sort`, `tree_count`; term meta keys apart, `indexed_term_meta_keys`)
- Implements `Indexable` contract for extensibility

#### Services (`src/Services/`)
- `Indexer`: Main indexing service with bulk and chunked operations
- `PostSingleIndexer`: Real-time single post indexing
- `TaxonomySingleIndexer`: Real-time single taxonomy indexing
- `AsyncIndexingQueue`: WP-Cron based async indexing queue (opt-in)
- `IndexingLogger`: Secure file-based log of the running full indexation, with its progress
- `ActivityLog`: Last 100 operations (full indexations, real-time tasks), failed tasks kept to retry them
- `SearchFallbacks`: Queries asking for Meilisearch that MySQL served, per hour, for 24 h
- `MetaKeyCatalog`: Meta keys of the indexable posts, with a type guessed from their values

### Configuration
- **Config System**: `src/Config/Config.php` (environment, constant, then option) and `src/Config/Settings.php`
- `RealtimeIndexing` (shutdown / async / off) and `SearchableAttributes` (fields searched, in order)
- **Plugin Constants**: Defined in `plugin.php`
- **Default Index**: Configured in `config/meiliscout.php`

## Development Conventions

### PHP Standards
- **PHP 8.2+**: Modern PHP with strict typing (`declare(strict_types=1)`)
- **PSR Standards**: PSR-4 autoloading, PSR-11 container interface
- **Namespace Structure**: `Pollora\MeiliScout\` follows directory structure
- **Documentation**: Comprehensive PHPDoc comments required

### Code Organization
- **Contracts**: Interfaces in `src/Contracts/` for extensibility
- **Enums**: Domain enums in `src/Domain/Search/Enums/`
- **Validators**: Type validation in `src/Domain/Search/Validators/`
- **Service Providers**: Modular service registration pattern

### Admin
- One page (`admin.php?page=meiliscout`, `Providers/Admin/AdminServiceProvider`) where a React app (`resources/admin/`) renders five screens from the hash: Overview, Content, Indexation, Search preview, Settings
- REST endpoints under `meiliscout/v1`, one controller per screen in `src/Admin/Rest/`, all for `manage_options`
- API keys never go to the browser: the app only learns whether one is set
- Plain CSS scoped to `.meiliscout-admin`, design tokens as custom properties (look of the Meilisearch Cloud dashboard)
- UI strings in English through `@wordpress/i18n`; French in `languages/`

## Testing Strategy

### Test Structure
- **PestPHP**: Modern PHP testing framework
- **Unit Tests**: Individual component testing
- **Query Builder Tests**: Comprehensive search functionality testing
- **Mock Objects**: WordPress function mocking for isolated testing

### Test Organization
- Tests mirror `src/` structure
- `tests/Integration/` runs against the demo site (`phpunit.integration.xml`; `tests/Pest.php` then loads WordPress instead of the stand-ins)
- Mock WordPress functions in `tests/Unit/Indexables/Post/QueryBuilder/MockWPQuery.php`
- Custom `TestCase` base class for shared functionality

## Key File Locations

### Core Files
- `plugin.php`: Plugin entry point
- `src/Foundation/Application.php`: Main application bootstrapper
- `src/Foundation/Container.php`: Dependency injection container

### Query System
- `src/Query/MeiliQueryBuilder.php`: Main query builder
- `src/Query/QueryIntegration.php`: WordPress integration
- `src/Query/Builders/`: Specialized query builders

### Configuration
- `config/meiliscout.php`: Plugin configuration
- `webpack.config.js`: Build configuration
- `composer.json`: PHP dependencies and autoloading

## Available Filters

The plugin provides several filters for customization:

- `meiliscout/skip_indexing`: Disable indexing globally (useful during imports)
- `meiliscout/indexables`: Customize or replace default indexables
- `meiliscout/post_single_indexer`: Customize or replace the PostSingleIndexer
- `meiliscout/bulk_batch_size`: Control batch size for bulk indexing (default: 500)
- `meiliscout/log_directory`: Customize log directory path
- `meiliscout/async_indexing_delay`: Delay before async queue processing (default: 300s)
- `meiliscout/post/displayed_attributes`: Restrict fields Meilisearch may return (default: `['*']`)
- `meiliscout/reindex_on_meta_change`: Whether a changed meta key re-indexes the post (default: selected meta keys only)
- `meiliscout/http_client_options`: Options of the Symfony HttpClient used for Meilisearch (default: `['timeout' => 10]`)
- `meiliscout/index_prefix`: Prefix of the index names (default: `MEILI_INDEX_PREFIX`, else the site's domain)
- `meiliscout/supported_query_vars`, `meiliscout/ignored_sql_filters`, `meiliscout/ignored_term_sql_filters`, `meiliscout/skip_term_query_integration`, `meiliscout/integrate_term_query`, `meiliscout/term/ranking_rules`, `meiliscout/term/document`, `meiliscout/skip_query_integration`, `meiliscout/integrate_query`, `meiliscout/search_params`, `meiliscout/hydrate_from_documents`, `meiliscout/search_memo`, `meiliscout/max_total_hits`, `meiliscout/php_order_limit`, `meiliscout/post/ranking_rules`, `meiliscout/debug_header`, `meiliscout/indexable_post_statuses`: see `docs/FILTERS.md`

## Environment Variables

- `MEILISCOUT_ASYNC_INDEXING`: Async (`true`) or end-of-request (`false`) real-time indexing; overrides the admin's setting
- `MEILI_INDEX_PREFIX`: Prefix of the index names

## Index Names and Migrations

`Services/IndexNames` names the indexes and records the *active* ones searches read, with the document format each was built in (`SCHEMA_VERSIONS`, one version per index). Writes go to the *target* names, and are mirrored to the active index while a migration is pending. A full indexation builds the targets and activates them. Bump an index's version in `SCHEMA_VERSIONS` whenever the documents change in a way searches depend on, and keep a read path for the previous version (see `TaxQueryBuilder::buildLegacyFilter()`), or fall back with `schema_too_old` (see `PostFieldsBuilder`, `DateQueryBuilder`). Posts schema 3 added the `WP_Query` fields (ids as numbers, `has_password`, `*_ts`, `date_parts`, `post_title_sort`); schema 4 every value of a meta key, with `Services/MetaValueFlags` noting what each key's values are like for the meta builders. A full indexation activates only the indexes it built.

Queries rely on `PostIndexable::queryableStatuses()`: the indexable statuses the last full indexation sent, not the setting alone.
