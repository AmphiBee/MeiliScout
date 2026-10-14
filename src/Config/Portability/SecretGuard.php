<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config\Portability;

/**
 * Refuses to let a secret into a file that is shared, committed or e-mailed.
 *
 * The sections write an allow-list, so this is the net under it: a setting
 * added later whose name says it is a key, a token or a password stops the
 * export instead of leaking.
 */
final class SecretGuard
{
    /**
     * A name that is one of these words, or holds one between underscores or dashes.
     */
    private const PATTERN = '/(^|[_\-.])(key|token|secret|password|passwd|pass|salt|credentials?)($|[_\-.])/i';

    /**
     * @param  array<mixed>  $document
     *
     * @throws \LogicException
     */
    public static function assertClean(array $document): void
    {
        foreach (self::offending($document) as $path) {
            throw new \LogicException("The export would carry what looks like a secret: {$path}");
        }
    }

    /**
     * @param  array<mixed>  $value
     * @return list<string>  The paths of the names that look like secrets
     */
    public static function offending(array $value, string $path = ''): array
    {
        $found = [];

        foreach ($value as $name => $item) {
            $here = $path === '' ? (string) $name : "{$path}.{$name}";

            if (is_string($name) && preg_match(self::PATTERN, $name) === 1) {
                $found[] = $here;
            }

            if (is_array($item)) {
                $found = [...$found, ...self::offending($item, $here)];
            }
        }

        return $found;
    }
}
