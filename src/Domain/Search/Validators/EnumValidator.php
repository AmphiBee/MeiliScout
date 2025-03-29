<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Domain\Search\Validators;

use BackedEnum;

class EnumValidator
{
    /**
     * Vérifie si une valeur existe dans une énumération
     *
     * @template T of BackedEnum
     * @param class-string<T> $enumClass
     * @param string|int $value
     * @return bool
     */
    public static function isValid(string $enumClass, string|int $value): bool
    {
        if (!is_subclass_of($enumClass, BackedEnum::class)) {
            throw new \InvalidArgumentException(sprintf(
                'La classe %s doit être une énumération BackedEnum',
                $enumClass
            ));
        }

        return !is_null($enumClass::tryFrom($value));
    }

    /**
     * Obtient une valeur valide de l'énumération ou une valeur par défaut
     *
     * @template T of BackedEnum
     * @param class-string<T> $enumClass
     * @param string|int $value
     * @param T $default
     * @return T
     */
    public static function getValidValueOrDefault(string $enumClass, string|int $value, BackedEnum $default): BackedEnum
    {
        if (!is_subclass_of($enumClass, BackedEnum::class)) {
            throw new \InvalidArgumentException(sprintf(
                'La classe %s doit être une énumération BackedEnum',
                $enumClass
            ));
        }

        return $enumClass::tryFrom($value) ?? $default;
    }
} 