<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Diagnostics;

use Pollora\MeiliScout\Listings\Blocks\BlockListings;
use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\FacetDefinition;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\Seo\SeoRules;
use Pollora\MeiliScout\Listings\State\ReservedParameters;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * What the Listings screen and `wp meiliscout check-listings` say of each
 * declared listing (design §13): where it comes from, its URL in each
 * language, its facets and parameters, and its checks: its definition, the
 * sources it reads (indexed, in the index's current format, with documents),
 * its route, path prefixes a page or a term may hide, parameters WordPress or
 * a cache reads.
 *
 * A check is error (the listing is not served, or a view is unreachable),
 * warning (served, but something is off) or info.
 */
final class ListingChecks
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /**
     * A segment WordPress writes after a page's path: comment-page-2.
     */
    private const WORDPRESS_PREFIXES = ['comment'];

    /**
     * Every declared listing's report.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $documents = self::documents();

        return array_map(fn (string $id) => self::report($id, $documents), DefinitionRegistry::ids());
    }

    /**
     * A listing's report.
     *
     * @param  array<string, int>|null  $documents  The index's published documents by post type, null when unknown
     * @return array<string, mixed>
     */
    public static function report(string $id, ?array $documents = null): array
    {
        $block = BlockListings::saved()[$id] ?? null;
        $report = [
            'id' => $id,
            'source' => $block === null ? 'php' : 'block',
            // The post holding the block (its default language's)
            'post' => $block === null ? null : self::post((int) $block['post']),
            'valid' => true,
            'checks' => [],
        ];

        try {
            $definition = DefinitionRegistry::get($id);
        } catch (InvalidListing $e) {
            $report['valid'] = false;
            $report['checks'] = array_map(fn (string $error) => self::check(self::ERROR, 'definition', $error), $e->errors);

            return $report;
        }

        $routes = self::routes($definition);

        return [
            ...$report,
            'post_types' => array_map(fn (string $type) => ['name' => $type, 'label' => (string) (get_post_type_object($type)->labels->name ?? $type)], $definition->postTypes),
            'per_page' => $definition->perPage,
            'transport' => $definition->transport,
            'apply' => $definition->apply,
            'personalised' => $definition->personalised,
            'route' => self::routeLabel($definition),
            'routes' => $routes,
            'facets' => array_map(fn (FacetDefinition $facet) => self::facet($facet, array_column($routes, 'language')), $definition->facets),
            'sorts' => array_map(fn (string $key, array $sort) => ['key' => $key, 'label' => $sort['label'], 'orderby' => $sort['orderby'], 'order' => $sort['order']], array_keys($definition->sorts), $definition->sorts),
            'default_sort' => $definition->defaultSort,
            'parameters' => self::parameters($definition),
            'seo' => [
                'enabled' => $definition->seo,
                'max_depth' => $definition->seoMaxDepth,
                'min_results' => $definition->seoMinResults,
                'rules' => count(SeoRules::all($definition->id)),
            ],
            'checks' => [
                ...self::sources($definition, $documents),
                ...self::routeChecks($definition, $routes),
                ...self::collisions($definition, $routes),
                ...self::parameterChecks($definition),
            ],
        ];
    }

    /**
     * The worst level of a report's checks, null when it has none.
     *
     * @param  array<string, mixed>  $report
     */
    public static function level(array $report): ?string
    {
        $levels = array_column($report['checks'], 'level');

        foreach ([self::ERROR, self::WARNING, self::INFO] as $level) {
            if (in_array($level, $levels, true)) {
                return $level;
            }
        }

        return null;
    }

    /**
     * The index's published documents by post type: one search; null when
     * Meilisearch cannot be asked.
     *
     * @return array<string, int>|null
     */
    public static function documents(): ?array
    {
        $client = ClientFactory::getClient();

        if ($client === null) {
            return null;
        }

        try {
            $result = $client->index(IndexNames::active('posts'))->search('', [
                'filter' => 'post_status = "publish"',
                'facets' => ['post_type'],
                'limit' => 0,
            ]);
        } catch (\Throwable) {
            return null;
        }

        return array_map('intval', (array) ($result->getFacetDistribution()['post_type'] ?? []));
    }

    /**
     * @return array{level: string, code: string, message: string}
     */
    private static function check(string $level, string $code, string $message): array
    {
        return ['level' => $level, 'code' => $code, 'message' => $message];
    }

    /**
     * @return array{id: int, title: string, edit: string|null, url: string|null}|null
     */
    private static function post(int $id): ?array
    {
        $post = get_post($id);

        if (! $post instanceof \WP_Post) {
            return null;
        }

        return [
            'id' => $post->ID,
            'title' => html_entity_decode(get_the_title($post), ENT_QUOTES),
            'edit' => get_edit_post_link($post->ID, 'raw') ?: null,
            'url' => get_post_status($post) === 'publish' ? (string) get_permalink($post) : null,
        ];
    }

    private static function routeLabel(ListingDefinition $definition): ?string
    {
        return match (true) {
            isset($definition->route['page']) => 'page:'.$definition->route['page'],
            isset($definition->route['post']) => 'post:'.$definition->route['post'],
            isset($definition->route['archive']) => 'archive:'.$definition->route['archive'],
            default => null,
        };
    }

    /**
     * Its first page in each language: '' language on a site without a
     * multilingual plugin; url null when it has none there.
     *
     * @return list<array{language: string, locale: string, url: string|null, post: int|null}>
     */
    private static function routes(ListingDefinition $definition): array
    {
        $adapter = Languages::adapter();
        $routes = [];

        foreach ($adapter->languages() ?: [''] as $language) {
            $route = $adapter->in($language, function () use ($definition) {
                $post = Listings::routePost($definition);
                $url = $definition->route === [] ? '' : Listings::baseUrl($definition);

                return ['url' => $url !== '' ? $url : null, 'post' => $post !== 0 ? $post : null];
            });

            $routes[] = ['language' => $language, 'locale' => $language === '' ? get_locale() : $adapter->locale($language)] + $route;
        }

        return $routes;
    }

    /**
     * @param  list<string>  $languages
     * @return array<string, mixed>
     */
    private static function facet(FacetDefinition $facet, array $languages): array
    {
        $prefixes = [];
        if ($facet->inPath()) {
            foreach ($languages as $language) {
                $prefixes[$language] = $facet->prefix($language);
            }
        }

        return [
            'key' => $facet->key,
            'label' => $facet->label,
            'source' => $facet->source.':'.$facet->name,
            'type' => $facet->type,
            'logic' => $facet->logic,
            'hierarchical' => $facet->hierarchical,
            'param' => $facet->param,
            'limit' => $facet->limit,
            // By language; empty for a facet in the query string
            'path' => (object) $prefixes,
        ];
    }

    /**
     * The query string names it reads.
     *
     * @return list<array{name: string, role: string, reserved: bool}>
     */
    private static function parameters(ListingDefinition $definition): array
    {
        $parameters = [
            ['name' => $definition->searchParam, 'role' => 'search'],
            ['name' => $definition->sortParam, 'role' => 'sort'],
        ];

        foreach ($definition->facets as $facet) {
            $parameters[] = ['name' => $facet->param, 'role' => 'facet:'.$facet->key];
        }

        return array_map(fn (array $parameter) => $parameter + ['reserved' => self::isReserved($parameter['name'])], $parameters);
    }

    /**
     * Reserved now: a plugin may have added its query vars since the listing was validated.
     */
    private static function isReserved(string $name): bool
    {
        global $wp;

        $public = $wp instanceof \WP ? (array) apply_filters('query_vars', $wp->public_query_vars) : [];

        return ReservedParameters::isReserved($name) || in_array($name, $public, true);
    }

    /**
     * The post types, taxonomies and meta keys it reads, as the index has them.
     *
     * @param  array<string, int>|null  $documents
     * @return list<array{level: string, code: string, message: string}>
     */
    private static function sources(ListingDefinition $definition, ?array $documents): array
    {
        $checks = [];

        foreach ($definition->facets as $facet) {
            if (! $facet->isTaxonomy()) {
                continue;
            }

            $taxonomy = get_taxonomy($facet->name);
            if ($taxonomy !== false && array_intersect($taxonomy->object_type, $definition->postTypes) === []) {
                $checks[] = self::check(self::WARNING, 'taxonomy', sprintf(
                    /* translators: 1: a facet, 2: a taxonomy, 3: post types */
                    __('The facet “%1$s” reads the taxonomy “%2$s”, which %3$s do not have: it never has values.', 'meiliscout'),
                    $facet->key,
                    $facet->name,
                    implode(', ', $definition->postTypes)
                ));
            }
        }

        $hierarchical = array_filter($definition->facets, fn (FacetDefinition $facet) => $facet->hierarchical);
        if ($hierarchical !== [] && IndexNames::activeSchema('posts') < 5) {
            $checks[] = self::check(self::ERROR, 'schema', __('The posts index predates the terms’ ancestors its hierarchical facets count on: run a full indexation.', 'meiliscout'));
        }

        if ($documents === null) {
            $checks[] = self::check(self::WARNING, 'documents', __('Meilisearch could not be asked whether it has the listing’s posts.', 'meiliscout'));

            return $checks;
        }

        foreach ($definition->postTypes as $type) {
            $indexed = $documents[$type] ?? 0;
            $published = (int) (wp_count_posts($type)->publish ?? 0);

            if ($indexed === 0 && $published > 0) {
                $checks[] = self::check(self::ERROR, 'documents', sprintf(
                    /* translators: 1: a post type, 2: a number of posts */
                    __('The index has no published %1$s, the site has %2$d: run a full indexation.', 'meiliscout'),
                    $type,
                    $published
                ));
            } elseif ($indexed !== $published) {
                $checks[] = self::check(self::WARNING, 'documents', sprintf(
                    /* translators: 1: a post type, 2: documents in the index, 3: posts on the site */
                    __('The index has %2$d published %1$s, the site %3$d: an indexation is late or failed (Indexation).', 'meiliscout'),
                    $type,
                    $indexed,
                    $published
                ));
            }
        }

        return $checks;
    }

    /**
     * @param  list<array{language: string, locale: string, url: string|null, post: int|null}>  $routes
     * @return list<array{level: string, code: string, message: string}>
     */
    private static function routeChecks(ListingDefinition $definition, array $routes): array
    {
        if ($definition->route === []) {
            return [self::check(self::INFO, 'route', __('No route: it renders where a template or a block prints it, without facets in the path, canonical URL or SEO view.', 'meiliscout'))];
        }

        $checks = [];
        $post = $definition->route['page'] ?? $definition->route['post'] ?? null;

        if ($post !== null && get_post_status((int) $post) !== 'publish') {
            $checks[] = self::check(self::ERROR, 'route', sprintf(
                /* translators: %d: a post id */
                __('Its route, the post %d, is not published.', 'meiliscout'),
                $post
            ));
        }

        if (isset($definition->route['archive']) && get_post_type_archive_link($definition->route['archive']) === false) {
            $checks[] = self::check(self::ERROR, 'route', sprintf(
                /* translators: %s: a post type */
                __('The post type “%s” has no archive.', 'meiliscout'),
                $definition->route['archive']
            ));
        }

        foreach ($routes as $route) {
            if ($route['language'] !== '' && $route['url'] === null) {
                $checks[] = self::check(self::WARNING, 'translation', sprintf(
                    /* translators: %s: a language */
                    __('Its page has no translation in %s: the listing has no URL in that language.', 'meiliscout'),
                    $route['language']
                ));
            }
        }

        // Two listings on one page: both read its path and its parameters
        foreach (DefinitionRegistry::ids() as $id) {
            if ($id === $definition->id) {
                continue;
            }
            try {
                $other = DefinitionRegistry::get($id);
            } catch (InvalidListing) {
                continue;
            }
            if ($other->route === $definition->route) {
                $checks[] = self::check(self::WARNING, 'shared_route', sprintf(
                    /* translators: %s: a listing */
                    __('The listing “%s” has the same route: both read its URL, only the first one declared redirects and answers 404.', 'meiliscout'),
                    $id
                ));
            }
        }

        return $checks;
    }

    /**
     * Path prefixes a post of its own, a term of another language or WordPress
     * itself makes ambiguous.
     *
     * @param  list<array{language: string, locale: string, url: string|null, post: int|null}>  $routes
     * @return list<array{level: string, code: string, message: string}>
     */
    private static function collisions(ListingDefinition $definition, array $routes): array
    {
        global $wpdb;

        $checks = [];

        foreach ($definition->pathFacets() as $facet) {
            foreach ($facet->prefixes() as $prefix) {
                if (in_array($prefix, self::WORDPRESS_PREFIXES, true)) {
                    $checks[] = self::check(self::ERROR, 'prefix_wordpress', sprintf(
                        /* translators: 1: a facet, 2: a prefix */
                        __('The facet “%1$s”: WordPress writes %2$s-page-N after a page for its comments; choose another prefix.', 'meiliscout'),
                        $facet->key,
                        $prefix
                    ));
                }

                // Every language's prefix is read under every language's page (PathFacetParser)
                foreach ($routes as $route) {
                    foreach (self::postsAt($definition, $route, $prefix) as $post) {
                        $checks[] = self::check(self::WARNING, 'prefix_post', sprintf(
                            /* translators: 1: a URL, 2: a facet, 3: a prefix */
                            __('%1$s is a post of its own: it hides the view of “%2$s” its path names (prefix %3$s-).', 'meiliscout'),
                            (string) get_permalink($post),
                            $facet->key,
                            $prefix
                        ));
                    }
                }
            }

            // A slug is looked up whatever its language: two terms sharing one are told apart by neither
            if (count($routes) > 1) {
                $shared = $wpdb->get_col($wpdb->prepare(
                    "SELECT t.slug FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s GROUP BY t.slug HAVING COUNT(*) > 1 LIMIT 10",
                    $facet->name
                ));
                if ($shared !== []) {
                    $checks[] = self::check(self::WARNING, 'term_slug', sprintf(
                        /* translators: 1: a facet, 2: term slugs */
                        __('The facet “%1$s”: terms of several languages share a slug (%2$s); the path names one of them only.', 'meiliscout'),
                        $facet->key,
                        implode(', ', $shared)
                    ));
                }
            }
        }

        return $checks;
    }

    /**
     * The posts living at {first page}/{prefix}-…: a child of the route's page
     * or post, a post of the archive's type.
     *
     * @param  array{language: string, locale: string, url: string|null, post: int|null}  $route
     * @return list<int>
     */
    private static function postsAt(ListingDefinition $definition, array $route, string $prefix): array
    {
        global $wpdb;

        $like = $wpdb->esc_like($prefix.'-').'%';

        if (isset($definition->route['archive'])) {
            $sql = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name LIKE %s AND post_status NOT IN ('trash', 'auto-draft', 'inherit') LIMIT 5",
                $definition->route['archive'],
                $like
            );
        } elseif ($route['post'] !== null) {
            $sql = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_name LIKE %s AND post_status NOT IN ('trash', 'auto-draft', 'inherit') LIMIT 5",
                $route['post'],
                $like
            );
        } else {
            return [];
        }

        return array_map('intval', $wpdb->get_col($sql));
    }

    /**
     * @return list<array{level: string, code: string, message: string}>
     */
    private static function parameterChecks(ListingDefinition $definition): array
    {
        $checks = [];

        foreach (self::parameters($definition) as $parameter) {
            if ($parameter['reserved']) {
                $checks[] = self::check(self::ERROR, 'parameter', sprintf(
                    /* translators: %s: a query string parameter */
                    __('The parameter “%s” is reserved: WordPress, a plugin or a page cache reads it.', 'meiliscout'),
                    $parameter['name']
                ));
            }
        }

        return $checks;
    }
}
