<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Diagnostics;

use Pollora\MeiliScout\Listings\Definition\FacetDefinition;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\Query\ListingQuery;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Listings\State\UrlCodec;

/**
 * A routed listing's URLs asked over HTTP, as a visitor would (design §14):
 * its first page, its fragment, a view of each facet of its path, a value
 * that is no term (404), two values out of order (301 to the canonical URL).
 */
final class RouteProbe
{
    /**
     * A slug no term has.
     */
    private const MISSING = 'meiliscout-no-such-term';

    /**
     * @return list<array{label: string, url: string, expected: int, status: int, ok: bool, note: string}>
     */
    public static function run(ListingDefinition $definition, string $language = ''): array
    {
        if ($definition->route === []) {
            return [];
        }

        return Languages::adapter()->in($language, function () use ($definition, $language): array {
            $base = Listings::baseUrl($definition);
            if ($base === '') {
                return [];
            }

            $probes = [
                self::probe(__('First page', 'meiliscout'), $base, 200),
                self::fragment($definition, $base, $language),
            ];

            $result = ListingQuery::run($definition, new ListingState);

            foreach ($definition->pathFacets() as $facet) {
                $values = array_values(array_filter(
                    $result->facets[$facet->key]['options'] ?? [],
                    fn (array $option) => $option['count'] > 0
                ));

                if ($values !== []) {
                    $probes[] = self::probe(
                        sprintf('%s=%s', $facet->key, $values[0]['value']),
                        UrlCodec::url($definition, new ListingState([$facet->key => [$values[0]['value']]]), $base),
                        200
                    );
                }

                $probes[] = self::probe(
                    sprintf('%s=%s', $facet->key, self::MISSING),
                    UrlCodec::url($definition, new ListingState([$facet->key => [self::MISSING]]), $base),
                    404
                );

                if (count($values) > 1 && $facet->type === FacetDefinition::LIST) {
                    $probes[] = self::reordered($definition, $facet, $base, $values[0]['value'], $values[1]['value']);
                }
            }

            return array_map(fn (array $probe) => array_diff_key($probe, ['body' => true, 'location' => true]), $probes);
        });
    }

    /**
     * @return array{label: string, url: string, expected: int, status: int, ok: bool, note: string}
     */
    private static function fragment(ListingDefinition $definition, string $base, string $language): array
    {
        $url = add_query_arg(
            array_filter(['url' => rawurlencode((string) wp_parse_url($base, PHP_URL_PATH)), 'lang' => $language]),
            rest_url("meiliscout/v1/listings/{$definition->id}/fragment")
        );
        $probe = self::probe(__('Fragment', 'meiliscout'), $url, 200);

        if ($probe['ok'] && ! str_contains($probe['body'], 'data-wp-router-region')) {
            $probe['ok'] = false;
            $probe['note'] = __('No router region in the fragment.', 'meiliscout');
        }

        return $probe;
    }

    /**
     * Two values in the other order: a 301 to the canonical URL.
     *
     * @return array{label: string, url: string, expected: int, status: int, ok: bool, note: string}
     */
    private static function reordered(ListingDefinition $definition, FacetDefinition $facet, string $base, string $a, string $b): array
    {
        $canonical = UrlCodec::url($definition, new ListingState([$facet->key => [$a, $b]]), $base);
        $segment = UrlCodec::pathSegments($definition, new ListingState([$facet->key => [$a, $b]]))[0] ?? '';
        [$prefix, $list] = [substr($segment, 0, strlen((string) $facet->prefix()) + 1), substr($segment, strlen((string) $facet->prefix()) + 1)];
        $reversed = $prefix.implode(',', array_reverse(explode(',', $list)));
        $url = str_replace('/'.$segment.'/', '/'.$reversed.'/', $canonical);

        $probe = self::probe(sprintf('%s=%s,%s', $facet->key, $b, $a), $url, 301);

        if ($probe['ok'] && $probe['location'] !== $canonical) {
            $probe['ok'] = false;
            $probe['note'] = sprintf('→ %s', $probe['location']);
        }

        return $probe;
    }

    /**
     * @return array{label: string, url: string, expected: int, status: int, ok: bool, note: string, body?: string, location?: string}
     */
    private static function probe(string $label, string $url, int $expected): array
    {
        $response = wp_remote_get($url, [
            'redirection' => 0,
            'timeout' => 20,
            // The local site's own certificate (DDEV, a staging host)
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return ['label' => $label, 'url' => $url, 'expected' => $expected, 'status' => 0, 'ok' => false, 'note' => $response->get_error_message(), 'body' => '', 'location' => ''];
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        return [
            'label' => $label,
            'url' => $url,
            'expected' => $expected,
            'status' => $status,
            'ok' => $status === $expected,
            'note' => '',
            'body' => (string) wp_remote_retrieve_body($response),
            'location' => (string) wp_remote_retrieve_header($response, 'location'),
        ];
    }
}
