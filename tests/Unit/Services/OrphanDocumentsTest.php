<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Services {

    use Meilisearch\Client;
    use Meilisearch\Contracts\DocumentsQuery;
    use Meilisearch\Contracts\DocumentsResults;
    use Meilisearch\Endpoints\Indexes;
    use Pollora\MeiliScout\Contracts\Indexable;
    use Pollora\MeiliScout\Indexables\PostIndexable;
    use Pollora\MeiliScout\Indexables\TaxonomyIndexable;
    use Pollora\MeiliScout\Services\OrphanDocuments;

    /**
     * An index holding these ids, read 1000 at a time; records what is deleted.
     *
     * @param  list<int>  $ids
     */
    function indexHolding(array $ids, string $key, $test, ?array &$deleted = null, ?array &$reads = null): Indexes
    {
        $deleted = [];
        $reads = [];
        $index = (fn () => $this->createMock(Indexes::class))->call($test);
        $index->method('getDocuments')->willReturnCallback(function (DocumentsQuery $query) use ($ids, $key, &$reads) {
            $options = $query->toArray();
            $reads[] = $options;
            $page = array_slice($ids, $options['offset'], $options['limit']);

            return new DocumentsResults(['results' => array_map(fn ($id) => [$key => $id], $page), 'offset' => $options['offset'], 'limit' => $options['limit'], 'total' => count($ids)]);
        });
        $index->method('deleteDocuments')->willReturnCallback(function (array $chunk) use (&$deleted) {
            array_push($deleted, ...$chunk);

            return [];
        });

        return $index;
    }

    function clientFor(Indexes $index, $test): Client
    {
        $client = (fn () => $this->createMock(Client::class))->call($test);
        $client->method('index')->willReturn($index);

        return $client;
    }

    test('the documents of posts the database no longer has are deleted, every page read first', function () {
        $ids = range(1, 2500);
        $index = indexHolding($ids, 'ID', $this, $deleted, $reads);
        // The database lost 7, 1500 and 2499
        $existing = fn (Indexable $indexable, array $page) => array_values(array_diff($page, [7, 1500, 2499]));

        $count = (new OrphanDocuments(clientFor($index, $this), $existing))->delete(new PostIndexable, 'example_test_posts');

        expect($count)->toBe(3)
            ->and($deleted)->toBe([7, 1500, 2499])
            ->and(array_column($reads, 'offset'))->toBe([0, 1000, 2000])
            ->and($reads[0]['fields'])->toBe(['ID']);
    });

    test('terms are checked by term id', function () {
        $index = indexHolding([3, 4], 'term_id', $this, $deleted, $reads);
        $asked = null;
        $existing = function (Indexable $indexable, array $page) use (&$asked) {
            $asked = [$indexable::class, $page];

            return [4];
        };

        expect((new OrphanDocuments(clientFor($index, $this), $existing))->delete(new TaxonomyIndexable, 'example_test_taxonomies'))->toBe(1)
            ->and($deleted)->toBe([3])
            ->and($asked)->toBe([TaxonomyIndexable::class, [3, 4]])
            ->and($reads[0]['fields'])->toBe(['term_id']);
    });

    test('nothing gone, nothing deleted; an empty index is read once', function () {
        $index = indexHolding([1, 2], 'ID', $this, $deleted);
        $empty = indexHolding([], 'ID', $this, $nothing, $reads);
        $all = fn (Indexable $indexable, array $page) => $page;

        expect((new OrphanDocuments(clientFor($index, $this), $all))->delete(new PostIndexable, 'posts'))->toBe(0)
            ->and($deleted)->toBe([])
            ->and((new OrphanDocuments(clientFor($empty, $this), $all))->delete(new PostIndexable, 'posts'))->toBe(0)
            ->and($reads)->toHaveCount(1);
    });

    test('an index another plugin added is left alone', function () {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('index');

        expect((new OrphanDocuments($client, fn () => []))->delete($this->createMock(Indexable::class), 'other'))->toBeNull();
    });
}
