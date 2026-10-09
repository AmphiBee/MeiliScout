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
     * The columns a WP_Post built from a document reads (post_password is never indexed).
     */
    public const POST_COLUMNS = [
        'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt',
        'post_status', 'comment_status', 'ping_status', 'post_name', 'to_ping', 'pinged', 'post_modified',
        'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type',
        'post_mime_type', 'comment_count',
    ];

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
        if (! apply_filters('meiliscout/hydrate_from_documents', false, $query)) {
            return false;
        }

        // An index returning only some fields (listings: public fields) would build incomplete posts: loaded from the database
        return self::canHydrate();
    }

    /**
     * Whether the posts index returns every column of a WP_Post.
     */
    public static function canHydrate(): bool
    {
        return IndexSettings::firstUncovered(IndexSettings::displayed(IndexNames::active('posts')), self::POST_COLUMNS) === null;
    }
}
