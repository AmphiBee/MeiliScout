<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Transport;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * The Meilisearch key the suggest fields' tenant tokens are signed with:
 * search only, on the posts and taxonomies indexes only. Its own key, apart
 * from the site's search key and from the front listings' key (a leak or a
 * rotation of one never touches the others).
 *
 * Rotating it creates a key with another uid: Meilisearch derives a key from
 * its uid, so one created again with the same uid would bring the old tokens
 * back to life.
 */
final class SuggestKey
{
    private const SETTING = 'suggest_key';

    /**
     * After a failed creation, how long before trying again.
     */
    private const RETRY = 5 * MINUTE_IN_SECONDS;

    /**
     * The indexes the key may search: the ones searches read now.
     *
     * @return list<string>
     */
    public static function indexes(): array
    {
        return [IndexNames::active('posts'), IndexNames::active('taxonomies')];
    }

    /**
     * @return array{uid: string, key: string}|null
     */
    public static function get(): ?array
    {
        $saved = Settings::get(self::SETTING);
        $indexes = self::indexes();

        if (is_array($saved) && ($saved['indexes'] ?? null) === $indexes && isset($saved['uid'], $saved['key'])) {
            return ['uid' => (string) $saved['uid'], 'key' => (string) $saved['key']];
        }

        if (get_transient('meiliscout_suggest_key_failed')) {
            return null;
        }

        // The indexes changed (a migration activated new ones): a key for them replaces the old one
        return self::create($indexes, is_array($saved) ? ($saved['uid'] ?? null) : null);
    }

    /**
     * The key saved, without making one: its uid and the indexes it searches.
     *
     * @return array{uid: string, indexes: list<string>}|null
     */
    public static function saved(): ?array
    {
        $saved = Settings::get(self::SETTING);

        return is_array($saved) && isset($saved['uid'], $saved['indexes'])
            ? ['uid' => (string) $saved['uid'], 'indexes' => array_values(array_map('strval', (array) $saved['indexes']))]
            : null;
    }

    /**
     * Replaces the key: every token signed with the previous one is refused.
     *
     * @return array{uid: string, key: string}|null
     */
    public static function rotate(): ?array
    {
        $saved = Settings::get(self::SETTING);
        delete_transient('meiliscout_suggest_key_failed');

        return self::create(self::indexes(), is_array($saved) ? ($saved['uid'] ?? null) : null);
    }

    /**
     * @param  list<string>  $indexes
     * @return array{uid: string, key: string}|null
     */
    private static function create(array $indexes, ?string $previous): ?array
    {
        $admin = ClientFactory::getClient();

        if ($admin === null) {
            return null;
        }

        try {
            $uid = wp_generate_uuid4();
            $admin->createKey([
                'uid' => $uid,
                'name' => 'MeiliScout suggest',
                'description' => 'Signs the tenant tokens of the search fields with suggestions (search on the posts and taxonomies indexes).',
                'actions' => ['search'],
                'indexes' => $indexes,
                'expiresAt' => null,
            ]);
            $key = $admin->getKey($uid)->getKey();
        } catch (\Throwable $e) {
            error_log('MeiliScout: could not create the suggest key: '.$e->getMessage());
            set_transient('meiliscout_suggest_key_failed', 1, self::RETRY);

            return null;
        }

        if ($previous !== null && $previous !== $uid) {
            try {
                $admin->deleteKey($previous);
            } catch (\Throwable) {
                // Already gone
            }
        }

        update_option('meiliscout/'.self::SETTING, ['uid' => $uid, 'key' => $key, 'indexes' => $indexes], false);

        return ['uid' => $uid, 'key' => (string) $key];
    }
}
