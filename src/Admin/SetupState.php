<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Admin;

use Pollora\MeiliScout\Config\Settings;

/**
 * Where the setup assistant stands, and what the site offers it.
 *
 * The assistant opens by itself while nothing is connected, until it is
 * finished or dismissed; it can always be opened again from the admin.
 */
final class SetupState
{
    public const SETTING = 'setup_state';

    public const COMPLETED = 'completed';

    public const DISMISSED = 'dismissed';

    public const MIN_PHP = '8.2';

    public const MIN_WORDPRESS = '6.6';

    public const LISTINGS_WORDPRESS = '6.9';

    /**
     * @return 'completed'|'dismissed'|null
     */
    public static function get(): ?string
    {
        $state = Settings::get(self::SETTING);

        return in_array($state, [self::COMPLETED, self::DISMISSED], true) ? $state : null;
    }

    /**
     * @param  string|null  $state  'completed', 'dismissed', or null to open the assistant by itself again
     */
    public static function set(?string $state): void
    {
        Settings::save(self::SETTING, in_array($state, [self::COMPLETED, self::DISMISSED], true) ? $state : '');
    }

    /**
     * What the assistant checks on its first screen.
     *
     * @return array{php: array{version: string, ok: bool}, wordpress: array{version: string, ok: bool}, listings: bool}
     */
    public static function checks(?string $php = null, ?string $wordpress = null): array
    {
        $php ??= PHP_VERSION;
        $wordpress ??= (string) ($GLOBALS['wp_version'] ?? '0');

        return [
            'php' => ['version' => $php, 'ok' => version_compare($php, self::MIN_PHP, '>=')],
            'wordpress' => ['version' => $wordpress, 'ok' => version_compare($wordpress, self::MIN_WORDPRESS, '>=')],
            'listings' => version_compare($wordpress, self::LISTINGS_WORDPRESS, '>='),
        ];
    }
}
