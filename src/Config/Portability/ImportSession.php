<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config\Portability;

use function delete_transient;
use function get_current_user_id;
use function get_transient;
use function set_transient;
use function wp_generate_password;

/**
 * A previewed file, kept a few minutes for the user who previewed it, so that
 * what is applied is the file that was shown (the browser sends back a token,
 * not the file).
 */
final class ImportSession
{
    private const LIFETIME = 900;

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $options
     */
    public static function store(array $document, array $options): string
    {
        $token = wp_generate_password(24, false);
        set_transient(self::name($token), ['document' => $document, 'options' => $options], self::LIFETIME);

        return $token;
    }

    /**
     * @return array{document: array<string, mixed>, options: array<string, mixed>}|null
     */
    public static function load(string $token): ?array
    {
        $saved = get_transient(self::name($token));

        return is_array($saved) && isset($saved['document'], $saved['options']) ? $saved : null;
    }

    public static function forget(string $token): void
    {
        delete_transient(self::name($token));
    }

    private static function name(string $token): string
    {
        return 'meiliscout_import_'.get_current_user_id().'_'.preg_replace('/[^A-Za-z0-9]/', '', $token);
    }
}
