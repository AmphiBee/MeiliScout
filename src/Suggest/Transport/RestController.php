<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Transport;

use Meilisearch\Contracts\SearchQuery;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Suggest\Definition\InvalidSuggest;
use Pollora\MeiliScout\Suggest\Definition\SuggestDefinition;
use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;
use Pollora\MeiliScout\Suggest\Definition\SuggestSource;
use Pollora\MeiliScout\Suggest\Render\Plan;

/**
 * The suggest fields' public endpoint, under meiliscout/v1/suggest/{id}:
 *
 * - token: a fresh tenant token, when the one in the page expired. GET,
 *   cacheable for five minutes.
 * - search?q=: the field's searches run by WordPress, for a Meilisearch no
 *   browser can reach (`'transport' => 'rest'`). The same answers as the
 *   browser's own multi-search; GET, cacheable for a minute by query.
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

        register_rest_route(self::NAMESPACE, '/suggest/(?P<id>[a-z0-9][a-z0-9_-]*)/search', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'args' => ['q' => ['type' => 'string', 'required' => true]],
            'callback' => [self::class, 'search'],
        ]);
    }

    /**
     * Longest query asked for: a search box, not a document.
     */
    private const MAX_QUERY = 100;

    public static function search(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return Languages::adapter()->in((string) $request->get_param('lang'), fn () => self::searchIn($request));
    }

    private static function searchIn(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $definition = self::definition((string) $request['id']);

        if ($definition instanceof \WP_Error) {
            return $definition;
        }

        if ($definition->transport !== 'rest') {
            return new \WP_Error('meiliscout_suggest_direct', 'This field searches Meilisearch directly.', ['status' => 404]);
        }

        $query = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $request['q'])), 0, self::MAX_QUERY);
        $client = ClientFactory::getClient();

        if ($client === null) {
            return new \WP_Error('meiliscout_no_engine', 'Meilisearch is not reachable.', ['status' => 503]);
        }

        // What a token adds in the browser's way, added here: a source's own filter inside the field's
        $base = [
            SuggestSource::POSTS => SuggestTokens::postsFilter($definition),
            SuggestSource::TERMS => SuggestTokens::termsFilter($definition),
        ];
        $queries = [];

        foreach (Plan::forDefinition($definition)['sources'] as $source) {
            foreach (Plan::searches($source) as $search) {
                $built = (new SearchQuery)
                    ->setIndexUid((string) $search['indexUid'])
                    ->setQuery($query)
                    ->setFilter(['('.$base[(string) $source['type']].') AND ('.$search['filter'].')'])
                    ->setLimit((int) $search['limit'])
                    ->setAttributesToRetrieve($search['attributesToRetrieve'])
                    ->setAttributesToHighlight($search['attributesToHighlight'])
                    ->setHighlightPreTag(Plan::MARK_OPEN)
                    ->setHighlightPostTag(Plan::MARK_CLOSE);

                if (isset($search['attributesToSearchOn'])) {
                    $built->setAttributesToSearchOn($search['attributesToSearchOn']);
                }

                if (! empty($search['showRankingScore'])) {
                    $built->setShowRankingScore(true);
                }

                $queries[] = $built;
            }
        }

        try {
            $results = $client->multiSearch($queries)['results'] ?? [];
        } catch (\Throwable $e) {
            error_log('MeiliScout: a suggest search failed: '.$e->getMessage());

            return new \WP_Error('meiliscout_engine_error', 'The search failed.', ['status' => 502]);
        }

        $response = new \WP_REST_Response(['results' => $results]);
        $response->header('Cache-Control', 'public, max-age=60');

        return $response;
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
