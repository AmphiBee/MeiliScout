<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Definition;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Listings\Render\Cards;
use Pollora\MeiliScout\Listings\State\ReservedParameters;

/**
 * A listing: the posts it shows, its facets, sorts and transport. Declared in
 * PHP (meiliscout_register_listing()) or by a block; both give this structure.
 *
 * Validated once: a definition that names an unindexed post type or meta key,
 * a reserved parameter, or an unknown option is refused with every reason.
 */
final class ListingDefinition
{
    public const TRANSPORTS = ['fragment', 'client', 'page'];

    /**
     * WP_Query orderby values a sort may use (the ones Meilisearch can sort on).
     */
    private const ORDERBY = ['date', 'modified', 'title', 'menu_order', 'comment_count', 'ID', 'meta_value', 'meta_value_num'];

    /**
     * @param  list<string>  $postTypes
     * @param  array{tax_query?: array<int|string, mixed>, meta_query?: array<int|string, mixed>}  $base
     * @param  array<string, array{label: string, orderby: string, order: string, meta_key?: string}>  $sorts
     * @param  list<FacetDefinition>  $facets
     * @param  list<string>  $publicMetas
     * @param  array{page?: int, archive?: string}  $route
     */
    private function __construct(
        public readonly string $id,
        public readonly array $postTypes,
        public readonly array $base,
        public readonly int $perPage,
        public readonly array $sorts,
        public readonly string $defaultSort,
        public readonly string $transport,
        public readonly bool $personalised,
        public readonly string $apply,
        public readonly array $facets,
        public readonly array $publicMetas,
        public readonly array $route,
        public readonly string $sortParam,
        public readonly string $searchParam,
        public readonly mixed $card = null,
        public readonly ?string $clientCard = null,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     *
     * @throws InvalidListing
     */
    public static function fromArray(string $id, array $args): self
    {
        $errors = [];

        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id)) {
            $errors[] = 'The id may only hold lowercase letters, digits, - and _.';
        }

        $indexedTypes = (array) Settings::get('indexed_post_types', []);
        $postTypes = array_values(array_map('strval', (array) ($args['post_types'] ?? [])));

        if ($postTypes === []) {
            $errors[] = 'post_types is required.';
        }

        foreach (array_diff($postTypes, $indexedTypes) as $type) {
            $errors[] = sprintf('The post type "%s" is not indexed (Content › Post types).', $type);
        }

        $base = [];
        foreach (['tax_query', 'meta_query'] as $clause) {
            if (isset($args['base'][$clause])) {
                if (! is_array($args['base'][$clause])) {
                    $errors[] = "base.{$clause} must be an array.";
                } else {
                    $base[$clause] = $args['base'][$clause];
                }
            }
        }

        $transport = (string) ($args['transport'] ?? 'fragment');
        if (! in_array($transport, self::TRANSPORTS, true)) {
            $errors[] = sprintf('transport must be one of %s.', implode(', ', self::TRANSPORTS));
        }

        $apply = (string) ($args['apply'] ?? 'instant');
        if (! in_array($apply, ['instant', 'button'], true)) {
            $errors[] = 'apply must be instant or button.';
        }

        $sortParam = (string) ($args['sort_param'] ?? 'sort');
        $searchParam = (string) ($args['search_param'] ?? 'q');

        $sorts = self::sorts($args['sorts'] ?? null, $errors);
        $defaultSort = (string) ($args['default_sort'] ?? array_key_first($sorts));
        if (! isset($sorts[$defaultSort])) {
            $errors[] = sprintf('default_sort "%s" is not one of the sorts.', $defaultSort);
        }

        $indexedMetas = (array) Settings::get('indexed_meta_keys', []);
        $facets = [];
        $params = [$sortParam, $searchParam];

        foreach ((array) ($args['facets'] ?? []) as $key => $facet) {
            $built = self::buildFacet((string) $key, is_array($facet) ? $facet : [], $indexedMetas, $errors);

            if ($built === null) {
                continue;
            }

            if (in_array($built->param, $params, true)) {
                $errors[] = sprintf('The parameter "%s" is used twice.', $built->param);
            }
            $params[] = $built->param;
            $facets[] = $built;
        }

        foreach ($params as $param) {
            if (ReservedParameters::isReserved($param)) {
                $errors[] = sprintf('The parameter "%s" is reserved (WordPress, WooCommerce or page caches read it).', $param);
            }
        }

        $publicMetas = array_values(array_map('strval', (array) ($args['public_metas'] ?? [])));
        foreach (array_diff($publicMetas, $indexedMetas) as $key) {
            $errors[] = sprintf('The public meta key "%s" is not indexed.', $key);
        }

        $card = $args['card'] ?? null;
        if (! Cards::isValid($card)) {
            $errors[] = 'card must be a callable, a template part name, blade:<view>, twig:<template> or a CardRenderer.';
        }

        $clientCard = $args['client_card'] ?? null;
        if ($clientCard !== null && (! is_string($clientCard) || $clientCard === '')) {
            $errors[] = 'client_card must be the markup of a card, bound to context.hit.';
        }

