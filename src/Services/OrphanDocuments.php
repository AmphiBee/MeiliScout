<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Services;

use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Contracts\Indexable;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Indexables\TaxonomyIndexable;

/**
 * Deletes the documents whose post or term is gone.
 *
 * A full indexation without --clear only adds documents: a post deleted
 * without WordPress knowing (a database import or restore, a direct SQL
 * delete, a plugin bypassing wp_delete_post()) kept its document, searchable
 * and counted. At the end of such a run, the ids the index holds are checked
 * against the database, page by page, and the missing ones deleted.
 *
 * Checked against the database rather than the documents the run sent: a
 * chunked run spans several processes, and a post saved meanwhile is there.
 */
final class OrphanDocuments
{
    private const PAGE = 1000;

    /**
     * @var callable(Indexable, list<int>): list<int> Which of these ids the database still has, as indexable
     */
    private $existing;

    public function __construct(private Client $client, ?callable $existing = null)
    {
        $this->existing = $existing ?? [self::class, 'existingInDatabase'];
    }

    /**
     * @return int|null The number of documents deleted, null for an index of
     *                  another kind (an indexable added by meiliscout/indexables)
     */
    public function delete(Indexable $indexable, string $indexName): ?int
    {
        if (! $indexable instanceof PostIndexable && ! $indexable instanceof TaxonomyIndexable) {
            return null;
        }

        $index = $this->client->index($indexName);
        $primaryKey = $indexable->getPrimaryKey();
        $orphans = [];
        $offset = 0;

        do {
            $page = $index->getDocuments((new DocumentsQuery())->setFields([$primaryKey])->setLimit(self::PAGE)->setOffset($offset));
            $ids = array_values(array_map(fn (array $document) => (int) $document[$primaryKey], $page->getResults()));
            $offset += self::PAGE;

            if ($ids !== []) {
                array_push($orphans, ...array_diff($ids, ($this->existing)($indexable, $ids)));
            }
        } while ($offset < $page->getTotal());

        // Deleted once every page is read: deleting along would shift the offsets
        foreach (array_chunk($orphans, self::PAGE) as $chunk) {
            $index->deleteDocuments($chunk);
        }

        return count($orphans);
    }

    /**
     * Which of these ids the database has as something the indexable indexes:
     * posts of an indexed type with an indexable status, terms of an indexed taxonomy.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function existingInDatabase(Indexable $indexable, array $ids): array
    {
        global $wpdb;

        $in = static fn (array $values, string $format) => implode(', ', array_fill(0, count($values), $format));

        if ($indexable instanceof PostIndexable) {
            $types = array_values((array) Settings::get('indexed_post_types', []));
            $statuses = PostIndexable::indexableStatuses();

            if ($types === [] || $statuses === []) {
                return [];
            }

            $sql = "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$in($ids, '%d')}) AND post_type IN ({$in($types, '%s')}) AND post_status IN ({$in($statuses, '%s')})";

            return array_map('intval', $wpdb->get_col($wpdb->prepare($sql, ...$ids, ...$types, ...$statuses)));
        }

        $taxonomies = array_values((array) Settings::get('indexed_taxonomies', []));

        if ($taxonomies === []) {
            return [];
        }

        $sql = "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_id IN ({$in($ids, '%d')}) AND taxonomy IN ({$in($taxonomies, '%s')})";

        return array_map('intval', $wpdb->get_col($wpdb->prepare($sql, ...$ids, ...$taxonomies)));
    }
}
