<?php

declare(strict_types=1);

use Pollora\MeiliScout\Query\PhpOrder;
use Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder\MockWPQuery;

function hitsOf(array $ids, string $field = 'ID'): array
{
    return array_map(fn ($id) => ['ID' => $field === 'ID' ? $id : 0, $field => $id], $ids);
}

test('only rand and list orders on their own are put in order in PHP', function () {
    expect(PhpOrder::of(new MockWPQuery(['orderby' => 'rand']))?->kind)->toBe('rand')
        ->and(PhpOrder::of(new MockWPQuery(['orderby' => 'RAND(3)']))?->kind)->toBe('rand')
        ->and(PhpOrder::of(new MockWPQuery(['orderby' => ['post__in' => 'ASC'], 'post__in' => [1]]))?->kind)->toBe('post__in')
        ->and(PhpOrder::of(new MockWPQuery(['orderby' => 'post__in'])))->toBeNull()
        ->and(PhpOrder::of(new MockWPQuery(['orderby' => 'date'])))->toBeNull()
        ->and(PhpOrder::of(new MockWPQuery(['orderby' => ['rand' => 'ASC', 'date' => 'DESC']])))->toBeNull();
});

test('post__in orders by position in the list, as FIELD() does', function () {
    $order = PhpOrder::of(new MockWPQuery(['orderby' => 'post__in', 'post__in' => [5, '2', 9, 5]]));

    expect(array_column($order->sort(hitsOf([2, 9, 5])), 'ID'))->toBe([5, 2, 9]);
});

test('post_parent__in and post_name__in order by their field, which the hits must carry', function () {
    $parents = PhpOrder::of(new MockWPQuery(['orderby' => 'post_parent__in', 'post_parent__in' => [7, 3]]));
    $names = PhpOrder::of(new MockWPQuery(['orderby' => 'post_name__in', 'post_name__in' => ['b', 'a']]));

    expect($parents->attributes())->toBe(['post_parent'])
        ->and(array_column($parents->sort(hitsOf([3, 7, 3], 'post_parent')), 'post_parent'))->toBe([7, 3, 3])
        ->and($names->attributes())->toBe(['post_name'])
        ->and(array_column($names->sort(hitsOf(['a', 'b'], 'post_name')), 'post_name'))->toBe(['b', 'a']);
});

test('RAND(seed) always gives the same order', function () {
    $order = PhpOrder::of(new MockWPQuery(['orderby' => 'RAND(42)']));
    $hits = hitsOf(range(1, 30));

    expect($order->sort($hits))->toBe($order->sort($hits))
        ->and($order->sort($hits))->not->toBe($hits);
});
