<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Domain\Search\Validators;

use BackedEnum;

class EnumValidator
{
    /**
     * Checks if a value exists in an enumeration
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enumClass
     */
    public static function isValid(string $enumClass, string|int $value): bool
    {
        if (! is_subclass_of($enumClass, BackedEnum::class)) {
            throw new \InvalidArgumentException(sprintf(
                'The class %s must be a BackedEnum enumeration',
                $enumClass
            ));
        }

        return ! is_null($enumClass::tryFrom($value));
    }

    /**
     * Gets a valid value from the enumeration or a default value
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enumClass
     * @param  T  $default
     * @return T
     */
    public static function getValidValueOrDefault(string $enumClass, string|int $value, BackedEnum $default): BackedEnum
    {
        if (! is_subclass_of($enumClass, BackedEnum::class)) {
            throw new \InvalidArgumentException(sprintf(
                'The class %s must be a BackedEnum enumeration',
                $enumClass
            ));
        }

        return $enumClass::tryFrom($value) ?? $default;
    }
}
