<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config\Portability\Sections;

use Pollora\MeiliScout\Config\Portability\Section;
use Pollora\MeiliScout\Config\Portability\SectionPlan;
use Pollora\MeiliScout\Embedders\Embedders;

use function __;
use function _n;
use function sprintf;

/**
 * The embedders of the indexes, for the record: name, source, model, template.
 *
 * They are declared in code (meiliscout_register_embedder()), so an import
 * writes nothing; it tells where the file and the site differ. API keys and
 * headers are never part of the file.
 */
final class EmbeddersSection implements Section
{
    public function key(): string
    {
        return 'embedders';
    }

    public function label(): string
    {
        return __('Embedders', 'meiliscout');
    }

    public function summary(): string
    {
        $count = array_sum(array_map('count', Embedders::all()));

        return sprintf(
            /* translators: %d: a number of embedders */
            _n('%d embedder, declared in code', '%d embedders, declared in code', $count, 'meiliscout'),
            $count
        );
    }

    public function unavailable(): ?string
    {
        return Embedders::all() === [] ? __('No embedder is declared.', 'meiliscout') : null;
    }

    /**
     * @return array<string, array<string, array<string, mixed>>> By base index name, then by embedder name
     */
    public function export(bool $includeEnvironment): array
    {
        return array_map(
            static fn (array $embedders): array => array_map([Embedders::class, 'describe'], $embedders),
            Embedders::all()
        );
    }

    public function plan(array $data, array $options = []): SectionPlan
    {
        $plan = new SectionPlan($this->key());
        $site = $this->export(false);

        foreach (array_unique([...array_keys($site), ...array_keys($data)]) as $base) {
            $before = $site[$base] ?? [];
            $after = is_array($data[$base] ?? null) ? $data[$base] : [];
            $plan->row((string) $base, (string) $base, array_keys($before), array_keys($after), null, $before == $after ? SectionPlan::UNCHANGED : SectionPlan::WARNING);
        }

        if (array_filter($plan->rows, static fn (array $row) => $row['status'] === SectionPlan::WARNING) !== []) {
            $plan->warn(__('Embedders are declared in code (meiliscout_register_embedder()): the import does not change them.', 'meiliscout'));
        }

        return $plan;
    }

    public function apply(SectionPlan $plan): void
    {
        // Declared in code: nothing to write
    }
}
