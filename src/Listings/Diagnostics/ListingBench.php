<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Diagnostics;

use Pollora\MeiliScout\Listings\Definition\FacetDefinition;
use Pollora\MeiliScout\Listings\Definition\ListingDefinition;
use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Listings\Query\FacetPlan;
use Pollora\MeiliScout\Listings\Query\ListingQuery;
use Pollora\MeiliScout\Listings\State\ListingState;
use Pollora\MeiliScout\Listings\State\UrlCodec;
use Pollora\MeiliScout\Listings\Transport\TenantTokens;

/**
 * The listings' performance bench (design §15, prototype P1): for a few
 * states of a listing, the median time to first byte of
 *
 * - the page, as a visitor's first request gets it (transport A);
 * - its fragment, what the client asks for a change (transport B);
 * - the multi-search of its counts, as the browser sends it to Meilisearch
 *   with the listing's tenant token (every change), with its cards in the
 *   client transport;
 *
 * and their sizes, raw and gzipped. Requests go from this host to the site's
 * URLs and the public Meilisearch host: run it where the network is the one
 * to measure.
 */
final class ListingBench
{
    /**
     * The states benched: the first page, a later one, a value of the first
     * list facet, and two facets.
     *
     * @return array<string, ListingState>
     */
    public static function states(ListingDefinition $definition): array
    {
        $result = ListingQuery::run($definition, new ListingState);
        $states = ['first page' => new ListingState];

        if ($result->pages() > 2) {
            $states['page 3'] = new ListingState([], [], '', 3);
        }

        $lists = array_values(array_filter($definition->facets, fn (FacetDefinition $facet) => $facet->type === FacetDefinition::LIST));
        $picked = [];

        foreach (array_slice($lists, 0, 2) as $facet) {
            foreach ($result->facets[$facet->key]['options'] ?? [] as $option) {
                if ($option['count'] > 0) {
                    $picked[$facet->key] = [$option['value']];
                    break;
                }
            }
        }

        if ($picked !== []) {
            $first = array_slice($picked, 0, 1, true);
            $states[self::label($first)] = new ListingState($first);
        }
        if (count($picked) > 1) {
            $states[self::label($picked)] = new ListingState($picked);
        }

        return $states;
    }

    /**
     * @param  array<string, list<string>>  $values
     */
    private static function label(array $values): string
    {
        return implode(', ', array_map(fn (string $key, array $list) => $key.'='.implode(',', $list), array_keys($values), $values));
    }

    /**
     * @return list<array{state: string, target: string, url: string, ms: float, status: int, bytes: int, gzip: int}>
     */
    public static function run(ListingDefinition $definition, string $language = '', int $runs = 20): array
    {
        return Languages::adapter()->in($language, function () use ($definition, $language, $runs): array {
            $base = Listings::baseUrl($definition);
            if ($definition->route === [] || $base === '') {
                return [];
            }

            $token = TenantTokens::forListing($definition);
            $rows = [];

            foreach (self::states($definition) as $label => $state) {
                $url = UrlCodec::url($definition, $state, $base);
                $fragment = add_query_arg(
                    array_filter(['url' => rawurlencode((string) wp_parse_url($url, PHP_URL_PATH).(($query = wp_parse_url($url, PHP_URL_QUERY)) ? '?'.$query : '')), 'lang' => $language]),
                    rest_url("meiliscout/v1/listings/{$definition->id}/fragment")
                );

                $rows[] = ['state' => $label, 'target' => 'page'] + self::measure($url, null, [], $runs);
                $rows[] = ['state' => $label, 'target' => 'fragment'] + self::measure($fragment, null, [], $runs);

                if ($token !== null) {
                    // The client transport asks for its cards in the same multi-search
                    $queries = FacetPlan::counts($definition, $state, null);
                    if ($definition->transport === 'client') {
                        $queries[] = FacetPlan::results($definition, $state, null);
                    }
                    $body = (string) wp_json_encode(['queries' => $queries]);
                    $rows[] = ['state' => $label, 'target' => $definition->transport === 'client' ? 'counts + cards' : 'counts'] + self::measure(
                        TenantTokens::publicHost().'/multi-search',
                        $body,
                        ['Content-Type: application/json', 'Authorization: Bearer '.$token['value']],
                        $runs
                    );
                }
            }

            return $rows;
        });
    }

    /**
     * The median time to first byte of a request, after three to warm up.
     *
     * @param  list<string>  $headers
     * @return array{url: string, ms: float, status: int, bytes: int, gzip: int}
     */
    private static function measure(string $url, ?string $body, array $headers, int $runs): array
    {
        $times = [];
        $last = ['status' => 0, 'body' => ''];

        for ($i = 0; $i < $runs + 3; $i++) {
            $last = self::request($url, $body, $headers);
            if ($i >= 3) {
                $times[] = $last['ttfb'];
            }
        }

        sort($times);
        $middle = intdiv(count($times), 2);
        $median = count($times) % 2 === 1 ? $times[$middle] : ($times[$middle - 1] + $times[$middle]) / 2;

        return [
            'url' => $url,
            'ms' => round($median * 1000, 1),
            'status' => $last['status'],
            'bytes' => strlen($last['body']),
            'gzip' => strlen((string) gzencode($last['body'], 6)),
        ];
    }

    /**
     * @param  list<string>  $headers
     * @return array{status: int, ttfb: float, body: string}
     */
    private static function request(string $url, ?string $body, array $headers): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            // The local site's own certificate (DDEV, a staging host)
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($curl);
        $result = [
            'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'ttfb' => (float) curl_getinfo($curl, CURLINFO_STARTTRANSFER_TIME),
            'body' => is_string($response) ? $response : '',
        ];
        curl_close($curl);

        return $result;
    }
}
