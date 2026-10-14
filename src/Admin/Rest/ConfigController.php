<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Admin\Rest;

use Pollora\MeiliScout\Config\Portability\ImportException;
use Pollora\MeiliScout\Config\Portability\ImportSession;
use Pollora\MeiliScout\Config\Portability\Transfer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function __;
use function gmdate;
use function home_url;
use function parse_url;
use function sanitize_file_name;

/**
 * Settings › Export / Import: what the configuration carries, the file of an
 * export, the preview of an import and its application.
 *
 * The browser sends a file's text once, to preview it; applying sends back the
 * token of the preview, so what is applied is what was shown.
 */
final class ConfigController extends Controller
{
    public function registerRoutes(): void
    {
        $this->route('/config/sections', 'GET', [$this, 'sections']);
        $this->route('/config/export', 'POST', [$this, 'export']);
        $this->route('/config/import/preview', 'POST', [$this, 'preview']);
        $this->route('/config/import/apply', 'POST', [$this, 'apply']);
    }

    public function sections(): WP_REST_Response
    {
        $sections = [];

        foreach (Transfer::sections() as $key => $section) {
            $unavailable = $section->unavailable();
            $sections[] = [
                'key' => $key,
                'label' => $section->label(),
                'summary' => $unavailable === null ? $section->summary() : '',
                'unavailable' => $unavailable,
            ];
        }

        return $this->respond(['sections' => $sections, 'max_bytes' => Transfer::MAX_BYTES]);
    }

    public function export(WP_REST_Request $request): WP_REST_Response
    {
        $only = $this->names($request, 'sections');
        $document = Transfer::export($only === [] ? null : $only, (bool) $request->get_param('include_env'));

        $host = (string) (parse_url(home_url(), PHP_URL_HOST) ?: 'site');

        return $this->respond([
            'filename' => sanitize_file_name('meiliscout-'.$host.'-'.gmdate('Y-m-d').'.json'),
            'document' => $document,
        ]);
    }

    public function preview(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $file = $request->get_param('file');

        if (! is_string($file)) {
            return new WP_Error('meiliscout_no_file', __('No file was sent.', 'meiliscout'), ['status' => 400]);
        }

        try {
            $document = Transfer::parse($file);
        } catch (ImportException $e) {
            return new WP_Error('meiliscout_'.$e->reason, $e->getMessage(), ['status' => $e->reason === 'too_large' ? 413 : 400]);
        }

        $only = $this->names($request, 'sections');
        $options = [
            'only' => $only === [] ? null : $only,
            'include_env' => (bool) $request->get_param('include_env'),
            'strict' => (bool) $request->get_param('strict'),
        ];

        $plan = Transfer::plan($document, $options);

        return $this->respond($plan->toArray() + ['token' => ImportSession::store($document, $options)]);
    }

    public function apply(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $token = $request->get_param('token');
        $saved = is_string($token) ? ImportSession::load($token) : null;

        if ($saved === null) {
            return new WP_Error('meiliscout_expired', __('This preview has expired: choose the file again.', 'meiliscout'), ['status' => 410]);
        }

        // Planned again, on the site as it is now: a change since the preview shows
        $plan = Transfer::plan($saved['document'], $saved['options']);

        if (! $plan->applicable()) {
            return new WP_Error('meiliscout_not_applicable', __('Nothing can be imported from this file.', 'meiliscout'), ['status' => 409]);
        }

        $only = $this->names($request, 'sections');
        $applied = Transfer::apply($plan, $only === [] ? null : $only);

        ImportSession::forget((string) $token);

        $reindex = false;
        foreach ($applied as $key) {
            $reindex = $reindex || $plan->sections[$key]->reindex;
        }

        return $this->respond(['applied' => $applied, 'reindex' => $reindex]);
    }

    /**
     * @return list<string>
     */
    private function names(WP_REST_Request $request, string $param): array
    {
        $value = $request->get_param($param);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
