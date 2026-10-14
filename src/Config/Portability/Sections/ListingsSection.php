<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config\Portability\Sections;

use Pollora\MeiliScout\Config\Portability\Section;
use Pollora\MeiliScout\Config\Portability\SectionPlan;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Listings\Admin\ListingsSettings;
use Pollora\MeiliScout\Listings\ListingsServiceProvider;

use function __;
use function esc_url_raw;
use function filter_var;
use function sanitize_hex_color;
use function sanitize_key;
use function sprintf;
use function trim;
use function untrailingslashit;

/**
 * The front listings module: its switch, the default look and its colors.
 *
 * Where browsers reach Meilisearch belongs to the server, not to the site: it
 * travels only when the export asks for the environment's values. The key the
 * tokens are signed with never travels.
 */
final class ListingsSection implements Section
{
    private const COLORS = ['accent', 'accent-contrast', 'text', 'background'];

    public function key(): string
    {
        return 'listings';
    }

    public function label(): string
    {
        return __('Front listings', 'meiliscout');
    }

    public function summary(): string
    {
        return (bool) Settings::get('listings_enabled', false)
            ? __('Module on', 'meiliscout')
            : __('Module off', 'meiliscout');
    }

    public function unavailable(): ?string
    {
        return null;
    }

    public function export(bool $includeEnvironment): array
    {
        $data = [
            'enabled' => (bool) Settings::get('listings_enabled', false),
            'styles' => (bool) Settings::get('listings_styles', true),
            'colors' => (object) array_filter((array) Settings::get('listings_colors', []), 'is_string'),
        ];

        if ($includeEnvironment) {
            $data['environment'] = ['public_host' => (string) Settings::get('listings_public_host', '')];
        }

        return $data;
    }

    public function plan(array $data, array $options = []): SectionPlan
    {
        $plan = new SectionPlan($this->key());
        $current = $this->export(false);
        $current['colors'] = (array) $current['colors'];

        foreach (array_diff(array_keys($data), ['enabled', 'styles', 'colors', 'environment']) as $unknown) {
            $plan->warn(sprintf(
                /* translators: %s: the name of a setting in the file */
                __('“%s” is not a setting of this version: ignored.', 'meiliscout'),
                (string) $unknown
            ));
        }

        if (array_key_exists('enabled', $data)) {
            if (! is_bool($data['enabled'])) {
                $plan->fail(__('“Front listings” must be true or false.', 'meiliscout'));
            } elseif ($data['enabled'] && $this->tooOld()) {
                $plan->row('enabled', __('Front listings', 'meiliscout'), $current['enabled'], $data['enabled'], sprintf(
                    /* translators: %s: a WordPress version */
                    __('Needs WordPress %s or later: not changed.', 'meiliscout'),
                    ListingsServiceProvider::MIN_WORDPRESS
                ), SectionPlan::WARNING);
            } else {
                $plan->data['enabled'] = $data['enabled'];
                $plan->row('enabled', __('Front listings', 'meiliscout'), $current['enabled'], $data['enabled']);
            }
        }

        if (array_key_exists('styles', $data)) {
            if (! is_bool($data['styles'])) {
                $plan->fail(__('“Default styles” must be true or false.', 'meiliscout'));
            } else {
                $plan->data['styles'] = $data['styles'];
                $plan->row('styles', __('Default styles', 'meiliscout'), $current['styles'], $data['styles']);
            }
        }

        if (array_key_exists('colors', $data)) {
            $colors = [];
            $given = is_object($data['colors']) ? (array) $data['colors'] : $data['colors'];

            if (! is_array($given)) {
                $plan->fail(__('“Colors” must be a list of colors by name.', 'meiliscout'));
            } else {
                foreach ($given as $token => $value) {
                    if (! in_array($token, self::COLORS, true)) {
                        $plan->warn(sprintf(
                            /* translators: %s: the name of a color */
                            __('The color “%s” is not one of this version: ignored.', 'meiliscout'),
                            (string) $token
                        ));
                    } elseif (is_string($value) && (str_starts_with($value, 'preset:') || sanitize_hex_color($value))) {
                        $colors[$token] = str_starts_with($value, 'preset:') ? 'preset:'.sanitize_key(substr($value, 7)) : $value;
                    } else {
                        $plan->warn(sprintf(
                            /* translators: %s: the name of a color */
                            __('The color “%s” is neither a hex color nor a palette preset: ignored.', 'meiliscout'),
                            (string) $token
                        ));
                    }
                }

                $plan->data['colors'] = $colors;
                $plan->row('colors', __('Colors', 'meiliscout'), $current['colors'], $colors);
            }
        }

        if (($options['include_env'] ?? false) && isset($data['environment']['public_host'])) {
            $host = $data['environment']['public_host'];
            $host = is_string($host) ? untrailingslashit(trim(esc_url_raw($host))) : null;

            if ($host === null || ($host !== '' && filter_var($host, FILTER_VALIDATE_URL) === false)) {
                $plan->fail(__('The public URL of Meilisearch is not a valid URL.', 'meiliscout'));
            } else {
                $plan->data['public_host'] = $host;
                $plan->row('public_host', __('Public URL of Meilisearch', 'meiliscout'), (string) Settings::get('listings_public_host', ''), $host);
            }
        } elseif (isset($data['environment'])) {
            $plan->warn(__('The file holds values of the server it came from (public URL): left out, they are imported on request only.', 'meiliscout'));
        }

        return $plan;
    }

    /**
     * Whether this WordPress is too old to run the module (it answers 'disabled' before then).
     */
    private function tooOld(): bool
    {
        global $wp_version;

        return is_string($wp_version) && version_compare($wp_version, ListingsServiceProvider::MIN_WORDPRESS, '<');
    }

    public function apply(SectionPlan $plan): void
    {
        ListingsSettings::save($plan->data);
    }
}
