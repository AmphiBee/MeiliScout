<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Transport;

use Pollora\MeiliScout\Config\Config;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\Transport\TenantTokens;
use Pollora\MeiliScout\Listings\Query\ArrayQuery;
use Pollora\MeiliScout\Query\MeiliQueryBuilder;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Suggest\Definition\SuggestDefinition;

/**
 * The tenant token a suggest field searches Meilisearch with. Its rules add a
 * filter to every search, which the browser cannot widen:
 *
 * - posts: the post types of the field's sources, published, without a
 *   password (and in the request's language when a language plugin runs);
 * - terms: the taxonomies of the field's sources.
 *
 * The same for every visitor of a language: it expires on the hour, a day
 * later (meiliscout/suggest/token_lifetime), so pages cached within the same
 * hour carry the same token. The client asks for a new one when it expires.
 */
final class SuggestTokens
{
    /**
     * @return array{value: string, exp: int, host: string}|null Null when no key can sign it
     */
    public static function forDefinition(SuggestDefinition $definition): ?array
    {
        $key = SuggestKey::get();

        if ($key === null) {
            return null;
        }

        /**
         * Filters how long a suggest field's tenant token lasts, in seconds (at least an hour).
         *
         * @param  int  $lifetime  Default a day.
         * @param  SuggestDefinition  $definition  The field.
         */
        $lifetime = max(HOUR_IN_SECONDS, (int) apply_filters('meiliscout/suggest/token_lifetime', DAY_IN_SECONDS, $definition));
        $exp = (int) (floor(time() / HOUR_IN_SECONDS) * HOUR_IN_SECONDS) + $lifetime;

        try {
            $value = ClientFactory::build((string) Config::get('meili_host'), $key['key'])
                ->generateTenantToken($key['uid'], self::rules($definition), ['apiKey' => $key['key'], 'expiresAt' => new \DateTimeImmutable('@'.$exp)]);
        } catch (\Throwable $e) {
            error_log('MeiliScout: could not sign a suggest token: '.$e->getMessage());

            return null;
        }

        return ['value' => $value, 'exp' => $exp, 'host' => TenantTokens::publicHost()];
    }

    /**
     * The token's search rules by index: only for the indexes the field reads.
     *
     * @return array<string, array{filter: string}>
     */
    public static function rules(SuggestDefinition $definition): array
    {
        $rules = [];

        if ($definition->postTypes() !== []) {
            $rules[IndexNames::active('posts')] = ['filter' => self::postsFilter($definition)];
        }

        if ($definition->taxonomies() !== []) {
            $rules[IndexNames::active('taxonomies')] = ['filter' => self::termsFilter($definition)];
        }

        return $rules;
    }

    /**
     * What may be read in the posts index, as the builders write it.
     */
    public static function postsFilter(SuggestDefinition $definition): string
    {
        $args = Languages::adapter()->baseArgs([
            'post_type' => $definition->postTypes(),
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => 1,
        ]);

        return (string) ((new MeiliQueryBuilder)->build(new ArrayQuery($args))['filter'] ?? '');
    }

    /**
     * What may be read in the taxonomies index.
     */
    public static function termsFilter(SuggestDefinition $definition): string
    {
        $values = implode(', ', array_map(static fn (string $taxonomy): string => (string) json_encode($taxonomy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $definition->taxonomies()));

        return "taxonomy IN [{$values}]";
    }
}
