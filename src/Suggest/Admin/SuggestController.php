<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Admin;

use Pollora\MeiliScout\Admin\Rest\Controller;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Listings\Admin\ListingsSettings;
use Pollora\MeiliScout\Listings\Transport\TenantTokens;
use Pollora\MeiliScout\Suggest\Diagnostics\SuggestChecks;
use Pollora\MeiliScout\Suggest\Transport\SuggestKey;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Suggestions screen: the declared search fields, PHP and blocks, with
 * their sources, checks and how to print them; the key their tokens are
 * signed with, and where browsers reach Meilisearch.
 */
final class SuggestController extends Controller
{
    public function registerRoutes(): void
    {
        $this->route('/suggest', 'GET', [$this, 'index']);
        $this->route('/suggest/key', 'POST', [$this, 'rotateKey']);
        $this->route('/suggest/host', 'POST', [$this, 'saveHost'], [
            'public_host' => ['type' => 'string', 'required' => true],
        ]);
    }

    public function index(): WP_REST_Response
    {
        return $this->respond($this->payload());
    }

    /**
     * Replaces the key: the tokens of every page cached so far are refused.
     */
    public function rotateKey(): WP_REST_Response|WP_Error
    {
        if (SuggestKey::rotate() === null) {
            return new WP_Error('meiliscout_suggest_key', __('The key could not be made: check the connection and that the admin key may manage keys.', 'meiliscout'), ['status' => 503]);
        }

        return $this->respond($this->payload());
    }

    public function saveHost(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            ListingsSettings::save(['public_host' => (string) $request['public_host']]);
        } catch (\InvalidArgumentException $e) {
            return new WP_Error('meiliscout_public_host', $e->getMessage(), ['status' => 400]);
        }

        return $this->respond($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $key = SuggestKey::saved();

        return [
            'suggests' => SuggestChecks::all(),
            'key' => $key === null ? null : ['uid' => $key['uid'], 'indexes' => $key['indexes']],
            // The setting, and where browsers will go (the setting, else the server's own address)
            'public_host' => (string) Settings::get('listings_public_host', ''),
            'effective_host' => TenantTokens::publicHost(),
        ];
    }
}
