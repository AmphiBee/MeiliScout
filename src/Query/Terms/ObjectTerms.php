<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Terms;

use Meilisearch\Client;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\IndexNames;
use Pollora\MeiliScout\Services\IndexSettings;

/**
 * The terms of the posts object_ids names, read from the posts index.
 *
 * Post documents carry their terms, by taxonomy (taxonomies.<taxonomy>).
 * That is only what MySQL's term_relationships holds when the index holds
 * every one of these posts: each must be of an indexed type, with a status
 * the index serves, and the queried taxonomies must be the posts'. Otherwise
 * the query runs on MySQL (unindexed_object).
 */
final class ObjectTerms
{
    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * The term ids of each object, in the queried taxonomies.
     *
     * @param  list<int>  $objectIds
     * @param  list<string>  $taxonomies
     * @return array<int, list<int>> By object id
     *
     * @throws UnsupportedQuery
     */
    public function of(array $objectIds, array $taxonomies): array
    {
        $this->assertPostTaxonomies($taxonomies);

        if (IndexNames::activeSchema('posts') < 3) {
            throw new UnsupportedQuery('schema_too_old');
        }

        $missing = IndexSettings::firstUncovered(IndexSettings::displayed(IndexNames::active('posts')), ['ID', 'taxonomies']);

        // Without them in the hits, every post would look like it has no term
        if ($missing !== null) {
            throw new UnsupportedQuery("undisplayed_attribute:{$missing}");
        }

        $posts = $this->posts($objectIds, $taxonomies);

        if ($posts === []) {
            return [];
        }

        $hits = $this->client->index(IndexNames::active('posts'))->search('', [
            'filter' => 'ID IN ['.implode(', ', array_keys($posts)).']',
            'limit' => count($posts),
            'attributesToRetrieve' => ['ID', 'taxonomies'],
        ])->getHits();

        $terms = [];

        foreach ($hits as $hit) {
            $ids = [];

            foreach ($taxonomies as $taxonomy) {
                foreach ((array) ($hit['taxonomies'][$taxonomy] ?? []) as $term) {
                    $ids[] = (int) ($term['term_id'] ?? 0);
                }
            }

            $terms[(int) $hit['ID']] = array_values(array_unique($ids));
        }

        return $terms;
    }

    /**
     * The objects that are posts, by id; one the index lacks sends the query to MySQL.
     *
     * An id that is no post has no terms of a post taxonomy.
     *
     * @param  list<int>  $objectIds
     * @param  list<string>  $taxonomies
     * @return array<int, true>
     *
     * @throws UnsupportedQuery
     */
    private function posts(array $objectIds, array $taxonomies): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter($objectIds, static fn (int $id) => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $rows = (array) $wpdb->get_results('SELECT ID, post_type, post_status FROM '.$wpdb->posts.' WHERE ID IN ('.implode(',', $ids).')');
        $types = (array) Settings::get('indexed_post_types', []);
        $statuses = PostIndexable::queryableStatuses();
        $posts = [];

        foreach ($rows as $row) {
            if (! in_array($row->post_type, $types, true) || ! in_array($row->post_status, $statuses, true)) {
                throw new UnsupportedQuery('unindexed_object:'.(int) $row->ID);
            }

            // Documents carry the terms of their type's taxonomies only
            foreach ($taxonomies as $taxonomy) {
                if (! is_object_in_taxonomy((string) $row->post_type, $taxonomy)) {
                    throw new UnsupportedQuery('unindexed_object:'.(int) $row->ID);
                }
            }

            $posts[(int) $row->ID] = true;
        }

        return $posts;
    }

    /**
     * The queried taxonomies must be named, and be the posts' only.
     *
     * @param  list<string>  $taxonomies
     *
     * @throws UnsupportedQuery
     */
    private function assertPostTaxonomies(array $taxonomies): void
    {
        if ($taxonomies === []) {
            throw new UnsupportedQuery('unindexed_object:any_taxonomy');
        }

        foreach ($taxonomies as $taxonomy) {
            $object = get_taxonomy($taxonomy);

            if (! $object instanceof \WP_Taxonomy) {
                throw new UnsupportedQuery('unindexed_object:'.$taxonomy);
            }

            foreach ((array) $object->object_type as $type) {
                if (! post_type_exists((string) $type)) {
                    throw new UnsupportedQuery('unindexed_object:'.$taxonomy);
                }
            }
        }
    }
}
