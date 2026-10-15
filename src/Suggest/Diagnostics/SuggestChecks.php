<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Diagnostics;

use Pollora\MeiliScout\Config\Config;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Listings\Diagnostics\ListingChecks;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\Transport\TenantTokens;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Suggest\Blocks\BlockSuggests;
use Pollora\MeiliScout\Suggest\Definition\InvalidSuggest;
use Pollora\MeiliScout\Suggest\Definition\SuggestDefinition;
use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;
use Pollora\MeiliScout\Suggest\Definition\SuggestSource;
use Pollora\MeiliScout\Suggest\Template\SuggestTemplate;
use Pollora\MeiliScout\Suggest\Transport\SuggestTokens;

/**
 * What the Suggestions screen and `wp meiliscout check-suggest` say of each
 * declared field: where it comes from, its sources and what each reads, and
 * its checks: the definition, the content the sources read, the index's
 * format, the key and token, what a browser holding the token can read, and
 * where browsers reach Meilisearch.
 *
 * A check is error (the field does not work), warning (works, but something
 * is off) or info.
 */
final class SuggestChecks
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /**
     * Every declared field's report.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        // false: asked once, and Meilisearch could not say
        $documents = ListingChecks::documents() ?? false;
        $terms = self::termDocuments() ?? false;

        return array_map(fn (string $id) => self::report($id, $documents, $terms), SuggestRegistry::ids());
    }

    /**
     * A field's report.
     *
     * @param  array<string, int>|false|null  $documents  Published documents by post type; false when unknown, null to ask Meilisearch
     * @param  array<string, int>|false|null  $terms  Term documents by taxonomy; false when unknown, null to ask Meilisearch
     * @return array<string, mixed>
     */
    public static function report(string $id, array|false|null $documents = null, array|false|null $terms = null): array
    {
        $documents = $documents === null ? ListingChecks::documents() : ($documents === false ? null : $documents);
        $terms = $terms === null ? self::termDocuments() : ($terms === false ? null : $terms);

        $block = BlockSuggests::saved()[$id] ?? null;
        $report = [
            'id' => $id,
            'source' => str_starts_with($id, 'block-') ? 'block' : 'php',
            'post' => $block === null ? null : self::post((int) $block['post']),
            'valid' => true,
            'transport' => 'direct',
            'sources' => [],
            'checks' => [],
        ];

        try {
            $definition = SuggestRegistry::get($id);
        } catch (InvalidSuggest $e) {
            $report['valid'] = false;
            $report['checks'] = array_map(fn (string $error) => self::check(self::ERROR, 'definition', $error), $e->errors);

            return $report;
        } catch (\OutOfBoundsException) {
            return ['valid' => false, 'checks' => [self::check(self::ERROR, 'definition', 'This field is not declared.')]] + $report;
        }

        $report['transport'] = $definition->transport;
        $report['sources'] = self::sources($definition, $documents, $terms);
        array_push($report['checks'], ...self::contentChecks($definition, $documents, $terms));
        array_push($report['checks'], ...self::engineChecks($definition));
        array_push($report['checks'], ...self::templateChecks($definition));

        return $report;
    }

    /**
     * The worst level of a report's checks, null when it has none.
     *
     * @param  array<string, mixed>  $report
     */
    public static function level(array $report): ?string
    {
        foreach ([self::ERROR, self::WARNING, self::INFO] as $level) {
            foreach ((array) $report['checks'] as $check) {
                if ($check['level'] === $level) {
                    return $level;
                }
            }
        }

        return null;
    }

    /**
     * Term documents by taxonomy, null when Meilisearch cannot say.
     *
     * @return array<string, int>|null
     */
    public static function termDocuments(): ?array
    {
        $client = ClientFactory::getClient();

        if ($client === null) {
            return null;
        }

        try {
            $result = $client->index(IndexNames::active('taxonomies'))->search('', ['facets' => ['taxonomy'], 'limit' => 0]);
        } catch (\Throwable) {
            return null;
        }

        return array_map('intval', (array) ($result->getFacetDistribution()['taxonomy'] ?? []));
    }

    /**
     * @param  array<string, int>|null  $documents
     * @param  array<string, int>|null  $terms
     * @return list<array<string, mixed>>
     */
    private static function sources(SuggestDefinition $definition, ?array $documents, ?array $terms): array
    {
        $rows = [];

        foreach ($definition->sources as $source) {
            $counts = $source->isPosts() ? $documents : $terms;
            $rows[] = [
                'key' => $source->key,
                'type' => $source->type,
                'objects' => $source->objects,
                'limit' => $source->limit,
                'label' => $source->label,
                'template' => self::templateKind($source),
                // What the sources read in the index, null when unknown
                'documents' => $counts === null ? null : array_sum(array_map(fn (string $object): int => $counts[$object] ?? 0, $source->objects)),
            ];
        }

        return $rows;
    }

    /**
     * How a source's suggestions are drawn, in a word.
     */
    public static function templateKind(SuggestSource $source): string
    {
        $template = $source->template;

        return match (true) {
            $template === null => 'default',
            $template instanceof SuggestTemplate => 'class',
            is_string($template) && str_starts_with($template, 'js:') => 'js',
            is_string($template) && str_starts_with($template, 'blade:') => 'blade',
            is_string($template) && str_starts_with($template, 'twig:') => 'twig',
            is_string($template) && str_starts_with(ltrim($template), '<') => 'html',
            is_string($template) => 'part',
            default => 'callable',
        };
    }

    /**
     * What the sources read: documents in the index, in the format a thumbnail needs.
     *
     * @param  array<string, int>|null  $documents
     * @param  array<string, int>|null  $terms
     * @return list<array{level: string, code: string, message: string}>
     */
    private static function contentChecks(SuggestDefinition $definition, ?array $documents, ?array $terms): array
    {
        $checks = [];

        if ($documents === null && $terms === null) {
            return [self::check(self::ERROR, 'engine', __('Meilisearch cannot be reached, or its indexes are not built yet.', 'meiliscout'))];
        }

        foreach ($definition->sources as $source) {
            $counts = $source->isPosts() ? $documents : $terms;

            foreach ($source->objects as $object) {
                if ($counts !== null && ($counts[$object] ?? 0) === 0) {
                    $checks[] = self::check(self::WARNING, 'empty_source', $source->isPosts()
                        /* translators: 1: a source's key, 2: a post type */
                        ? sprintf(__('Source “%1$s”: no published “%2$s” in the index. Run an indexation.', 'meiliscout'), $source->key, $object)
                        /* translators: 1: a source's key, 2: a taxonomy */
                        : sprintf(__('Source “%1$s”: no term of “%2$s” in the index. Run an indexation.', 'meiliscout'), $source->key, $object));
                }
            }
        }

        if ($definition->postTypes() !== [] && IndexNames::activeSchema('posts') < 6) {
            $checks[] = self::check(self::WARNING, 'schema', __('The posts index is in an older format: featured images are not suggested until a full indexation rebuilds it.', 'meiliscout'));
        }

        if ($definition->taxonomies() !== [] && Languages::adapter()->languages() !== []) {
            $checks[] = self::check(self::INFO, 'terms_languages', __('Terms are suggested in every language: the taxonomies index has no language yet.', 'meiliscout'));
        }

        return $checks;
    }

    /**
     * The way to Meilisearch: the transport, the key and token, what a browser reads with it, where browsers reach it.
     *
     * @return list<array{level: string, code: string, message: string}>
     */
    private static function engineChecks(SuggestDefinition $definition): array
    {
        if ($definition->transport === 'rest') {
            return [self::check(self::INFO, 'transport', __('WordPress runs the searches (transport “rest”): slower than Meilisearch directly, and no public URL is needed.', 'meiliscout'))];
        }

        $checks = [];
        $token = SuggestTokens::forDefinition($definition);

        if ($token === null) {
            return [self::check(self::ERROR, 'token', __('No token can be signed: the admin key must be allowed to manage keys (Meilisearch › API keys), and Meilisearch must be reachable.', 'meiliscout'))];
        }

        $checks[] = self::probe($definition, $token['value']);

        $setting = (string) Settings::get('listings_public_host', '');
        $host = TenantTokens::publicHost();

        if ($setting === '') {
            $checks[] = self::check(self::WARNING, 'public_host', sprintf(
                /* translators: %s: the address the server uses */
                __('No public URL of Meilisearch is set: browsers will use the address the server uses (%s), which they may not reach. Set it below, or search through WordPress.', 'meiliscout'),
                $host
            ));
        } elseif (is_ssl() && str_starts_with($host, 'http://')) {
            $checks[] = self::check(self::ERROR, 'mixed_content', sprintf(
                /* translators: %s: a URL */
                __('The site is served over HTTPS but Meilisearch’s public URL is %s: browsers block it. Serve Meilisearch over HTTPS.', 'meiliscout'),
                $host
            ));
        }

        return array_values(array_filter($checks));
    }

    /**
     * What a browser holding the token reads: a search with it, as a browser would.
     *
     * @return array{level: string, code: string, message: string}|null Null when it works
     */
    private static function probe(SuggestDefinition $definition, string $token): ?array
    {
        try {
            $client = ClientFactory::build((string) Config::get('meili_host'), $token);

            foreach (SuggestTokens::rules($definition) as $index => $rule) {
                $client->index($index)->search('', ['limit' => 1]);
            }
        } catch (\Throwable $e) {
            return self::check(self::ERROR, 'token_refused', sprintf(
                /* translators: %s: Meilisearch's answer */
                __('Meilisearch refused a search with the field’s token: %s', 'meiliscout'),
                $e->getMessage()
            ));
        }

        return null;
    }

    /**
     * What the templates ask of the site.
     *
     * @return list<array{level: string, code: string, message: string}>
     */
    private static function templateChecks(SuggestDefinition $definition): array
    {
        $checks = [];

        foreach ($definition->sources as $source) {
            if (is_string($source->template) && str_starts_with($source->template, 'js:')) {
                $checks[] = self::check(self::INFO, 'js_template', sprintf(
                    /* translators: 1: a source's key, 2: a template's name */
                    __('Source “%1$s” is drawn by a JavaScript template: the theme must register window.MeiliScout.suggestTemplates[“%2$s”] (suggestions show their title until it does).', 'meiliscout'),
                    $source->key,
                    substr($source->template, 3)
                ));
            }
        }

        return $checks;
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
}
