<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Config\Portability\Sections;

use Pollora\MeiliScout\Config\Portability\Section;
use Pollora\MeiliScout\Config\Portability\SectionPlan;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Listings\Seo\RulesCsv;
use Pollora\MeiliScout\Listings\Seo\SeoRules;

use function __;
use function _n;
use function sprintf;

/**
 * The SEO rules of the listings' views, as the CSV the SEO rules screen
 * exports and imports (RulesCsv), so one format serves both.
 */
final class SeoRulesSection implements Section
{
    public function key(): string
    {
        return 'seo_rules';
    }

    public function label(): string
    {
        return __('SEO rules', 'meiliscout');
    }

    public function summary(): string
    {
        if ($this->unavailable() !== null) {
            return '';
        }

        $count = count(SeoRules::all());

        return sprintf(
            /* translators: %d: a number of rules */
            _n('%d rule', '%d rules', $count, 'meiliscout'),
            $count
        );
    }

    public function unavailable(): ?string
    {
        if (! (bool) Settings::get('listings_enabled', false)) {
            return __('The front listings module is off.', 'meiliscout');
        }

        return SeoRules::installed() ? null : __('The rules table is not installed yet.', 'meiliscout');
    }

    public function export(bool $includeEnvironment): array
    {
        return ['format' => 'csv', 'data' => RulesCsv::export(SeoRules::all())];
    }

    public function plan(array $data, array $options = []): SectionPlan
    {
        $plan = new SectionPlan($this->key());

        if (($reason = $this->unavailable()) !== null) {
            $plan->skipped = $reason;

            return $plan;
        }

        if (($data['format'] ?? null) !== 'csv' || ! is_string($data['data'] ?? null)) {
            $plan->fail(__('The SEO rules must be given as CSV text.', 'meiliscout'));

            return $plan;
        }

        $report = RulesCsv::import($data['data'], true);
        $plan->data = ['csv' => $data['data']];

        // The rules the site has, as the file would write them: the same text is nothing to import
        $same = trim(str_replace("\r\n", "\n", RulesCsv::export(SeoRules::all()))) === trim(str_replace("\r\n", "\n", $data['data']));
        $unchanged = $same ? SectionPlan::UNCHANGED : null;

        $plan->row('created', __('Rules to create', 'meiliscout'), 0, $report['created'], null, $report['created'] > 0 ? SectionPlan::CHANGED : SectionPlan::UNCHANGED);
        $plan->row('updated', __('Rules to replace', 'meiliscout'), $same ? $report['updated'] : 0, $report['updated'], null, $unchanged ?? ($report['updated'] > 0 ? SectionPlan::CHANGED : SectionPlan::UNCHANGED));

        foreach ($report['errors'] as $error) {
            $plan->warn(sprintf(
                /* translators: 1: a line number, 2: what is wrong */
                __('Line %1$d skipped: %2$s', 'meiliscout'),
                $error['line'],
                $error['message']
            ));
        }

        return $plan;
    }

    public function apply(SectionPlan $plan): void
    {
        RulesCsv::import((string) ($plan->data['csv'] ?? ''), false);
    }
}
