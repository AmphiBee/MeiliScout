<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Query\PhpOrder;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\IndexSettings;

use function apply_filters;

/**
 * The attributes a search returns: the ids only, since posts are loaded from the database.
 *
 * Loading them from the database gives the same WP_Post objects MySQL does,
 * fresh and complete (post_password included), at the cost of a query on the
 * primary key, often served by the object cache. The meiliscout/hydrate_from_documents
 * filter builds them from the documents instead.
 */
class FieldsBuilder implements QueryBuilderInterface
{
    /**
     * @param  array<string, mixed>  $searchParams
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        if (self::hydrateFromDocuments($query)) {
            return;
        }

        $attributes = $query->get('fields') === 'id=>parent' ? ['ID', 'post_parent'] : ['ID'];
        $order = PhpOrder::of($query);

        $attributes = array_values(array_unique([...$attributes, ...($order?->attributes() ?? [])]));
        $missing = IndexSettings::firstUncovered(IndexSettings::displayed(IndexNames::active('posts')), $attributes);

        // An indexable may narrow displayedAttributes: a field the index does not return cannot be read back
        if ($missing !== null) {
            throw new UnsupportedQuery("undisplayed_attribute:{$missing}");
        }

        $searchParams['attributesToRetrieve'] = $attributes;
    }

    /**
     * Whether posts are built from the documents rather than loaded from the database.
     */
    public static function hydrateFromDocuments(QueryInterface $query): bool
    {
        if (in_array($query->get('fields'), ['ids', 'id=>parent'], true)) {
            return false;
        }

        /**
         * Filters whether WP_Post objects are built from the Meilisearch documents.
         *
         * Saves a database query, but the posts are as fresh as the index,
         * protected posts lose their content, and only displayed attributes are there.
         *
         * @param  bool  $fromDocuments  Default false.
         * @param  QueryInterface  $query
         */
        return (bool) apply_filters('meiliscout/hydrate_from_documents', false, $query);
    }
}
