<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Admin\Rest;

use Meilisearch\Client;
use Pollora\MeiliScout\Services\ClientFactory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function current_user_can;
use function register_rest_route;
use function rest_ensure_response;

/**
 * Base of the REST endpoints the admin app calls, under meiliscout/v1.
 *
 * Every endpoint is for administrators only.
 */
abstract class Controller
{
    public const NAMESPACE = 'meiliscout/v1';

    /**
     * Registers the controller's routes. Hooked on rest_api_init.
     */
    abstract public function registerRoutes(): void;

    /**
     * Registers a route for administrators.
     *
     * @param  array<string, mixed>  $args
     */
    protected function route(string $path, string $methods, callable $callback, array $args = []): void
    {
        register_rest_route(self::NAMESPACE, $path, [
            'methods' => $methods,
            'callback' => $callback,
            'permission_callback' => [$this, 'canManage'],
            'args' => $args,
        ]);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * The admin client, or an error the app shows when Meilisearch cannot be reached.
     */
    protected function client(): Client|WP_Error
    {
        return ClientFactory::getClient() ?? $this->unreachable();
    }

    protected function unreachable(): WP_Error
    {
        return new WP_Error(
            'meiliscout_unreachable',
            __('Meilisearch cannot be reached: check the connection settings.', 'meiliscout'),
            ['status' => 503]
        );
    }

    /**
     * @param  mixed  $data
     */
    protected function respond($data): WP_REST_Response
    {
        return rest_ensure_response($data);
    }

    /**
     * A list of strings from a request parameter, sanitized.
     *
     * @return list<string>
     */
    protected function strings(WP_REST_Request $request, string $key): array
    {
        $value = $request->get_param($key);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item) => is_scalar($item) ? sanitize_text_field((string) $item) : '',
            $value
        ), static fn (string $item) => $item !== ''));
    }

    /**
     * A date as the app reads it (ISO 8601, UTC), or null.
     */
    protected function isoDate(?\DateTimeInterface $date): ?string
    {
        return $date?->format(\DateTimeInterface::ATOM);
    }
}
