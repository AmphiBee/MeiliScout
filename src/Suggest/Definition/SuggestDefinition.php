<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Definition;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Listings\PublicFields;
use Pollora\MeiliScout\Suggest\Template\SuggestTemplate;

/**
 * A search field with suggestions: its groups of suggestions (sources), what
 * the visitor sees while typing, and where "all results" leads. Declared in
 * PHP (meiliscout_register_suggest()) or by a block; both give this structure.
 *
 * Validated once: a source naming an unindexed post type, taxonomy or meta key,
 * or an unknown option, is refused with every reason.
 */
final class SuggestDefinition
{
    public const MAX_LIMIT = 20;

    /**
     * `direct`: the browser asks Meilisearch with a token. `rest`: it asks
     * WordPress, which asks Meilisearch: for a Meilisearch no browser can reach.
     */
    public const TRANSPORTS = ['direct', 'rest'];

    /**
     * @param  array<string, SuggestSource>  $sources  By key, in display order
     * @param  list<string>  $publicMetas  Meta keys browsers may read (templates show them)
     */
    private function __construct(
        public readonly string $id,
        public readonly array $sources,
        public readonly int $minChars,
        public readonly int $debounce,
        public readonly string $searchParam,
        public readonly ?string $resultsUrl,
        public readonly bool $recent,
        public readonly array $publicMetas,
        public readonly string $transport = 'direct',
    ) {}

    /**
     * Every post type the sources read, in order of appearance.
     *
     * @return list<string>
     */
    public function postTypes(): array
    {
        return $this->objects(SuggestSource::POSTS);
    }

    /**
     * Every taxonomy the sources read, in order of appearance.
     *
     * @return list<string>
     */
    public function taxonomies(): array
    {
        return $this->objects(SuggestSource::TERMS);
    }

