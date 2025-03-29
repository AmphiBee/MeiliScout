<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Domain\Search\Enums;

enum MetaType: string
{
    case NUMERIC = 'NUMERIC';
    case BINARY = 'BINARY';
    case CHAR = 'CHAR';
    case DATE = 'DATE';
    case DATETIME = 'DATETIME';
    case DECIMAL = 'DECIMAL';
    case SIGNED = 'SIGNED';
    case TIME = 'TIME';
    case UNSIGNED = 'UNSIGNED';

    public static function getDefault(): self
    {
        return self::CHAR;
    }
} 