<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config\Portability\Sections;

use Pollora\MeiliScout\Config\Portability\Normalize;
use Pollora\MeiliScout\Config\Portability\Section;
use Pollora\MeiliScout\Config\Portability\SectionPlan;
use Pollora\MeiliScout\Config\RealtimeIndexing;
use Pollora\MeiliScout\Config\SearchableAttributes;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Indexables\TaxonomyIndexable;
use Pollora\MeiliScout\Services\MissedMetaKeys;

use function __;
use function _n;
use function sprintf;

/**
 * What is indexed (types, taxonomies, meta keys), what is searched and in
 * which order, when changes are sent, how a full indexation is cut.
 */
final class IndexingSection implements Section
{
    /**
     * Fields whose change makes the index out of date.
     */
    private const STRUCTURE = ['post_types', 'taxonomies', 'meta_keys', 'term_meta_keys', 'index_private', 'searchable_attributes'];

    public function key(): string
    {
        return 'indexing';
    }

    public function label(): string
    {
        return __('What is indexed', 'meiliscout');
    }

    public function summary(): string
    {
        $types = count((array) Settings::get('indexed_post_types', []));
        $taxonomies = count((array) Settings::get('indexed_taxonomies', []));
        $fields = count((array) Settings::get('indexed_meta_keys', []));

        return sprintf(
            /* translators: 1: a number of post types, 2: of taxonomies, 3: of custom fields */
            __('%1$s · %2$s · %3$s', 'meiliscout'),
            sprintf(_n('%d type', '%d types', $types, 'meiliscout'), $types),
            sprintf(_n('%d taxonomy', '%d taxonomies', $taxonomies, 'meiliscout'), $taxonomies),
            sprintf(_n('%d field', '%d fields', $fields, 'meiliscout'), $fields)
        );
    }

    public function unavailable(): ?string
    {
        return null;
    }

    public function export(bool $includeEnvironment): array
    {
        $metaKeys = $this->strings(Settings::get('indexed_meta_keys', []));

        return [
            'post_types' => $this->strings(Settings::get('indexed_post_types', [])),
            'taxonomies' => $this->strings(Settings::get('indexed_taxonomies', [])),
            'meta_keys' => $metaKeys,
            'term_meta_keys' => $this->strings(Settings::get(TaxonomyIndexable::META_KEYS_SETTING, [])),
            'index_private' => (bool) Settings::get(PostIndexable::INDEX_PRIVATE, false),
            'searchable_attributes' => SearchableAttributes::configured() ?? [],
            'realtime_indexing' => RealtimeIndexing::mode(),
            'posts_per_page' => (int) Settings::get('indexing.posts_per_page', 200),
            'batch_size' => (int) Settings::get('bulk_batch_size', 500),
        ];
    }

    public function plan(array $data, array $options = []): SectionPlan
    {
        $plan = new SectionPlan($this->key());
        $current = $this->export(false);
        $known = array_keys($current);

        foreach (array_diff(array_keys($data), $known) as $unknown) {
            $plan->warn(sprintf(
                /* translators: %s: the name of a setting in the file */
                __('“%s” is not a setting of this version: ignored.', 'meiliscout'),
                (string) $unknown
            ));
        }

        $lists = [
            'post_types' => [__('Post types', 'meiliscout'), 'post_type_exists', __('not a registered post type here', 'meiliscout')],
            'taxonomies' => [__('Taxonomies', 'meiliscout'), 'taxonomy_exists', __('not a registered taxonomy here', 'meiliscout')],
            'meta_keys' => [__('Custom fields', 'meiliscout'), null, null],
            'term_meta_keys' => [__('Term fields', 'meiliscout'), null, null],
        ];

        $incoming = [];
        foreach ($lists as $field => [$label, $registered, $why]) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $values = Normalize::strings($data[$field]);
            if ($values === null) {
                $plan->fail(sprintf(
                    /* translators: %s: a setting's name */
                    __('“%s” must be a list of names.', 'meiliscout'),
                    $label
                ));
                continue;
            }

            $incoming[$field] = $values;
            $plan->data[$field] = $values;
            $plan->row($field, $label, $current[$field], $values);

            if ($registered !== null) {
                foreach ($values as $name) {
                    if (! $registered($name)) {
                        // Kept, as the plugin that registers it may not be active yet; the Content screen drops it when saved
                        $plan->warn(sprintf(
                            /* translators: 1: a post type or taxonomy, 2: why it is a problem */
                            __('“%1$s” is %2$s. It is kept, but saving the Content screen would drop it until it is.', 'meiliscout'),
                            $name,
                            $why
                        ));
                    }
                }
            }
        }

        $this->flag('index_private', __('Index private content', 'meiliscout'), $data, $current, $plan);