    /**
     * @param  array<string, mixed>  $args
     *
     * @throws InvalidSuggest
     */
    public static function fromArray(string $id, array $args): self
    {
        $errors = [];

        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id)) {
            $errors[] = 'The id may only hold lowercase letters, digits, - and _.';
        }

        $sources = self::sources($args['sources'] ?? null, $errors);

        $minChars = self::integer($args, 'min_chars', 2, 1, 5, $errors);
        $debounce = self::integer($args, 'debounce', 120, 0, 1000, $errors);

        $searchParam = (string) ($args['search_param'] ?? 's');
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $searchParam)) {
            $errors[] = 'search_param must be a query string parameter name.';
        }

        $resultsUrl = $args['results_url'] ?? null;
        if ($resultsUrl !== null && (! is_string($resultsUrl) || $resultsUrl === '')) {
            $errors[] = 'results_url must be a URL, or left out for the site\'s search page.';
            $resultsUrl = null;
        }

        $recent = $args['recent'] ?? true;
        if (! is_bool($recent)) {
            $errors[] = 'recent must be true or false.';
            $recent = true;
        }

        $publicMetas = [];
        $indexedMetas = (array) Settings::get('indexed_meta_keys', []);
        foreach ((array) ($args['public_metas'] ?? []) as $key) {
            if (! is_string($key) || ! in_array($key, $indexedMetas, true)) {
                $errors[] = sprintf('The meta key "%s" in public_metas is not indexed (Content › Custom fields).', is_scalar($key) ? (string) $key : gettype($key));

                continue;
            }
            $publicMetas[] = $key;
        }

        $transport = $args['transport'] ?? 'direct';
        if (! is_string($transport) || ! in_array($transport, self::TRANSPORTS, true)) {
            $errors[] = sprintf('transport must be one of %s.', implode(', ', self::TRANSPORTS));
            $transport = 'direct';
        }

        $known = ['sources', 'min_chars', 'debounce', 'search_param', 'results_url', 'recent', 'public_metas', 'transport'];
        foreach (array_diff(array_keys($args), $known) as $unknown) {
            $errors[] = sprintf('Unknown option "%s".', $unknown);
        }

        if ($errors !== []) {
            throw new InvalidSuggest($id, $errors);
        }

        return new self($id, $sources, $minChars, $debounce, $searchParam, $resultsUrl, $recent, array_values(array_unique($publicMetas)), $transport);
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, SuggestSource>
     */
    private static function sources(mixed $declared, array &$errors): array
    {
        if (! is_array($declared) || $declared === []) {
            $errors[] = 'sources is required: at least one group of suggestions.';

            return [];
        }

        $indexedTypes = (array) Settings::get('indexed_post_types', []);
        $indexedTaxonomies = (array) Settings::get('indexed_taxonomies', []);
        $sources = [];

        foreach ($declared as $key => $source) {
            $key = (string) $key;

            if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $key)) {
                $errors[] = sprintf('The source key "%s" may only hold lowercase letters, digits, - and _.', $key);

                continue;
            }

            if (! is_array($source)) {
                $errors[] = sprintf('The source "%s" must be an array.', $key);

                continue;
            }

            $type = (string) ($source['type'] ?? SuggestSource::POSTS);
            if (! in_array($type, [SuggestSource::POSTS, SuggestSource::TERMS], true)) {
                $errors[] = sprintf('The source "%s": type must be posts or terms.', $key);

                continue;
            }

            $isPosts = $type === SuggestSource::POSTS;
            $field = $isPosts ? 'post_types' : 'taxonomies';
            $objects = array_values(array_map('strval', (array) ($source[$field] ?? [])));

            if ($objects === []) {
                $errors[] = sprintf('The source "%s": %s is required.', $key, $field);
            }

            $indexed = $isPosts ? $indexedTypes : $indexedTaxonomies;
            foreach (array_diff($objects, $indexed) as $object) {
                $errors[] = $isPosts
                    ? sprintf('The source "%s": the post type "%s" is not indexed (Content › Post types).', $key, $object)
                    : sprintf('The source "%s": the taxonomy "%s" is not indexed (Content › Taxonomies).', $key, $object);
            }

            $limit = self::integer($source, 'limit', $isPosts ? 5 : 3, 1, self::MAX_LIMIT, $errors, "The source \"{$key}\": ");

            $template = $source['template'] ?? null;
            if ($template !== null && ! is_callable($template) && ! $template instanceof SuggestTemplate && (! is_string($template) || trim($template) === '')) {
                $errors[] = sprintf('The source "%s": template must be a callable, a SuggestTemplate, or a string (HTML, blade:<view>, twig:<template>, js:<name> or a theme template part).', $key);
                $template = null;
            }

            $fields = [];
            foreach ((array) ($source['fields'] ?? []) as $name) {
                $public = $isPosts ? PublicFields::FIELDS : PublicFields::TERM_FIELDS;
                if (! is_string($name) || ! in_array($name, $public, true)) {
                    $errors[] = sprintf('The source "%s": the field "%s" is not one browsers may read (%s).', $key, is_scalar($name) ? (string) $name : gettype($name), implode(', ', $public));

                    continue;
                }
                $fields[] = $name;
            }

            $label = $source['label'] ?? '';
            if (! is_string($label)) {
                $errors[] = sprintf('The source "%s": label must be a string.', $key);
                $label = '';
            }

            foreach (array_diff(array_keys($source), ['type', $field, 'limit', 'label', 'template', 'fields']) as $unknown) {
                $errors[] = sprintf('The source "%s": unknown option "%s".', $key, $unknown);
            }

            $sources[$key] = new SuggestSource($key, $type, $objects, $limit, $label, $template, array_values(array_unique($fields)));
        }

        return $sources;
    }

    /**
     * @param  array<array-key, mixed>  $args
     * @param  list<string>  $errors
     */
    private static function integer(array $args, string $option, int $default, int $min, int $max, array &$errors, string $prefix = ''): int
    {
        $value = $args[$option] ?? $default;

        if (! is_int($value) || $value < $min || $value > $max) {
            $errors[] = sprintf('%s%s must be a whole number from %d to %d.', $prefix, $option, $min, $max);

            return $default;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function objects(string $type): array
    {
        $objects = [];

        foreach ($this->sources as $source) {
            if ($source->type === $type) {
                array_push($objects, ...$source->objects);
            }
        }

        return array_values(array_unique($objects));
    }
}
