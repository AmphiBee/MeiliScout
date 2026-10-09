<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Language;

/**
 * A site in one language.
 */
final class NoLanguage implements LanguageAdapter
{
    public function current(): string
    {
        return '';
    }

    public function default(): string
    {
        return '';
    }

    public function languages(): array
    {
        return [];
    }

    public function locale(string $language): string
    {
        return get_locale();
    }

    public function post(int $postId, string $language): int
    {
        return $postId;
    }

    public function postLanguage(int $postId): string
    {
        return '';
    }

    public function termLanguage(\WP_Term $term): string
    {
        return '';
    }

    public function translateTerm(\WP_Term $term, string $language): \WP_Term
    {
        return $term;
    }

    public function findTerm(string $slug, string $taxonomy): ?\WP_Term
    {
        $term = get_term_by('slug', $slug, $taxonomy);

        return $term instanceof \WP_Term ? $term : null;
    }

    public function in(string $language, callable $callback): mixed
    {
        return $callback();
    }

    public function baseArgs(array $args): array
    {
        return $args;
    }

    public function boot(): void {}
}
