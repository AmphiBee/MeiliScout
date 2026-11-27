<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Embedders;

use function apply_filters;

/**
 * The embedders declared for the indexes, merged into their settings whenever they are pushed.
 *
 * An embedder is Meilisearch's own setting (`source`, `url`, `request`,
 * `response`, `documentTemplate`...), declared in code by base index name
 * (`posts`, `manual_chunks`). Its API key is never stored: `apiKey` is a
 * callable, or the name of a constant or an environment variable, read
 * when the settings are sent. It is left out of the settings' fingerprint
 * and of the configuration export.
 */
final class Embedders
{
    /**
     * The setting of an embedder holding its API key.
     */
    public const API_KEY = 'apiKey';

    /**
     * Settings of an embedder that may carry a secret, left out of the configuration export.
     */
    private const SECRET_SETTINGS = [self::API_KEY, 'headers'];

    /**
     * Meilisearch's rule for an embedder's name.
     */
    private const NAME_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * Embedders declared in code, by base index name, then by name.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $declared = [];

    /**
     * Declares an embedder for some indexes; a later declaration of the same name replaces it.
     *
     * @param  array<string, mixed>  $config  Meilisearch's embedder settings; `apiKey` a callable or a constant / environment variable name
     * @param  list<string>  $indexes  Base index names
     *
     * @throws \InvalidArgumentException When the name or the source is missing
     */
    public static function declare(string $name, array $config, array $indexes = ['posts']): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException("Invalid embedder name \"{$name}\": letters, digits, - and _ only.");
        }

        if (! is_string($config['source'] ?? null) || $config['source'] === '') {
            throw new \InvalidArgumentException("The embedder \"{$name}\" has no source.");
        }

        foreach ($indexes as $base) {
            self::$declared[$base][$name] = $config;
        }
    }

    /**
     * Every embedder, by base index name then by name, as the meiliscout/embedders filter leaves them.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function all(): array
    {
        /**
         * Filters the embedders of the indexes.
         *
         * @param  array<string, array<string, array<string, mixed>>>  $embedders  By base index name, then by embedder name
         */
        /** @var mixed $embedders A filter's answer is whatever a plugin returned */
        $embedders = apply_filters('meiliscout/embedders', self::$declared);

        return is_array($embedders) ? array_filter($embedders, 'is_array') : [];
    }

    /**
     * The embedders of an index, by name.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forIndex(string $base): array
    {
        return self::all()[$base] ?? [];
    }

    /**
     * Adds an index's declared embedders to its settings; those the settings already have win.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function mergeInto(array $settings, string $base): array
    {
        $declared = self::forIndex($base);

        if ($declared === []) {
            return $settings;
        }

        $own = is_array($settings['embedders'] ?? null) ? $settings['embedders'] : [];
        $settings['embedders'] = [...$declared, ...$own];

        return $settings;
    }

    /**
     * The settings as Meilisearch receives them: each embedder's API key read now.
     *
     * A key that cannot be read is left out, and Meilisearch keeps the one it has.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function withApiKeys(array $settings): array
    {
        return self::mapEmbedders($settings, static function (array $config, string $name): array {
            if (! array_key_exists(self::API_KEY, $config)) {
                return $config;
            }

            $apiKey = self::readApiKey($config[self::API_KEY], $name);
            unset($config[self::API_KEY]);

            if ($apiKey === null) {
                error_log("MeiliScout: the API key of the embedder \"{$name}\" could not be read; Meilisearch keeps the one it has.");

                return $config;
            }

            return [...$config, self::API_KEY => $apiKey];
        });
    }

    /**
     * The settings without the embedders' API keys: what their fingerprint is made of.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function withoutApiKeys(array $settings): array
    {
        return self::mapEmbedders($settings, static fn (array $config): array => array_diff_key($config, [self::API_KEY => true]));
    }

    /**
     * An embedder as the export and the status show it: no setting that may carry a secret.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function describe(array $config): array
    {
        return array_diff_key($config, array_flip(self::SECRET_SETTINGS));
    }

    /**
     * The model an embedder uses, wherever its settings put it.
     *
     * @param  array<string, mixed>  $config
     */
    public static function model(array $config): string
    {
        $model = $config['model'] ?? $config['request']['model'] ?? '';

        return is_string($model) ? $model : '';
    }

    /**
     * Forgets the embedders declared in code. For tests.
     */
    public static function reset(): void
    {
        self::$declared = [];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  callable(array<string, mixed>, string): array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private static function mapEmbedders(array $settings, callable $map): array
    {
        if (! is_array($settings['embedders'] ?? null)) {
            return $settings;
        }

        foreach ($settings['embedders'] as $name => $config) {
            if (is_array($config)) {
                $settings['embedders'][$name] = $map($config, (string) $name);
            }
        }

        return $settings;
    }

    /**
     * An API key from a callable, a constant or an environment variable; null when it cannot be read.
     */
    private static function readApiKey(mixed $source, string $name): ?string
    {
        try {
            $apiKey = match (true) {
                $source instanceof \Closure, is_array($source) && is_callable($source) => $source(),
                is_string($source) && defined($source) => constant($source),
                is_string($source) => self::environment($source),
                default => null,
            };
        } catch (\Throwable $e) {
            error_log("MeiliScout: reading the API key of the embedder \"{$name}\" failed: ".$e->getMessage());

            return null;
        }

        return is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
    }

    /**
     * An environment variable, wherever the site's loader put it.
     */
    private static function environment(string $name): ?string
    {
        $value = getenv($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