        $route = [];
        if (isset($args['route']['page'])) {
            $route['page'] = (int) $args['route']['page'];
        } elseif (isset($args['route']['archive'])) {
            $route['archive'] = (string) $args['route']['archive'];
        }

        if ($errors !== []) {
            throw new InvalidListing($id, $errors);
        }

        return new self(
            $id,
            $postTypes,
            $base,
            max(1, (int) ($args['per_page'] ?? 12)),
            $sorts,
            $defaultSort,
            $transport,
            (bool) ($args['personalised'] ?? false),
            $apply,
            $facets,
            $publicMetas,
            $route,
            $sortParam,
            $searchParam,
            $card,
            $clientCard,
        );
    }

    public function facet(string $key): ?FacetDefinition
    {
        foreach ($this->facets as $facet) {
            if ($facet->key === $key) {
                return $facet;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, array{label: string, orderby: string, order: string, meta_key?: string}>
     */
    private static function sorts(mixed $sorts, array &$errors): array
    {
        if (! is_array($sorts) || $sorts === []) {
            return ['date' => ['label' => __('Newest first', 'meiliscout'), 'orderby' => 'date', 'order' => 'DESC']];
        }

        $valid = [];

        foreach ($sorts as $key => $sort) {
            $orderby = (string) ($sort['orderby'] ?? '');
            $order = strtoupper((string) ($sort['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

            if (! in_array($orderby, self::ORDERBY, true)) {
                $errors[] = sprintf('The sort "%s" orders by "%s", which listings cannot sort on.', $key, $orderby);

                continue;
            }

            if (str_starts_with($orderby, 'meta_value') && empty($sort['meta_key'])) {
                $errors[] = sprintf('The sort "%s" needs a meta_key.', $key);

                continue;
            }

            $valid[(string) $key] = array_filter([
                'label' => (string) ($sort['label'] ?? $key),
                'orderby' => $orderby,
                'order' => $order,
                'meta_key' => isset($sort['meta_key']) ? (string) $sort['meta_key'] : null,
            ], fn ($value) => $value !== null);
        }

        return $valid;
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  list<string>  $indexedMetas
     * @param  list<string>  $errors
     */
    private static function buildFacet(string $key, array $args, array $indexedMetas, array &$errors): ?FacetDefinition
    {
        if (! preg_match('/^[a-z0-9_]+$/', $key)) {
            $errors[] = sprintf('The facet key "%s" may only hold lowercase letters, digits and _.', $key);

            return null;
        }

        [$source, $name] = array_pad(explode(':', (string) ($args['source'] ?? ''), 2), 2, '');
        $type = (string) ($args['type'] ?? FacetDefinition::LIST);
        $logic = (string) ($args['logic'] ?? 'or');

        if (! in_array($source, ['taxonomy', 'meta'], true) || $name === '') {
            $errors[] = sprintf('The facet "%s" needs a source: taxonomy:<name> or meta:<key>.', $key);

            return null;
        }

        if ($source === 'taxonomy' && ! taxonomy_exists($name)) {
            $errors[] = sprintf('The facet "%s" reads the taxonomy "%s", which does not exist.', $key, $name);

            return null;
        }

        if ($source === 'meta' && ! in_array($name, $indexedMetas, true)) {
            $errors[] = sprintf('The facet "%s" reads the meta key "%s", which is not indexed (Content › Meta keys).', $key, $name);

            return null;
        }

        if (! in_array($type, [FacetDefinition::LIST, FacetDefinition::RANGE, FacetDefinition::BOOLEAN], true)) {
            $errors[] = sprintf('The facet "%s" has an unknown type "%s".', $key, $type);

            return null;
        }

        if ($type !== FacetDefinition::LIST && $source !== 'meta') {
            $errors[] = sprintf('The facet "%s": ranges and booleans read a meta key.', $key);

            return null;
        }

        if (! in_array($logic, ['or', 'and'], true)) {
            $errors[] = sprintf('The facet "%s": logic must be or or and.', $key);

            return null;
        }

        $taxonomy = $source === 'taxonomy' ? get_taxonomy($name) : null;
        $hierarchical = $taxonomy !== null && $taxonomy !== false && $taxonomy->hierarchical && ($args['hierarchy'] ?? 'tree') !== 'flat';

        return new FacetDefinition(
            key: $key,
            source: $source,
            name: $name,
            type: $type,
            logic: $logic,
            hierarchical: $hierarchical,
            param: (string) ($args['param'] ?? $key),
            label: (string) ($args['label'] ?? ($taxonomy ? $taxonomy->labels->singular_name : $key)),
            labels: array_map('strval', (array) ($args['labels'] ?? [])),
            limit: max(0, (int) ($args['limit'] ?? 0)),
            decimals: max(0, min(6, (int) ($args['decimals'] ?? 0))),
            booleanValue: (string) ($args['value'] ?? '1'),
        );
    }
}
