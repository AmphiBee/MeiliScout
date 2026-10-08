<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Admin\Rest;

use Meilisearch\Contracts\IndexesQuery;
use Pollora\MeiliScout\Config\Config;
use Pollora\MeiliScout\Config\RealtimeIndexing;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Query\AutoIntegration;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\ContainsFilter;
use Pollora\MeiliScout\Services\Indexer;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\IndexSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Connection, index names, real-time indexing and tuning.
 *
 * API keys never leave the server: the app only learns whether one is set,
 * and an empty key field leaves the saved key as it is.
 */
final class SettingsController extends Controller
{
    private const HOST = 'meili_host';

    private const ADMIN_KEY = 'meili_key';

    private const SEARCH_KEY = 'meili_search_key';

    private const PREFIX = 'meili_index_prefix';

    public function registerRoutes(): void
    {
        $this->route('/settings', 'GET', [$this, 'show']);
        $this->route('/settings', 'POST', [$this, 'update']);
        $this->route('/settings/test', 'POST', [$this, 'test']);
    }

    public function show(): WP_REST_Response
    {
        return $this->respond($this->payload());
    }

    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $host = $request->get_param('host');
        if (is_string($host) && ! Config::isReadOnly(self::HOST)) {
            $host = trim(esc_url_raw($host));
            if ($host !== '' && filter_var($host, FILTER_VALIDATE_URL) === false) {
                return new WP_Error('meiliscout_invalid_host', __('The instance URL is not a valid URL.', 'meiliscout'), ['status' => 400]);
            }
            Settings::save(self::HOST, $host);
        }

        foreach (['admin_key' => self::ADMIN_KEY, 'search_key' => self::SEARCH_KEY] as $param => $setting) {
            $key = $request->get_param($param);
            // Empty: unchanged
            if (is_string($key) && trim($key) !== '' && ! Config::isReadOnly($setting)) {
                Settings::save($setting, sanitize_text_field($key));
            }
        }

        if ($request->get_param('clear_search_key') === true && ! Config::isReadOnly(self::SEARCH_KEY)) {
            Settings::save(self::SEARCH_KEY, '');
        }

        $prefix = $request->get_param('prefix');
        if (is_string($prefix) && ! Config::isReadOnly(self::PREFIX)) {
            Settings::save(self::PREFIX, sanitize_text_field($prefix));
        }

        $mode = $request->get_param('realtime');
        if (is_string($mode) && in_array($mode, RealtimeIndexing::MODES, true) && ! RealtimeIndexing::isLocked()) {
            RealtimeIndexing::save($mode);
        }

        foreach (['timeout' => 'http_timeout', 'batch_size' => 'bulk_batch_size'] as $param => $setting) {
            $value = $request->get_param($param);
            if (is_numeric($value) && (int) $value > 0) {
                Settings::save($setting, (int) $value);
            }
        }

        $maxTotalHits = $request->get_param('max_total_hits');
        $previousMaxTotalHits = IndexSettings::maxTotalHits();
        if (is_numeric($maxTotalHits) && (int) $maxTotalHits > 0) {
            Settings::save('max_total_hits', (int) $maxTotalHits);
        }

        ClientFactory::reset();

        if (IndexSettings::maxTotalHits() !== $previousMaxTotalHits) {
            $this->pushMaxTotalHits();
        }

        $integration = $request->get_param('query_integration');
        if (is_array($integration)) {
            AutoIntegration::save($integration);
        }

        // An experimental feature of the instance: changed there, only when asked to change
        $contains = $request->get_param('contains_filter');
        if (is_bool($contains) && $contains !== ContainsFilter::state()['enabled']) {
            ContainsFilter::set($contains);
        }

        return $this->respond($this->payload());
    }

    /**
     * Checks a host and keys, the saved ones or the ones being typed.
     */
    public function test(WP_REST_Request $request): WP_REST_Response
    {
        $host = $this->typedOrSaved($request, 'host', self::HOST);
        $adminKey = $this->typedOrSaved($request, 'admin_key', self::ADMIN_KEY);
        $searchKey = $this->typedOrSaved($request, 'search_key', self::SEARCH_KEY);

        $result = ['ok' => false, 'version' => null, 'admin_key' => false, 'search_key' => null, 'error' => null];

        if ($host === '' || $adminKey === '') {
            $result['error'] = __('Enter the instance URL and the admin key.', 'meiliscout');

            return $this->respond($result);
        }

        try {
            $client = ClientFactory::build($host, $adminKey);
            $result['version'] = $client->version()['pkgVersion'] ?? null;
            // Listing indexes needs a key with access to them: a search key fails here
            $client->getIndexes((new IndexesQuery)->setLimit(1));
            $result['admin_key'] = true;
            $result['ok'] = true;
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();

            return $this->respond($result);
        }

        if ($searchKey !== '') {
            $result['search_key'] = $this->searchKeyAccess($host, $searchKey);
        }

        return $this->respond($result);
    }

    /**
     * How many of the indexes searches read the search key can search.
     *
     * @return array{readable: int, total: int}
     */
    private function searchKeyAccess(string $host, string $key): array
    {
        $client = ClientFactory::build($host, $key);
        $readable = 0;

        foreach (IndexNames::BASES as $base) {
            try {
                $client->index(IndexNames::active($base))->search('', ['limit' => 0]);
                $readable++;
            } catch (\Throwable) {
                // No access, or no such index yet
            }
        }

        return ['readable' => $readable, 'total' => count(IndexNames::BASES)];
    }

    /**
     * Applies the maximum number of results to the index searches read, without waiting for an indexation.
     */
    private function pushMaxTotalHits(): void
    {
        try {
            ClientFactory::getClient()?->index(IndexNames::active('posts'))->updatePagination(['maxTotalHits' => IndexSettings::maxTotalHits()]);
        } catch (\Throwable $e) {
            // The next indexation sends it with the other settings
            error_log('MeiliScout: could not update the maximum number of results: '.$e->getMessage());
        }
    }

    private function typedOrSaved(WP_REST_Request $request, string $param, string $setting): string
    {
        $typed = $request->get_param($param);

        if (is_string($typed) && trim($typed) !== '' && ! Config::isReadOnly($setting)) {
            return trim($typed);
        }

        return (string) Config::get($setting, '');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $prefix = (string) Config::get(self::PREFIX, '');

        return [
            'host' => ['value' => (string) Config::get(self::HOST, ''), 'locked' => Config::isReadOnly(self::HOST)],
            'admin_key' => ['set' => (string) Config::get(self::ADMIN_KEY, '') !== '', 'locked' => Config::isReadOnly(self::ADMIN_KEY)],
            'search_key' => ['set' => (string) Config::get(self::SEARCH_KEY, '') !== '', 'locked' => Config::isReadOnly(self::SEARCH_KEY)],
            'prefix' => [
                'value' => $prefix,
                'locked' => Config::isReadOnly(self::PREFIX),
                // The domain's, when none is set
                'effective' => rtrim(IndexNames::prefix(), '_'),
            ],
            'index_names' => array_map([IndexNames::class, 'name'], IndexNames::BASES),
            'realtime' => ['value' => RealtimeIndexing::mode(), 'locked' => RealtimeIndexing::isLocked()],
            'timeout' => ClientFactory::timeout(),
            'batch_size' => Indexer::batchSize(),
            'max_total_hits' => IndexSettings::maxTotalHits(),
            'query_integration' => AutoIntegration::settings(),
            // The instance's state, which can be changed outside the plugin
            'contains_filter' => ContainsFilter::state(),
        ];
    }
}
