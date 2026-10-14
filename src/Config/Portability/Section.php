<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config\Portability;

/**
 * One part of the configuration that an export carries and an import applies.
 *
 * Sections are pure with regard to the file: they read the site to export,
 * compare a file with the site to plan, and write through the savers the admin
 * uses, so what an import writes is what the admin would have written.
 */
interface Section
{
    /**
     * Its name in the file (`indexing`, `queries`...).
     */
    public function key(): string;

    public function label(): string;

    /**
     * What it carries, in a few words (the export screen shows it).
     */
    public function summary(): string;

    /**
     * Why it cannot be exported or imported on this site, or null.
     */
    public function unavailable(): ?string;

    /**
     * What the file holds for it.
     *
     * @return array<string, mixed>
     */
    public function export(bool $includeEnvironment): array;

    /**
     * Compares what a file holds with the site, without writing anything.
     *
     * @param  array<mixed>  $data
     * @param  array{include_env?: bool}  $options
     */
    public function plan(array $data, array $options = []): SectionPlan;

    /**
     * Writes a plan this section made.
     */
    public function apply(SectionPlan $plan): void;
}
