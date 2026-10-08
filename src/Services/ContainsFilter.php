<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Pollora\MeiliScout\Config\Config;
use Pollora\MeiliScout\Config\Settings;

use function wp_remote_request;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;

/**
 * Meilisearch's CONTAINS filter, which meta_query LIKE and NOT LIKE are translated to.
 *
 * CONTAINS is an experimental feature of Meilisearch (containsFilter), off by
 * default: it is turned on from Settings > Advanced, where the instance's own
 * state is shown, since it can be changed outside the plugin. Without it, a
 * LIKE runs on MySQL.
 */
final class ContainsFilter
{
    public const SETTING = 'contains_filter';

    private const FEATURE = 'containsFilter';

    /**
     * Whether LIKE is translated to CONTAINS: the admin turned the feature on.
     */
    public static function enabled(): bool
    {
        return (bool) Settings::get(self::SETTING, false);
    }

    /**
     * The instance's state: whether it has the feature, and whether it is on.
     *
     * @return array{available: bool, enabled: bool, error: string|null}
     */
    public static function state(): array
    {
        $features = self::request('GET');

        if (! is_array($features)) {
            return ['available' => false, 'enabled' => false, 'error' => is_string($features) ? $features : null];
        }

        $enabled = ! empty($features[self::FEATURE]);

        // Turned on or off outside the plugin: queries follow the instance
        if ($enabled !== self::enabled()) {
            Settings::save(self::SETTING, $enabled);
        }

        return [
            'available' => array_key_exists(self::FEATURE, $features),
            'enabled' => $enabled,
            'error' => null,
        ];
    }

    /**
     * Turns the feature on or off on the instance, and remembers it.
     *
     * @return array{available: bool, enabled: bool, error: string|null}
     */
    public static function set(bool $enabled): array
    {
        $result = self::request('PATCH', [self::FEATURE => $enabled]);

        if (is_array($result)) {
            Settings::save(self::SETTING, ! empty($result[self::FEATURE]));
        }

        return self::state();
    }

    /**
     * @param  array<string, bool>|null  $body
     * @return array<string, mixed>|string|null The features, or an error message
     */
    private static function request(string $method, ?array $body = null): array|string|null
    {
        $host = (string) Config::get('meili_host', '');
        $key = (string) Config::get('meili_key', '');

        if ($host === '' || $key === '') {
            return null;
        }

        $response = wp_remote_request(rtrim($host, '/').'/experimental-features', [
            'method' => $method,
            'timeout' => ClientFactory::timeout(),
            'headers' => ['Authorization' => 'Bearer '.$key, 'Content-Type' => 'application/json'],
            'body' => $body === null ? null : (string) wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (wp_remote_retrieve_response_code($response) !== 200 || ! is_array($data)) {
            return is_array($data) && isset($data['message']) ? (string) $data['message'] : null;
        }

        return $data;
    }
}
