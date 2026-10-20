<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Search;

use Pollora\MeiliScout\Config\SearchableAttributes;
use Pollora\MeiliScout\Config\Settings;

/**
 * How much each field of the posts index counts in a search, per post type.
 *
 * A weight is a number from 0 (the field is not searched) to 10. Meilisearch
 * only orders its searchable attributes; real weights are made at search time
 * by WeightedSearch (one federated query per weight tier).
 */
final class SearchWeights
{
    public const SETTING = 'search_weights';

    public const MAX = 10.0;

    /**
     * What a type searches until the admin says otherwise.
     */
    public const DEFAULT_PROFILE = ['post_title' => 4.0, 'terms.name' => 2.0, 'post_excerpt' => 1.5, 'content_text' => 1.0];

    /**
     * The weights set in the admin, by post type then field, or null when none were.
     *
     * @return array<string, array<string, float>>|null
     */
    public static function configured(): ?array
    {
        $saved = Settings::get(self::SETTING, null);

        if (! is_array($saved) || $saved === []) {
            return null;
        }

        $weights = [];
        foreach ($saved as $type => $fields) {
            if (is_string($type) && is_array($fields)) {
                $weights[$type] = self::normalize($fields);
            }
        }

        return $weights === [] ? null : $weights;
    }

    public static function isActive(): bool
    {
        return self::configured() !== null;
    }

    /**
     * The weights of a post type, 0 included: its own, or the defaults.
     *
     * @return array<string, float>
     */
    public static function profile(string $type): array
    {
        $profile = self::configured()[$type] ?? self::DEFAULT_PROFILE;

        // meiliscout/search_weights filters a post type's weights (field => weight)
        return self::normalize((array) apply_filters('meiliscout/search_weights', $profile, $type));
    }

    /**
     * The fields a post type is searched in, with their weight.
     *
     * @return array<string, float>
     */
    public static function enabled(string $type): array
    {
        return array_filter(self::profile($type), static fn (float $weight) => $weight > 0);
    }

    /**
     * Every field some type searches (the indexed types by default), the heaviest first: what the index's searchable attributes become.
     *
     * @param  list<string>|null  $types
     * @return list<string>
     */
    public static function enabledFields(?array $types = null): array
    {
        $best = [];
        foreach ($types ?? self::indexedTypes() as $type) {
            foreach (self::enabled($type) as $field => $weight) {
                $best[$field] = max($best[$field] ?? 0.0, $weight);
            }
        }

        $order = array_flip(SearchableAttributes::SUGGESTED);
        uksort($best, static fn (string $a, string $b) => [-$best[$a], $order[$a] ?? 99, $a] <=> [-$best[$b], $order[$b] ?? 99, $b]);

        return array_keys($best);
    }

    /**
     * Weight tiers for post types: fields that weigh the same for a type are one query.
     *
     * @param  list<string>  $types
     * @param  array<string, array<string, mixed>>|null  $profiles  Weights to use instead of the saved ones (a preview)
     * @return list<array{fields: list<string>, weight: float, types: list<string>}>
     */
    public static function tiers(array $types, ?array $profiles = null): array
    {
        $tiers = [];
        foreach ($types as $type) {
            $byWeight = [];
            $enabled = $profiles !== null
                ? array_filter(self::normalize($profiles[$type] ?? []), static fn (float $weight) => $weight > 0)
                : self::enabled($type);
            foreach ($enabled as $field => $weight) {
                $byWeight[(string) $weight][] = $field;
            }
            foreach ($byWeight as $weight => $fields) {
                sort($fields);
                $key = $weight.'|'.implode(',', $fields);
                $tiers[$key] ??= ['fields' => $fields, 'weight' => (float) $weight, 'types' => []];
                $tiers[$key]['types'][] = $type;
            }
        }

        $tiers = array_values($tiers);
        usort($tiers, static fn (array $a, array $b) => $b['weight'] <=> $a['weight']);

        return $tiers;
    }

    /**
     * Saves the weights: only the indexed types and the fields the admin can choose, weights clamped to 0-10 by halves.
     *
     * @param  array<mixed>  $weights
     */
    public static function save(array $weights): void
    {
        $allowed = SearchableAttributes::available();
        $types = self::indexedTypes();
        $clean = [];

        foreach ($weights as $type => $fields) {
            if (! is_string($type) || ! in_array($type, $types, true) || ! is_array($fields)) {
                continue;
            }
            $clean[$type] = array_intersect_key(self::normalize($fields), array_flip($allowed));
        }

        Settings::save(self::SETTING, $clean);
    }

    /**
     * @param  array<mixed>  $fields
     * @return array<string, float>
     */
    public static function normalize(array $fields): array
    {
        $clean = [];
        foreach ($fields as $field => $weight) {
            if (is_string($field) && is_numeric($weight)) {
                $clean[$field] = round(min(self::MAX, max(0.0, (float) $weight)) * 2) / 2;
            }
        }

        return $clean;
    }

    /**
     * @return list<string>
     */
    public static function indexedTypes(): array
    {
        return array_values(array_filter((array) Settings::get('indexed_post_types', []), 'is_string'));
    }
}
