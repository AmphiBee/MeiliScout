<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config\Portability;

/**
 * What an import would do, section by section, and what the file said about itself.
 */
final class ImportPlan
{
    /**
     * @param  array<string, SectionPlan>  $sections
     * @param  array<string, string>  $labels  A section's label, by key
     * @param  list<string>  $warnings  What is wrong with the file as a whole
     * @param  array<string, mixed>  $source
     */
    public function __construct(
        public readonly array $sections,
        public readonly array $labels = [],
        public readonly array $warnings = [],
        public readonly array $source = [],
        public readonly bool $strict = false,
    ) {}

    /**
     * Whether anything would be written.
     */
    public function changes(): bool
    {
        foreach ($this->sections as $section) {
            if ($section->changes()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the index is out of date once it is applied.
     */
    public function reindex(): bool
    {
        foreach ($this->sections as $section) {
            if ($section->reindex && $section->changes()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether it can be applied: with `strict`, a warning is a refusal.
     */
    public function applicable(): bool
    {
        if (! $this->changes()) {
            return false;
        }

        return ! ($this->strict && $this->hasWarnings());
    }

    public function hasWarnings(): bool
    {
        if ($this->warnings !== []) {
            return true;
        }

        foreach ($this->sections as $section) {
            if ($section->warnings !== []) {
                return true;
            }
        }

        return false;
    }

    public function hasErrors(): bool
    {
        foreach ($this->sections as $section) {
            if ($section->errors !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $sections = [];
        foreach ($this->sections as $key => $section) {
            $sections[] = $section->toArray($this->labels[$key] ?? $key);
        }

        return [
            'source' => $this->source,
            'warnings' => $this->warnings,
            'sections' => $sections,
            'reindex' => $this->reindex(),
            'applicable' => $this->applicable(),
            'strict' => $this->strict,
        ];
    }
}
