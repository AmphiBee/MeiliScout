<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config\Portability;

/**
 * Reads the values of a file: what has the wrong type is reported, never guessed.
 */
final class Normalize
{
    /**
     * @return list<string>|null  Null when it is not a list of non-empty strings
     */
    public static function strings(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                return null;
            }
            $strings[] = trim($item);
        }

        return array_values(array_unique($strings));
    }

    /**
     * @return int|null  Null when it is not a positive whole number
     */
    public static function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @param  array<string, bool>  $known  The switches there can be, with their defaults
     * @return array{values: array<string, bool>, unknown: list<string>}|null  Null when it is not a map of booleans
     */
    public static function switches(mixed $value, array $known): ?array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }

        $values = $known;
        $unknown = [];

        foreach ($value as $name => $on) {
            if (! is_bool($on)) {
                return null;
            }

            if (array_key_exists((string) $name, $known)) {
                $values[(string) $name] = $on;
            } else {
                $unknown[] = (string) $name;
            }
        }

        return ['values' => $values, 'unknown' => $unknown];
    }
}
