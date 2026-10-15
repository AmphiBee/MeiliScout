<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Definition;

/**
 * The suggest fields a site declares, by id.
 *
 * Declared at any time (functions.php, a plugin, a block); validated on first
 * use, once the post types and taxonomies are registered.
 */
final class SuggestRegistry
{
    /** @var array<string, array<string, mixed>> */
    private static array $declared = [];

    /** @var array<string, SuggestDefinition|InvalidSuggest> */
    private static array $built = [];

    /**
     * @param  array<string, mixed>  $args
     */
    public static function declare(string $id, array $args): void
    {
        self::$declared[$id] = $args;
        unset(self::$built[$id]);
    }

    /**
     * Forgets a suggest (tests).
     */
    public static function remove(string $id): void
    {
        unset(self::$declared[$id], self::$built[$id]);
    }

    public static function has(string $id): bool
    {
        return isset(self::$declared[$id]);
    }

    /**
     * @throws InvalidSuggest When the definition cannot be served
     * @throws \OutOfBoundsException When no suggest has this id
     */
    public static function get(string $id): SuggestDefinition
    {
        if (! isset(self::$declared[$id])) {
            throw new \OutOfBoundsException(sprintf('MeiliScout: no suggest "%s" is declared.', $id));
        }

        if (! isset(self::$built[$id])) {
            try {
                self::$built[$id] = SuggestDefinition::fromArray($id, self::$declared[$id]);
            } catch (InvalidSuggest $e) {
                self::$built[$id] = $e;
            }
        }

        if (self::$built[$id] instanceof InvalidSuggest) {
            throw self::$built[$id];
        }

        return self::$built[$id];
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::$declared);
    }

    /**
     * Every declared suggest's errors, empty for a valid one.
     *
     * @return array<string, list<string>>
     */
    public static function errors(): array
    {
        $errors = [];

        foreach (self::ids() as $id) {
            try {
                self::get($id);
                $errors[$id] = [];
            } catch (InvalidSuggest $e) {
                $errors[$id] = $e->errors;
            }
        }

        return $errors;
    }

    /**
     * Forgets every suggest (tests).
     */
    public static function reset(): void
    {
        self::$declared = [];
        self::$built = [];
    }
}
