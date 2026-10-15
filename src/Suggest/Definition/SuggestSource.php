<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Definition;

/**
 * One group of suggestions of a field: the posts of some types, or the terms
 * of some taxonomies, with its own limit, label and template.
 */
final class SuggestSource
{
    public const POSTS = 'posts';

    public const TERMS = 'terms';

    /**
     * @param  list<string>  $objects  Post types (posts) or taxonomies (terms)
     * @param  string|callable|null  $template  How a suggestion is drawn: see docs
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly array $objects,
        public readonly int $limit,
        public readonly string $label,
        public readonly mixed $template = null,
    ) {}

    public function isPosts(): bool
    {
        return $this->type === self::POSTS;
    }

    /**
     * The Meilisearch filter of this source alone: what the client adds to its
     * search, inside the token's wider one.
     */
    public function filter(): string
    {
        $values = implode(', ', array_map(static fn (string $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->objects));

        return ($this->isPosts() ? 'post_type' : 'taxonomy').' IN ['.$values.']';
    }
}
