<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Transport;

use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Suggest\Definition\InvalidSuggest;
use Pollora\MeiliScout\Suggest\Definition\SuggestDefinition;
use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;

/**
 * The suggest fields' public endpoint, under meiliscout/v1/suggest/{id}:
 *
 * - token: a fresh tenant token, when the one in the page expired. GET,
 *   cacheable for five minutes.
 */
final class RestController
{
    private const NAMESPACE = 'meiliscout/v1';

    public static function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/suggest/(?P<id>[a-z0-9][a-z0-9_-]*)/token', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'token'],
        ]);
    }

    public static function token(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return Languages::adapter()->in((string) $request->get_param('lang'), fn () => self::tokenIn($request));
    }

    private static function tokenIn(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $definition = self::definition((string) $request['id']);

        if ($definition instanceof \WP_Error) {
            return $definition;
        }

        $token = SuggestTokens::forDefinition($definition);

        if ($token === null) {
            return new \WP_Error('meiliscout_no_token', 'No token can be signed.', ['status' => 503]);
        }

        $response = new \WP_REST_Response($token);
        $response->header('Cache-Control', 'public, max-age=300');

        return $response;
    }

    private static function definition(string $id): SuggestDefinition|\WP_Error
    {
        try {
            return SuggestRegistry::get($id);
        } catch (InvalidSuggest|\OutOfBoundsException) {
            return new \WP_Error('meiliscout_unknown_suggest', 'Unknown suggest.', ['status' => 404]);
        }
    }
}
