<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Language;

/**
 * What listings need of a multilingual plugin (design §9): the request's
 * language, translations of posts and terms, terms read in any language, a
 * language for a while, and the listing's base filter in a language.
 *
 * Languages are the plugin's codes (fr, en); '' on a site without one.
 */
interface LanguageAdapter
{
    /**
     * The request's language, '' when unknown.
     */
    public function current(): string;

    public function default(): string;

    /**
     * @return list<string>
     */
    public function languages(): array;

    /**
     * A language's WordPress locale (fr_FR).
     */
    public function locale(string $language): string;

    /**
     * A post's translation in a language, null without one.
     */
    public function post(int $postId, string $language): ?int;

    /**
     * A post's language, '' when it has none.
     */
    public function postLanguage(int $postId): string;

    /**
     * A term's language, '' when it has none.
     */
    public function termLanguage(\WP_Term $term): string;

    /**
     * A term's translation in a language, null without one.
     */
    public function translateTerm(\WP_Term $term, string $language): ?\WP_Term;

    /**
     * A term by slug, whatever its language.
     */
    public function findTerm(string $slug, string $taxonomy): ?\WP_Term;

    /**
     * Runs a callback in a language (its queries, URLs and strings).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function in(string $language, callable $callback): mixed;

    /**
     * A listing's base WP_Query arguments in the current language.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function baseArgs(array $args): array;

    /**
     * Hooks its plugin's alternates (hreflang, language switcher) and canonical checks.
     */
    public function boot(): void;
}
