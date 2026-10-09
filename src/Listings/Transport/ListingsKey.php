<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Transport;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * The Meilisearch key the listings' tenant tokens are signed with: search
 * only, on the posts index only. Created with the admin key the first time a
 * listing needs it, kept apart from the site's search key.
 *
 * Rotating it creates a key with another uid: Meilisearch derives a key from
 * its uid, so one created again with the same uid would bring the old tokens
 * back to life.
 */
final class ListingsKey
{
    private const SETTING = 'listings_key';

    /**
     * After a failed creation, how long before trying again.
     */
    private const RETRY = 5 * MINUTE_IN_SECONDS;

    /**
     * @return array{uid: string, key: string}|null
     */
    public static function get(): ?array
    {
        $saved = Settings::get(self::SETTING);
        $index = IndexNames::active('posts');

        if (is_array($saved) && ($saved['index'] ?? null) === $index && isset($saved['uid'], $saved['key'])) {
            return ['uid' => (string) $saved['uid'], 'key' => (string) $saved['key']];
        }

        if (get_transient('meiliscout_listings_key_failed')) {
            return null;
        }

        return self::create($index, is_array($saved) ? ($saved['uid'] ?? null) : null);
    }

    /**
     * Replaces the key: every token signed with the previous one is refused.
     *
     * @return array{uid: string, key: string}|null
     */
    public static function rotate(): ?array
    {
        $saved = Settings::get(self::SETTING);
        delete_transient('meiliscout_listings_key_failed');

        return self::create(IndexNames::active('posts'), is_array($saved) ? ($saved['uid'] ?? null) : null);
    }

    /**
     * @return array{uid: string, key: string}|null
     */
    private static function create(string $index, ?string $previous): ?array
    {
        $admin = ClientFactory::getClient();

        if ($admin === null) {
            return null;
        }

        try {
            $uid = wp_generate_uuid4();
            $admin->createKey([
                'uid' => $uid,
                'name' => 'MeiliScout listings',
                'description' => 'Signs the tenant tokens of the front listings (search on the posts index).',
                'actions' => ['search'],
                'indexes' => [$index],
                'expiresAt' => null,
            ]);
            $key = $admin->getKey($uid)->getKey();
        } catch (\Throwable $e) {
            error_log('MeiliScout: could not create the listings key: '.$e->getMessage());
            set_transient('meiliscout_listings_key_failed', 1, self::RETRY);

            return null;
        }

        if ($previous !== null && $previous !== $uid) {
            try {
                $admin->deleteKey($previous);
            } catch (\Throwable) {
                // Already gone
            }
        }

        update_option('meiliscout/'.self::SETTING, ['uid' => $uid, 'key' => $key, 'index' => $index], false);

        return ['uid' => $uid, 'key' => (string) $key];
    }
}
