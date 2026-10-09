<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Admin;

use Pollora\MeiliScout\Admin\Rest\Controller;
use Pollora\MeiliScout\Listings\Definition\DefinitionRegistry;
use Pollora\MeiliScout\Listings\Definition\InvalidListing;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\Seo\RuleKey;
use Pollora\MeiliScout\Listings\Seo\RulesCsv;
use Pollora\MeiliScout\Listings\Seo\SeoRule;
use Pollora\MeiliScout\Listings\Seo\SeoRules;
use Pollora\MeiliScout\Listings\Seo\SeoRulesService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The SEO rules screen (design §8.3): the listings and the terms their keys
 * may name, the rules, saving and deleting one, CSV import and export, and
 * the preview of a URL.
 */
final class SeoRulesController extends Controller
{
    /**
     * Terms offered per facet; more are found by search.
     */
    private const TERMS = 200;

    public function registerRoutes(): void
    {
        $this->route('/listings/seo-rules', 'GET', [$this, 'index']);
        $this->route('/listings/seo-rules', 'POST', [$this, 'save']);
        $this->route('/listings/seo-rules/(?P<id>\d+)', 'DELETE', [$this, 'delete']);
        $this->route('/listings/seo-rules/(?P<id>\d+)/duplicate', 'POST', [$this, 'duplicate']);
        $this->route('/listings/seo-rules/export', 'GET', [$this, 'export']);
        $this->route('/listings/seo-rules/import', 'POST', [$this, 'import']);
        $this->route('/listings/seo-rules/preview', 'GET', [$this, 'preview'], ['url' => ['type' => 'string', 'required' => true]]);
    }

    public function index(): WP_REST_Response
    {
        SeoRules::install();
        $adapter = Languages::adapter();
        $locales = array_map([$adapter, 'locale'], $adapter->languages());

        return $this->respond([
            'listings' => $this->listings(),
            'rules' => array_map([$this, 'rule'], SeoRules::all()),
            'locale' => get_locale(),
            'locales' => array_values(array_unique(array_merge([get_locale()], $locales, get_available_languages(), ['en_US']))),
            // The multilingual plugin's languages: rules can be copied to them
            'languages' => $locales,
        ]);
    }

    public function save(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $input = (array) $request->get_json_params();

        try {
            SeoRulesService::save($input);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return new WP_Error('meiliscout_seo_rule', $e->getMessage(), ['status' => 400]);
        }

        return $this->index();
    }

    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        SeoRules::delete((int) $request['id']);

        return $this->index();
    }

    public function duplicate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $report = SeoRulesService::duplicate((int) $request['id']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return new WP_Error('meiliscout_seo_rule', $e->getMessage(), ['status' => 400]);
        }

        return $this->respond(['duplicated' => $report] + $this->index()->get_data());
    }

    public function export(WP_REST_Request $request): WP_REST_Response
    {
        $listing = (string) $request->get_param('listing');

        return $this->respond([
            'filename' => 'meiliscout-seo-rules'.($listing !== '' ? '-'.sanitize_file_name($listing) : '').'.csv',
            'csv' => RulesCsv::export(SeoRules::all($listing !== '' ? $listing : null)),
        ]);
    }

    public function import(WP_REST_Request $request): WP_REST_Response
    {
        $report = RulesCsv::import((string) $request->get_param('csv'), (bool) $request->get_param('dry_run'));

        return $this->respond(['report' => $report] + $this->index()->get_data());
    }

    public function preview(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $url = (string) $request['url'];

        // A path, or a URL of this site
        if (! str_starts_with($url, '/')) {
            $home = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            if ((string) wp_parse_url($url, PHP_URL_HOST) !== $home) {
                return new WP_Error('meiliscout_seo_preview', __('The URL must be a page of this site.', 'meiliscout'), ['status' => 400]);
            }
        }

        try {
            return $this->respond(SeoRulesService::preview($url));
        } catch (\InvalidArgumentException $e) {
            return new WP_Error('meiliscout_seo_preview', $e->getMessage(), ['status' => 400]);
        }
    }

    /**
     * The listings, their page, and the facets a rule may name with their terms.
     *
     * @return list<array<string, mixed>>
     */
    private function listings(): array
    {
        $listings = [];

        foreach (DefinitionRegistry::ids() as $id) {
            try {
                $definition = DefinitionRegistry::get($id);
            } catch (InvalidListing) {
                continue;
            }

            $facets = [];
            foreach (RuleKey::facets($definition) as $facet) {
                $terms = get_terms(['taxonomy' => $facet->name, 'hide_empty' => false, 'number' => self::TERMS, 'orderby' => 'name']);
                $facets[] = [
                    'key' => $facet->key,
                    'label' => $facet->label,
                    'taxonomy' => $facet->name,
                    'terms' => is_array($terms) ? array_map(fn (\WP_Term $term) => ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'parent' => $term->parent], array_values($terms)) : [],
                ];
            }

            $listings[] = [
                'id' => $definition->id,
                'url' => $definition->route !== [] ? Listings::baseUrl($definition) : null,
                'seo' => $definition->seo,
                'facets' => $facets,
                'depth' => RuleKey::MAX_DEPTH,
            ];
        }

        return $listings;
    }

    /**
     * @return array<string, mixed>
     */
    private function rule(SeoRule $rule): array
    {
        try {
            $label = RuleKey::label(DefinitionRegistry::get($rule->listing), $rule->key);
        } catch (InvalidListing|\OutOfBoundsException) {
            $label = $rule->key;
        }

        return [
            'id' => $rule->id,
            'listing' => $rule->listing,
            'locale' => $rule->locale,
            'key' => $rule->key,
            'key_label' => $label,
            'specificity' => $rule->specificity,
            'updated_at' => $rule->updatedAt,
        ] + $rule->fields();
    }
}
