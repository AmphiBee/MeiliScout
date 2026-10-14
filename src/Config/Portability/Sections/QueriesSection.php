<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config\Portability\Sections;

use Pollora\MeiliScout\Config\Portability\Normalize;
use Pollora\MeiliScout\Config\Portability\Section;
use Pollora\MeiliScout\Config\Portability\SectionPlan;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Query\AutoIntegration;
use Pollora\MeiliScout\Query\Terms\TermAutoIntegration;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\IndexSettings;

use function __;
use function _n;
use function sprintf;

/**
 * Which queries Meilisearch serves without being asked, and how far a query goes.
 */
final class QueriesSection implements Section
{
    public function key(): string
    {
        return 'queries';
    }

    public function label(): string
    {
        return __('Queries', 'meiliscout');
    }

    public function summary(): string
    {
        $on = count(array_filter(AutoIntegration::settings())) + count(array_filter(TermAutoIntegration::settings()));

        return sprintf(
            /* translators: %d: a number of kinds of queries served */
            _n('%d kind of query served', '%d kinds of queries served', $on, 'meiliscout'),
            $on
        );
    }

    public function unavailable(): ?string
    {
        return null;
    }

    public function export(bool $includeEnvironment): array
    {
        return [
            'query_integration' => AutoIntegration::settings(),
            'term_query_integration' => TermAutoIntegration::settings(),
            'max_total_hits' => IndexSettings::maxTotalHits(),
            'http_timeout' => (int) Settings::get('http_timeout', 10),
        ];
    }

    public function plan(array $data, array $options = []): SectionPlan
    {
        $plan = new SectionPlan($this->key());
        $current = $this->export(false);

        foreach (array_diff(array_keys($data), array_keys($current)) as $unknown) {
            $plan->warn(sprintf(
                /* translators: %s: the name of a setting in the file */
                __('“%s” is not a setting of this version: ignored.', 'meiliscout'),
                (string) $unknown
            ));
        }

        $maps = [
            'query_integration' => [__('Queries served', 'meiliscout'), AutoIntegration::DEFAULTS],
            'term_query_integration' => [__('Term queries served', 'meiliscout'), TermAutoIntegration::DEFAULTS],
        ];

        foreach ($maps as $field => [$label, $defaults]) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $switches = Normalize::switches($data[$field], $defaults);
            if ($switches === null) {
                $plan->fail(sprintf(
                    /* translators: %s: a setting's name */
                    __('“%s” must be a list of switches, each true or false.', 'meiliscout'),
                    $label
                ));
                continue;
            }

            foreach ($switches['unknown'] as $name) {
                $plan->warn(sprintf(
                    /* translators: 1: a setting's name, 2: a switch's name */
                    __('“%1$s”: “%2$s” is not a switch of this version, ignored.', 'meiliscout'),
                    $label,
                    $name
                ));
            }

            $plan->data[$field] = $switches['values'];
            $plan->row($field, $label, $current[$field], $switches['values']);
        }

        foreach (['max_total_hits' => __('Maximum results per query', 'meiliscout'), 'http_timeout' => __('Timeout (seconds)', 'meiliscout')] as $field => $label) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $number = Normalize::positiveInt($data[$field]);
            if ($number === null) {
                $plan->fail(sprintf(
                    /* translators: %s: a setting's name */
                    __('“%s” must be a positive whole number.', 'meiliscout'),
                    $label
                ));
                continue;
            }

            $plan->data[$field] = $number;
            $plan->row($field, $label, $current[$field], $number);
        }

        return $plan;
    }

    public function apply(SectionPlan $plan): void
    {
        $data = $plan->data;

        if (array_key_exists('query_integration', $data)) {
            AutoIntegration::save($data['query_integration']);
        }

        if (array_key_exists('term_query_integration', $data)) {
            TermAutoIntegration::save($data['term_query_integration']);
        }

        if (array_key_exists('http_timeout', $data)) {
            Settings::save('http_timeout', $data['http_timeout']);
        }

        if (array_key_exists('max_total_hits', $data)) {
            $previous = IndexSettings::maxTotalHits();
            Settings::save('max_total_hits', $data['max_total_hits']);
            ClientFactory::reset();

            // The index searches read, without waiting for an indexation
            if (IndexSettings::maxTotalHits() !== $previous) {
                IndexSettings::pushMaxTotalHits();
            }
        }
    }
}
