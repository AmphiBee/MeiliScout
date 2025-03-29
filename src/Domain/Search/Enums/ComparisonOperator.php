<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Domain\Search\Enums;

enum ComparisonOperator: string
{
    case EQUALS = '=';
    case NOT_EQUALS = '!=';
    case GREATER_THAN = '>';
    case GREATER_THAN_OR_EQUALS = '>=';
    case LESS_THAN = '<';
    case LESS_THAN_OR_EQUALS = '<=';
    case IN = 'IN';
    case NOT_IN = 'NOT IN';
    case BETWEEN = 'BETWEEN';
    case NOT_BETWEEN = 'NOT BETWEEN';
    case EXISTS = 'EXISTS';
    case NOT_EXISTS = 'NOT EXISTS';
    case LIKE = 'LIKE';
    case NOT_LIKE = 'NOT LIKE';
    case REGEXP = 'REGEXP';
    case NOT_REGEXP = 'NOT REGEXP';
    case RLIKE = 'RLIKE';
    case AND = 'AND';

    public static function getMetaOperators(): array
    {
        return [
            self::EQUALS,
            self::NOT_EQUALS,
            self::GREATER_THAN,
            self::GREATER_THAN_OR_EQUALS,
            self::LESS_THAN,
            self::LESS_THAN_OR_EQUALS,
            self::LIKE,
            self::NOT_LIKE,
            self::IN,
            self::NOT_IN,
            self::BETWEEN,
            self::NOT_BETWEEN,
            self::EXISTS,
            self::NOT_EXISTS,
            self::REGEXP,
            self::NOT_REGEXP,
            self::RLIKE,
        ];
    }

    public static function getTaxonomyOperators(): array
    {
        return [
            self::IN,
            self::NOT_IN,
            self::AND,
            self::EXISTS,
            self::NOT_EXISTS,
        ];
    }

    public static function getDateOperators(): array
    {
        return [
            self::EQUALS,
            self::NOT_EQUALS,
            self::GREATER_THAN,
            self::GREATER_THAN_OR_EQUALS,
            self::LESS_THAN,
            self::LESS_THAN_OR_EQUALS,
            self::IN,
            self::NOT_IN,
            self::BETWEEN,
            self::NOT_BETWEEN,
        ];
    }

    public static function getDefault(): self
    {
        return self::EQUALS;
    }

    public static function getTaxonomyDefault(): self
    {
        return self::IN;
    }
} 