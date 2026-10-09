<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Admin;

use Pollora\MeiliScout\Config\Config;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\ListingsServiceProvider;
use Pollora\MeiliScout\Listings\PublicFields;
use Pollora\MeiliScout\Listings\Query\ArrayQuery;
use Pollora\MeiliScout\Listings\Transport\ListingsKey;
use Pollora\MeiliScout\Listings\Transport\TenantTokens;
use Pollora\MeiliScout\Query\Builders\FieldsBuilder;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\IndexSettings;

/**
 * Settings › Listings: the module's switch, the host browsers reach
 * Meilisearch at, the key their tokens are signed with, and what the admin
 * should know (the declared listings and their errors, the fields browsers
 * read). Available when the module is off, to turn it on.
 */
final class ListingsSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $unavailable = ListingsServiceProvider::unavailable();
        $key = ListingsKey::saved();
        $listings = [];

        foreach (DefinitionRegistry::errors() as $id => $errors) {
            $listings[] = ['id' => $id, 'errors' => $errors];
        }

        return [
            'enabled' => (bool) Settings::get('listings_enabled', false),
            // Off is a choice; an old WordPress is not
            'unavailable' => $unavailable === 'wordpress_version' ? sprintf(
                /* translators: %s: a WordPress version */
                __('Listings need WordPress %s or later.', 'meiliscout'),
                ListingsServiceProvider::MIN_WORDPRESS
            ) : null,
            'public_host' => (string) Settings::get('listings_public_host', ''),
            'host' => (string) Config::get('meili_host', ''),
            'effective_host' => TenantTokens::publicHost(),
            'key' => $key === null ? null : [
                'uid' => $key['uid'],
                // A key made for an index searches no longer read is made again on first use
                'current' => $key['index'] === IndexNames::active('posts'),
            ],
            'listings' => $listings,
            'fields' => PublicFields::allowlist(),
            'displayed' => IndexSettings::displayed(IndexNames::active('posts')),
            // Asked for, and ignored while the index returns only some fields
            'hydration_ignored' => $unavailable === null && self::hydrationAsked() && ! FieldsBuilder::canHydrate(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input  enabled, public_host
     *
     * @throws \InvalidArgumentException When the public host is not a URL
     */
    public static function save(array $input): void
    {
        if (isset($input['enabled']) && is_bool($input['enabled'])) {
            Settings::save('listings_enabled', $input['enabled']);
        }

        if (isset($input['public_host']) && is_string($input['public_host'])) {
            $host = untrailingslashit(trim(esc_url_raw($input['public_host'])));

            if ($host !== '' && filter_var($host, FILTER_VALIDATE_URL) === false) {
                throw new \InvalidArgumentException(__('The public URL of Meilisearch is not a valid URL.', 'meiliscout'));
            }

            Settings::save('listings_public_host', $host);
        }
    }

    /**
     * A new key: every token signed with the previous one is refused at once.
     *
     * @return bool Whether a key could be made
     */
    public static function rotate(): bool
    {
        return ListingsKey::rotate() !== null;
    }

    private static function hydrationAsked(): bool
    {
        return (bool) apply_filters('meiliscout/hydrate_from_documents', false, new ArrayQuery([]));
    }
}
