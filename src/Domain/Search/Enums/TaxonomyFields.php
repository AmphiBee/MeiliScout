<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Domain\Search\Enums;

enum TaxonomyFields: string
{
    case TERM_ID = 'term_id';
    case SLUG = 'slug';
    case NAME = 'name';
    case TERM_TAXONOMY_ID = 'term_taxonomy_id';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $field) => $field->value, self::cases());
    }

    /**
     * Gets the default taxonomy field.
     *
     * This is the `term_id` field by default.
     */
    public static function getDefault(): self
    {
        return self::TERM_ID;
    }
}
