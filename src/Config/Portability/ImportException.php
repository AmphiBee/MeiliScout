<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config\Portability;

/**
 * A file that cannot be an export: not JSON, too large, not ours, from a newer format.
 */
final class ImportException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }
}
