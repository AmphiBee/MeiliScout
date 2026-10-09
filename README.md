# MeiliScout

[Meilisearch](https://www.meilisearch.com/) for WordPress: posts and terms indexed as they change, `WP_Query` and `get_terms()` answered by Meilisearch when it can answer them as MySQL would, and filterable listings on the front end.

- **Queries.** A query asking for it (`use_meilisearch`), or one the admin serves (the site search, archives, REST searches, admin lists), is translated to Meilisearch or runs on MySQL with the reason recorded: never a different result. Posts are loaded from the database, `found_posts` and the pagination are exact. Coverage: [docs/WP_QUERY.md](docs/WP_QUERY.md), [docs/TERM_QUERY.md](docs/TERM_QUERY.md).
- **Indexing.** Post types, taxonomies and meta keys chosen in the admin; posts and terms re-indexed when they change, at the end of the request or in the background; full indexations that build new indexes and switch to them when done.
- **Front listings** (a module, off by default): facets with their counts, sorts, search, active filters and pagination, counted by the browser straight on Meilisearch; one canonical URL per state, facets in the path, SEO through the SEO plugin in use, Polylang and WPML; declared in PHP, Blade or Twig, or built in the block editor. See [docs/LISTINGS.md](docs/LISTINGS.md).
- **Admin.** MeiliScout in the WordPress menu: overview and health, content to index, indexation and its log, a search preview and a `WP_Query` tester, the listings and their SEO rules, settings.

## Requirements

- PHP 8.2 or later, WordPress 6.6 or later (6.9 for the front listings).
- A Meilisearch instance (1.x), its URL and an admin key (one allowed to manage keys, for the listings).

## Installation

The release zip, which ships its dependencies, in `wp-content/plugins`; or the package `amphibee/meiliscout` as a Composer dependency of the site (Bedrock, Pollora), whose autoloader then loads it. Then activate it.

## Configuration

MeiliScout › Settings › Connection holds the instance's URL and keys. Each setting can come from the environment or a constant instead, and is then read-only in the admin:

| Variable or constant | |
|---|---|
| `MEILI_HOST` | The instance's URL |
| `MEILI_KEY` | Its admin key |
| `MEILI_SEARCH_KEY` | A search key, for searches (optional) |
| `MEILI_INDEX_PREFIX` | Prefix of the index names (default: the site's domain) |
| `MEILISCOUT_ASYNC_INDEXING` | `true`: real-time indexing in the background (WP-Cron); `false`: at the end of the request |

Then choose what to index (MeiliScout › Content) and run a full indexation (MeiliScout › Indexation, or `wp meiliscout index`).

## Querying

```php
$query = new WP_Query([
    'post_type' => 'project',
    'tax_query' => [['taxonomy' => 'project_type', 'terms' => [12]]],
    'meta_query' => [['key' => '_price', 'value' => 5000, 'compare' => '<=', 'type' => 'NUMERIC']],
    'use_meilisearch' => true,
]);

$query->meiliscout; // served or not, why, the search sent
```

The meta keys a query filters or sorts on must be indexed (MeiliScout › Content › Meta keys). The `X-MeiliScout` header, the Query Monitor panel and MeiliScout › Search preview › WP_Query say what happened to a query.

## Front listings

```php
add_action('init', function () {
    meiliscout_register_listing('projects', [
        'post_types' => ['project'],
        'route' => ['page' => 42],
        'facets' => [
            'type' => ['source' => 'taxonomy:project_type', 'path' => 'type'],
            'price' => ['source' => 'meta:_price', 'type' => 'range'],
        ],
    ]);
});

// In the page's template, or the Filterable listing block in the editor
meiliscout_listing('projects');
```

Turn the module on in MeiliScout › Settings › Listings. Everything else, from the cards to the SEO rules: [docs/LISTINGS.md](docs/LISTINGS.md).

## WP-CLI

```sh
wp meiliscout index                 # full indexation
wp meiliscout status                # the indexes searches read and write, a pending migration
wp meiliscout check-queries         # WP_Query on MySQL and on Meilisearch, compared
wp meiliscout check-queries --terms # get_terms(), the same way
wp meiliscout check-listings        # the front listings: checks, counts against MySQL, URLs
wp meiliscout bench-listings <id>   # a listing's page, fragment and counts, timed
wp meiliscout seo-rules list|export|import|preview
```

## Hooks

The filters, from what is indexed to the search parameters sent: [docs/FILTERS.md](docs/FILTERS.md); the listings' own: [docs/LISTINGS.md#filters](docs/LISTINGS.md#filters).

## Development

```sh
composer install && npm install
npm run build                 # admin and listings assets
vendor/bin/pest               # unit tests
npm run test:js               # the listings' client
composer test:types           # PHPStan
composer test:integration     # against a WordPress with Meilisearch (the demo site)
```

[CHANGELOG.md](CHANGELOG.md) lists the changes by version.

## License

MIT, by [AmphiBee](https://amphibee.fr).
