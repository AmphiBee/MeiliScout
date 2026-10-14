<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config\Portability;

/**
 * What importing a section would do: each setting before and after, what is
 * wrong with the file, whether the index must be rebuilt.
 */
final class SectionPlan
{
    public const CHANGED = 'changed';

    public const UNCHANGED = 'unchanged';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    /**
     * @param  list<array{field: string, label: string, before: mixed, after: mixed, status: string, note: string|null}>  $rows
     * @param  list<string>  $warnings
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $data  The normalized values to write
     */
    public function __construct(
        public readonly string $key,
        public array $rows = [],
        public array $warnings = [],
        public array $errors = [],
        public bool $reindex = false,
        public array $data = [],
        public ?string $skipped = null,
    ) {}

    public function row(string $field, string $label, mixed $before, mixed $after, ?string $note = null, ?string $status = null): void
    {
        $this->rows[] = [
            'field' => $field,
            'label' => $label,
            'before' => $before,
            'after' => $after,
            'status' => $status ?? (self::same($before, $after) ? self::UNCHANGED : self::CHANGED),
            'note' => $note,
        ];
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function fail(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * Whether the section would write anything.
     */
    public function changes(): bool
    {
        if ($this->skipped !== null || $this->errors !== []) {
            return false;
        }

        foreach ($this->rows as $row) {
            if ($row['status'] === self::CHANGED) {
                return true;
            }
        }

        return false;
    }

    public function status(): string
    {
        return match (true) {
            $this->errors !== [] => self::ERROR,
            $this->skipped !== null => 'skipped',
            $this->changes() => self::CHANGED,
            default => self::UNCHANGED,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $label = ''): array
    {
        return [
            'key' => $this->key,
            'label' => $label,
            'status' => $this->status(),
            'skipped' => $this->skipped,
            'rows' => $this->rows,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'reindex' => $this->reindex && $this->changes(),
        ];
    }

    /**
     * Lists compare as sets unless their order is what they say.
     */
    public static function same(mixed $before, mixed $after): bool
    {
        if (is_array($before) && is_array($after) && array_is_list($before) && array_is_list($after)) {
            $a = $before;
            $b = $after;
            sort($a);
            sort($b);

            return $a === $b;
        }

        return $before === $after;
    }
}
