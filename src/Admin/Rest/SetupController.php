<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Admin\Rest;

use Pollora\MeiliScout\Admin\SetupState;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function __;

/**
 * The setup assistant: its state, and what it checks first.
 */
final class SetupController extends Controller
{
    public function registerRoutes(): void
    {
        $this->route('/setup', 'GET', [$this, 'show']);
        $this->route('/setup', 'POST', [$this, 'update'], [
            'state' => ['type' => ['string', 'null'], 'enum' => [SetupState::COMPLETED, SetupState::DISMISSED, 'reset', null]],
        ]);
    }

    public function show(): WP_REST_Response
    {
        return $this->respond(['state' => SetupState::get()] + SetupState::checks());
    }

    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $state = $request->get_param('state');

        if (! in_array($state, [SetupState::COMPLETED, SetupState::DISMISSED, 'reset', null], true)) {
            return new WP_Error('meiliscout_setup_state', __('Unknown state.', 'meiliscout'), ['status' => 400]);
        }

        SetupState::set(is_string($state) && $state !== 'reset' ? $state : null);

        return $this->show();
    }
}
