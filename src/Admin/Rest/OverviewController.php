<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Admin\Rest;

use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Pollora\MeiliScout\Config\Config;
use Pollora\MeiliScout\Config\RealtimeIndexing;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\Indexable;
use Pollora\MeiliScout\Documents\ItemSync;
use Pollora\MeiliScout\Embedders\Embedders;
use Pollora\MeiliScout\Services\ActivityLog;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\Indexer;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Query\AutoIntegration;
use Pollora\MeiliScout\Services\SearchFallbacks;
use WP_REST_Response;

/**
 * The state of the integration at a glance.
 */
final class OverviewController extends Controller
{
    private const RECENT_ACTIVITY = 5;

    public function registerRoutes(): void
    {
        $this->route('/overview', 'GET', [$this, 'show']);
    }

    public function show(): WP_REST_Response
    {
        $client = ClientFactory::isConfigured() ? ClientFactory::getClient() : null;
        $structure = (new Indexer)->checkStructureChanges();

        return $this->respond([
            'connection' => [
                'configured' => ClientFactory::isConfigured(),
                'reachable' => $client !== null,
                'host' => (string) Config::get('meili_host', ''),
                'version' => $client !== null ? $this->version($client) : null,
            ],
            'migration' => [
                'pending' => IndexNames::migrationPending(),
                'active' => array_map([IndexNames::class, 'active'], IndexNames::BASES),
                'target' => array_map([IndexNames::class, 'name'], IndexNames::BASES),
                'legacy' => IndexNames::legacyIndexes(),
            ],
            'indexes' => $client !== null ? $this->indexes($client) : [],
            'realtime' => ['mode' => RealtimeIndexing::mode(), 'locked' => RealtimeIndexing::isLocked()],
            'selection' => [
                'post_types' => count((array) Settings::get('indexed_post_types', [])),
                'taxonomies' => count((array) Settings::get('indexed_taxonomies', [])),
                'meta_keys' => count((array) Settings::get('indexed_meta_keys', [])),
            ],
            'needs_indexation' => $structure['has_changed'] || $structure['last_indexed'] === null,
            'fallbacks' => SearchFallbacks::lastDay(),
            // Offered once a full indexation succeeded
            'integration' => [
                'search' => AutoIntegration::settings()['search'],
                'indexed' => $structure['last_indexed'] !== null,
            ],
            'run' => IndexationController::currentRun(),
            'activity' => array_map(
                [IndexationController::class, 'present'],
                array_slice(ActivityLog::all(), 0, self::RECENT_ACTIVITY)
            ),
        ]);
    }

    private function version(Client $client): ?string
    {
        try {
            return $client->version()['pkgVersion'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The indexes searches read, other plugins' included, with their size, freshness and vectors.
     *
     * @return list<array<string, mixed>>
     */
    private function indexes(Client $client): array
    {
        $indexes = [];

        foreach ($this->bases() as $base) {
            $name = IndexNames::active($base);
            $index = ['base' => $base, 'name' => $name, 'exists' => false];

            try {
                $endpoint = $client->getIndex($name);
                $stats = $endpoint->stats();

                $index = [
                    ...$index,
                    'exists' => true,
                    'documents' => (int) ($stats['numberOfDocuments'] ?? 0),
                    // Meilisearch 1.13+
                    'size' => isset($stats['rawDocumentDbSize']) ? (int) $stats['rawDocumentDbSize'] : null,
                    'is_indexing' => (bool) ($stats['isIndexing'] ?? false),
                    'updated_at' => $this->isoDate($endpoint->getUpdatedAt()),
                    'vectors' => self::vectors($base, $stats),
                ];
            } catch (ApiException $e) {
                if ($e->httpStatus !== 404) {
                    $index['error'] = $e->getMessage();
                }
            } catch (\Throwable $e) {
                $index['error'] = $e->getMessage();
            }

            $indexes[] = $index;
        }

        return $indexes;
    }

    /**
     * Posts and terms, then the indexes of other plugins' indexables.
     *
     * @return list<string>
     */
    private function bases(): array
    {
        $others = array_map(
            static fn (Indexable $indexable): string => IndexNames::baseOf($indexable->getIndexName()),
            ItemSync::indexables()
        );

        return array_values(array_unique([...IndexNames::BASES, ...$others]));
    }

    /**
     * The documents of an index that have their vectors, and its embedders; null without embedders.
     *
     * @param  array<string, mixed>  $stats
     * @return array{embedded: int, embedders: list<string>}|null
     */
    private static function vectors(string $base, array $stats): ?array
    {
        $embedders = Embedders::forIndex($base);

        if ($embedders === []) {
            return null;
        }

        return [
            'embedded' => (int) ($stats['numberOfEmbeddedDocuments'] ?? 0),
            'embedders' => array_map('strval', array_keys($embedders)),
        ];
    }
}
