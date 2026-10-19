<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Render;

use Pollora\MeiliScout\Search\SearchWeights;
use Pollora\MeiliScout\Search\WeightedSearch;
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
    public const VERSION = 2;

    /**
     * What Meilisearch wraps a match in: private-use characters, never in a title (client.js MARK_OPEN, MARK_CLOSE).
     */
    public const MARK_OPEN = "\u{E000}";

    public const MARK_CLOSE = "\u{E001}";

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

            $index = IndexNames::active($source->isPosts() ? 'posts' : 'taxonomies');
            $search = [
                'indexUid' => $index,
                'filter' => $source->filter(),
                'limit' => $source->limit,
                'attributesToRetrieve' => array_values(array_unique($fields)),
                'attributesToHighlight' => [$source->isPosts() ? 'post_title' : 'name'],
            ];
            $entry = [
                'key' => $source->key,
                'type' => $source->type,
                'label' => $source->label,
                'limit' => $source->limit,
                'search' => $search,
            ];

            // Weights: one search per weight, the client merges them by weight × ranking score
            $tiers = $source->isPosts() && SearchWeights::isActive() ? WeightedSearch::tiers($source->objects, $index) : [];

            if (count($tiers) > 1) {
                $entry['searches'] = array_map(static fn (array $tier): array => [
                    'weight' => $tier['weight'],
                    'search' => [
                        ...$search,
                        'filter' => '('.$search['filter'].') AND post_type IN ['.implode(', ', array_map(static fn (string $type): string => json_encode($type, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $tier['types'])).']',
                        'attributesToSearchOn' => $tier['fields'],
                        'showRankingScore' => true,
                    ],
                ], $tiers);
            }

            $sources[] = $entry;
        }

        return ['version' => self::VERSION, 'sources' => $sources];
    }

    /**
     * The searches of a source, in the order their answers come back: one, or one per weight.
     *
     * @param  array<string, mixed>  $source  A source of the plan
     * @return list<array<string, mixed>>
     */
    public static function searches(array $source): array
    {
        $searches = isset($source['searches']) ? array_map(static fn (array $tier): array => (array) $tier['search'], (array) $source['searches']) : [(array) $source['search']];

        return array_values($searches);
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