        if (array_key_exists('searchable_attributes', $data)) {
            $attributes = Normalize::strings($data['searchable_attributes']);
            if ($attributes === null && $data['searchable_attributes'] !== []) {
                $plan->fail(__('“Relevance” must be a list of fields.', 'meiliscout'));
            } else {
                $attributes ??= [];
                // What the admin would accept once the meta keys are saved
                $allowed = SearchableAttributes::availableFor($incoming['meta_keys'] ?? $current['meta_keys']);
                $kept = array_values(array_filter($attributes, static fn (string $a) => in_array($a, $allowed, true)));

                foreach (array_diff($attributes, $kept) as $dropped) {
                    $plan->warn(sprintf(
                        /* translators: %s: a field of the documents */
                        __('“%s” cannot be searched on this site (its custom field is not indexed): dropped.', 'meiliscout'),
                        $dropped
                    ));
                }

                $plan->data['searchable_attributes'] = $kept;
                // The order is what this setting says
                $plan->row('searchable_attributes', __('Relevance', 'meiliscout'), $current['searchable_attributes'], $kept, null, $current['searchable_attributes'] === $kept ? SectionPlan::UNCHANGED : SectionPlan::CHANGED);
            }
        }

        if (array_key_exists('realtime_indexing', $data)) {
            $mode = $data['realtime_indexing'];
            if (! is_string($mode) || ! in_array($mode, RealtimeIndexing::MODES, true)) {
                $plan->fail(sprintf(
                    /* translators: %s: the modes there are */
                    __('“Real time” must be one of: %s.', 'meiliscout'),
                    implode(', ', RealtimeIndexing::MODES)
                ));
            } elseif (RealtimeIndexing::isLocked()) {
                $plan->row('realtime_indexing', __('Real time', 'meiliscout'), $current['realtime_indexing'], $mode, __('Set by the environment on this site: not changed.', 'meiliscout'), SectionPlan::WARNING);
            } else {
                $plan->data['realtime_indexing'] = $mode;
                $plan->row('realtime_indexing', __('Real time', 'meiliscout'), $current['realtime_indexing'], $mode);
            }
        }

        foreach (['posts_per_page' => __('Posts per page of an indexation', 'meiliscout'), 'batch_size' => __('Contents per batch', 'meiliscout')] as $field => $label) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $number = Normalize::positiveInt($data[$field]);
            if ($number === null) {
                $plan->fail(sprintf(
                    /* translators: %s: a setting's name */
                    __('“%s” must be a positive whole number.', 'meiliscout'),
                    $label
                ));
                continue;
            }

            $plan->data[$field] = $number;
            $plan->row($field, $label, $current[$field], $number);
        }

        foreach ($plan->rows as $row) {
            if ($row['status'] === SectionPlan::CHANGED && in_array($row['field'], self::STRUCTURE, true)) {
                $plan->reindex = true;
            }
        }

        return $plan;
    }

    public function apply(SectionPlan $plan): void
    {
        $data = $plan->data;

        // The admin's order: the meta keys first, a field can be searched once it is indexed
        $saves = [
            'post_types' => 'indexed_post_types',
            'taxonomies' => 'indexed_taxonomies',
            'meta_keys' => 'indexed_meta_keys',
            'term_meta_keys' => TaxonomyIndexable::META_KEYS_SETTING,
        ];

        foreach ($saves as $field => $setting) {
            if (array_key_exists($field, $data)) {
                Settings::save($setting, $data[$field]);
            }
        }

        $this->forgetMissed(MissedMetaKeys::SETTING, $data['meta_keys'] ?? null);
        $this->forgetMissed(MissedMetaKeys::TERM_SETTING, $data['term_meta_keys'] ?? null);

        if (array_key_exists('index_private', $data)) {
            Settings::save(PostIndexable::INDEX_PRIVATE, (bool) $data['index_private']);
        }

        if (array_key_exists('searchable_attributes', $data)) {
            SearchableAttributes::save($data['searchable_attributes']);
        }

        if (array_key_exists('realtime_indexing', $data)) {
            RealtimeIndexing::save($data['realtime_indexing']);
        }

        if (array_key_exists('posts_per_page', $data)) {
            Settings::save('indexing.posts_per_page', $data['posts_per_page']);
        }

        if (array_key_exists('batch_size', $data)) {
            Settings::save('bulk_batch_size', $data['batch_size']);
        }
    }

    /**
     * @param  array<mixed>  $data
     * @param  array<string, mixed>  $current
     */
    private function flag(string $field, string $label, array $data, array $current, SectionPlan $plan): void
    {
        if (! array_key_exists($field, $data)) {
            return;
        }

        if (! is_bool($data[$field])) {
            $plan->fail(sprintf(
                /* translators: %s: a setting's name */
                __('“%s” must be true or false.', 'meiliscout'),
                $label
            ));

            return;
        }

        $plan->data[$field] = $data[$field];
        $plan->row($field, $label, $current[$field], $data[$field]);
    }

    /**
     * Keys now indexed are no longer missed by queries.
     *
     * @param  list<string>|null  $indexed
     */
    private function forgetMissed(string $setting, ?array $indexed): void
    {
        if ($indexed === null) {
            return;
        }

        $missed = (array) Settings::get($setting, []);
        $still = array_values(array_diff($missed, $indexed));

        if ($still !== $missed) {
            Settings::save($setting, $still);
        }
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return array_values(array_filter((array) $value, 'is_string'));
    }
}
