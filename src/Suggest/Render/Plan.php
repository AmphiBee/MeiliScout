<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Render;

use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Suggest\Definition\SuggestDefinition;
use Pollora\MeiliScout\Suggest\Definition\SuggestSource;

/**
 * What the client replays for a field: one search per source, written here
 * and sent as is, so that PHP and the browser never disagree on an index, a
 * filter or a field. `VERSION` is the client's contract: a client that does
 * not know it leaves the form to submit as a plain search.
 */
final class Plan
{
    public const VERSION = 1;

    /**
     * @param  array<string, array{markup: string, fields: list<string>, js: string|null}>|null  $templates  The sources' templates, compiled (Templates::forDefinition())
     * @return array{version: int, sources: list<array<string, mixed>>}
     */
    public static function forDefinition(SuggestDefinition $definition, ?array $templates = null): array
    {
        $templates ??= Templates::forDefinition($definition);
        $sources = [];

        foreach ($definition->sources as $source) {
            $fields = $templates[$source->key]['fields'];

            if ($source->isPosts()) {
                foreach ($definition->publicMetas as $key) {
                    $fields[] = "metas.{$key}";
                }
            }

            $sources[] = [
                'key' => $source->key,
                'type' => $source->type,
                'label' => $source->label,
                'limit' => $source->limit,
                'search' => [
                    'indexUid' => IndexNames::active($source->isPosts() ? 'posts' : 'taxonomies'),
                    'filter' => $source->filter(),
                    'limit' => $source->limit,
                    'attributesToRetrieve' => array_values(array_unique($fields)),
                    'attributesToHighlight' => [$source->isPosts() ? 'post_title' : 'name'],
                ],
            ];
        }

        return ['version' => self::VERSION, 'sources' => $sources];
    }

    /**
     * The labels of what a suggestion is: post types and taxonomies.
     *
     * @return array<string, string>
     */
    public static function labels(SuggestDefinition $definition): array
    {
        $labels = [];

        foreach ($definition->postTypes() as $type) {
            $labels[$type] = (string) (get_post_type_object($type)->labels->singular_name ?? $type);
        }

        foreach ($definition->taxonomies() as $taxonomy) {
            $labels[$taxonomy] = (string) (get_taxonomy($taxonomy)->labels->singular_name ?? $taxonomy);
        }

        return $labels;
    }
}
