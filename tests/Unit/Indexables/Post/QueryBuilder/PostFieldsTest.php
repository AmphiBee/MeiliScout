<?php

declare(strict_types=1);

namespace {
    if (! function_exists('get_user_by')) {
        function get_user_by($field, $value) { return $GLOBALS['users'][$field][$value] ?? false; }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder {

    use Pollora\MeiliScout\Query\MeiliQueryBuilder;
    use Pollora\MeiliScout\Query\UnsupportedQuery;

    function fieldFilter(array $vars): string
    {
        $filter = (new MeiliQueryBuilder)->build(new MockWPQuery($vars))['filter'];

        return substr($filter, strlen("post_type = 'post' AND post_status = 'publish'"));
    }

    beforeEach(function () {
        $GLOBALS['wp_options'] = [];
        $GLOBALS['users'] = [];
    });

    test('a post by id: p, or page_id', function () {
        expect(fieldFilter(['p' => 12]))->toBe(' AND ID = 12')
            ->and(fieldFilter(['page_id' => 7]))->toBe(' AND ID = 7');
    });

    test('p wins over post__in, which wins over post__not_in, as in WordPress', function () {
        expect(fieldFilter(['p' => 3, 'post__in' => [1, 2]]))->toBe(' AND ID = 3')
            ->and(fieldFilter(['post__in' => [2, '1', 2, -4], 'post__not_in' => [9]]))->toBe(' AND ID IN [2, 1, 4]')
            ->and(fieldFilter(['post__not_in' => [9, 8]]))->toBe(' AND ID NOT IN [9, 8]')
            ->and(fieldFilter(['post__in' => []]))->toBe('');
    });

    test('slugs: name, else post_name__in', function () {
        expect(fieldFilter(['name' => 'hello-world', 'post_name__in' => ['a']]))->toBe(" AND post_name = 'hello-world'")
            ->and(fieldFilter(['post_name__in' => ['a', 'b', 'a']]))->toBe(" AND post_name IN ['a', 'b']");
    });

    test('parents: post_parent, 0 included, else __in, else __not_in', function () {
        expect(fieldFilter(['post_parent' => 0]))->toBe(' AND post_parent = 0')
            ->and(fieldFilter(['post_parent' => '5', 'post_parent__in' => [1]]))->toBe(' AND post_parent = 5')
            ->and(fieldFilter(['post_parent' => '', 'post_parent__in' => [1, 2]]))->toBe(' AND post_parent IN [1, 2]')
            ->and(fieldFilter(['post_parent__not_in' => [3]]))->toBe(' AND post_parent NOT IN [3]');
    });

    test('authors: a list with exclusions, which win, as in WordPress', function () {
        expect(fieldFilter(['author' => '3,4']))->toBe(' AND post_author IN [3, 4]')
            ->and(fieldFilter(['author' => '-3']))->toBe(' AND post_author NOT IN [3]')
            ->and(fieldFilter(['author' => '4,-3']))->toBe(' AND post_author NOT IN [3]')
            ->and(fieldFilter(['author__in' => [4], 'author__not_in' => [5]]))->toBe(' AND post_author NOT IN [5]')
            ->and(fieldFilter(['author' => 0]))->toBe('');
    });

    test('author_name is the author with that slug, or no post', function () {
        $GLOBALS['users']['slug']['camille'] = (object) ['ID' => 6];

        expect(fieldFilter(['author_name' => 'camille']))->toBe(' AND post_author = 6')
            ->and(fieldFilter(['author_name' => 'authors/camille/']))->toBe(' AND post_author = 6')
            ->and(fieldFilter(['author_name' => 'nobody']))->toBe(' AND post_author = 0');
    });

    test('has_password, comment_count and menu_order', function () {
        expect(fieldFilter(['has_password' => true]))->toBe(' AND has_password = true')
            ->and(fieldFilter(['has_password' => false]))->toBe(' AND has_password = false')
            ->and(fieldFilter(['comment_count' => 2]))->toBe(' AND comment_count = 2')
            ->and(fieldFilter(['comment_count' => ['value' => 2, 'compare' => '>=']]))->toBe(' AND comment_count >= 2')
            ->and(fieldFilter(['comment_count' => ['value' => 2, 'compare' => 'LIKE']]))->toBe(' AND comment_count = 2')
            ->and(fieldFilter(['menu_order' => 0]))->toBe(' AND menu_order = 0');
    });

    test('indexes built before schema 3 lack these fields: the query runs on MySQL', function () {
        update_option('meiliscout/schema_version', 2);

        expect(fn () => fieldFilter(['post__in' => [1]]))->toThrow(UnsupportedQuery::class, 'schema_too_old')
            ->and(fieldFilter(['post__in' => []]))->toBe('');
    });

    test('title, comment_status and ping_status, from schema 4', function () {
        expect(fieldFilter(['title' => 'Hello World ']))->toBe(" AND post_title_sort = 'hello world'")
            ->and(fieldFilter(['title' => "L\\'ete"]))->toBe(" AND post_title_sort = 'l\\'ete'")
            ->and(fieldFilter(['comment_status' => 'open']))->toBe(" AND comment_status = 'open'")
            ->and(fieldFilter(['ping_status' => 'closed']))->toBe(" AND ping_status = 'closed'");

        update_option('meiliscout/schema_version', 3);

        expect(fn () => fieldFilter(['title' => 'Hello']))->toThrow(UnsupportedQuery::class, 'schema_too_old');
    });

    test('post_mime_type, as wp_post_mime_type_where() reads it', function () {
        expect(fieldFilter(['post_mime_type' => 'image']))->toBe(" AND (mime_group = 'image')")
            ->and(fieldFilter(['post_mime_type' => 'image/*']))->toBe(" AND (mime_group = 'image')")
            ->and(fieldFilter(['post_mime_type' => 'application/pdf, image/png']))->toBe(" AND (post_mime_type = 'application/pdf' OR post_mime_type = 'image/png')")
            ->and(fieldFilter(['post_mime_type' => ['*/svg+xml']]))->toBe(" AND (mime_subgroup = 'svg+xml')")
            ->and(fieldFilter(['post_mime_type' => '*']))->toBe('')
            ->and(fn () => fieldFilter(['post_mime_type' => 'im*ge/jpeg']))->toThrow(UnsupportedQuery::class, 'unsupported_arg:post_mime_type');
    });

    test('an attachment by slug or id, as WordPress turns it into name or p', function () {
        expect(fieldFilter(['attachment' => 'photo']))->toBe(" AND post_name = 'photo'")
            ->and(fieldFilter(['attachment_id' => 9]))->toBe(' AND ID = 9')
            ->and(fieldFilter(['subpost_id' => 4]))->toBe(' AND ID = 4');
    });
}
