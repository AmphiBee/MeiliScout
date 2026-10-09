<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Transport;

use Pollora\MeiliScout\Config\Config;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Query\FacetPlan;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * The tenant token a listing's client searches Meilisearch with: its base
 * filter (post types, published, no password, the listing's own base) is
 * added to every search, which the browser cannot widen.
 *
 * One per listing, the same for every visitor: it expires on the hour, a day
 * later (meiliscout/listings/token_lifetime), so pages cached within the same
 * hour carry the same token. The client asks for a new one when it expires.
 */
final class TenantTokens
{
    /**
     * @return array{value: string, exp: int}|null Null when no key can sign it
     */
    public static function forListing(ListingDefinition $definition): ?array
    {
        $key = ListingsKey::get();

        if ($key === null) {
            return null;
        }

        /**
         * Filters how long a listing's tenant token lasts, in seconds (at least an hour).
         *
         * @param  int  $lifetime  Default a day.
         * @param  ListingDefinition  $definition  The listing.
         */
        $lifetime = max(HOUR_IN_SECONDS, (int) apply_filters('meiliscout/listings/token_lifetime', DAY_IN_SECONDS, $definition));
        $exp = (int) (floor(time() / HOUR_IN_SECONDS) * HOUR_IN_SECONDS) + $lifetime;
        $rules = [IndexNames::active('posts') => ['filter' => FacetPlan::baseFilter($definition)]];

        try {
            $value = ClientFactory::build((string) Config::get('meili_host'), $key['key'])
                ->generateTenantToken($key['uid'], $rules, ['apiKey' => $key['key'], 'expiresAt' => new \DateTimeImmutable('@'.$exp)]);
        } catch (\Throwable $e) {
            error_log('MeiliScout: could not sign a listing token: '.$e->getMessage());

            return null;
        }

        return ['value' => $value, 'exp' => $exp];
    }

    /**
     * Where browsers reach Meilisearch: the public host (Settings › Listings),
     * else the one the server uses.
     */
    public static function publicHost(): string
    {
        $host = (string) Settings::get('listings_public_host', '');

        return untrailingslashit($host !== '' ? $host : (string) Config::get('meili_host'));
    }
}
