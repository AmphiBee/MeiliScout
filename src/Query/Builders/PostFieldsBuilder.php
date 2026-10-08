<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Query\Builders\Concerns\FormatsValues;
use Pollora\MeiliScout\Query\QueryVars;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * Filters on the post's own fields: id, slug, title, parent, author, password, comments, menu order.
 *
 * The arguments are read as WP_Query::get_posts() reads them, precedence
 * included: p wins over post__in, which wins over post__not_in; name over
 * pagename over post_name__in; author__not_in over author__in. By the time
 * posts_pre_query runs, WordPress has already turned page_id into p, author
 * into author__in and author__not_in, a post type's query var into name or
 * pagename, and sanitized the slugs; without WordPress, the same is done here.
 */
class PostFieldsBuilder implements QueryBuilderInterface
{
    use FormatsValues;

    /**
     * The arguments this builder translates, which need the fields of schema 3.
     */
    public const VARS = [
        'p', 'page_id', 'name', 'pagename', 'post__in', 'post__not_in', 'post_name__in',
        'post_parent', 'post_parent__in', 'post_parent__not_in',
        'author', 'author__in', 'author__not_in', 'author_name',
        'has_password', 'comment_count', 'menu_order',
        ...self::V4_VARS,
    ];

    /**
     * The arguments that need the posts index in schema 4, where their fields are filterable.
     */
    private const V4_VARS = ['title', 'comment_status', 'ping_status'];

    /**
     * @param  array<string, mixed>  $searchParams
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $filters = array_values(array_filter([
            $this->slug($query),
            $this->id($query),
            $this->parent($query),
            ...$this->author($query),
            $this->password($query),
            $this->commentCount($query),
            $this->menuOrder($query),
        ]));
        $v4Filters = array_values(array_filter([
            $this->title($query),
            $this->status($query, 'comment_status'),
            $this->status($query, 'ping_status'),
        ]));

        if ($v4Filters !== [] && IndexNames::activeSchema('posts') < 4) {
            throw new UnsupportedQuery('schema_too_old');
        }

        $filters = [...$filters, ...$v4Filters];

        if ($filters === []) {
            return;
        }

        if (IndexNames::activeSchema('posts') < 3) {
            throw new UnsupportedQuery('schema_too_old');
        }

        foreach ($filters as $filter) {
            $searchParams['filter'][] = $filter;
        }
    }

    /**
     * title: the title as a whole, compared as MySQL's collation compares it (case and accents aside).
     */
    private function title(QueryInterface $query): ?string
    {
        $title = $this->string($query->get('title'));

        if ($title === '') {
            return null;
        }

        // WP_Query compares the title unslashed
        return 'post_title_sort = '.$this->quote(PostIndexable::titleSortKey(stripslashes($title)));
    }

    /**
     * comment_status or ping_status: open or closed.
     */
    private function status(QueryInterface $query, string $field): ?string
    {
        $status = $this->string($query->get($field));

        return $status === '' ? null : "{$field} = ".$this->quote($status);
    }

    private function slug(QueryInterface $query): ?string
    {
        $name = $this->string($query->get('name'));
        $pagename = $this->string($query->get('pagename'));

        // WordPress turned pagename into the page's id, and its last segment into name
        if ($pagename !== '') {
            return $this->pagename($query, $pagename);
        }

        if ($name !== '') {
            return 'post_name = '.$this->quote($this->sanitizeSlug($name));
        }

        $names = $query->get('post_name__in');

        if (is_array($names) && $names !== []) {
            return 'post_name IN ['.implode(', ', array_map(fn ($name) => $this->quote($this->sanitizeSlug((string) $name)), array_values(array_unique($names)))).']';
        }

        return null;
    }

    /**
     * The page at a path, as WP_Query::get_posts() finds it: the queried object, or by path among hierarchical types.
     */
    private function pagename(QueryInterface $query, string $pagename): ?string
    {
        if (! function_exists('get_page_by_path')) {
            throw new UnsupportedQuery('unsupported_arg:pagename');
        }

        $wp = QueryVars::wp($query);

        if ($wp !== null && isset($wp->queried_object_id)) {
            $pageId = (int) $wp->queried_object_id;
        } else {
            $pageId = $this->pageByPath($query, $wp !== null ? $this->originalPath($wp) : $pagename);
        }

        // The page of the posts is the blog's home: no restriction
        $pageForPosts = (int) get_option('page_for_posts');
        if (get_option('show_on_front') === 'page' && $pageForPosts > 0 && $pageId === $pageForPosts) {
            return null;
        }

        return 'ID = '.$pageId;
    }

    /**
     * The path asked for: get_posts() leaves only its last segment in pagename.
     */
    private function originalPath(\WP_Query $wp): string
    {
        if (isset($wp->query['pagename']) && is_string($wp->query['pagename'])) {
            return $wp->query['pagename'];
        }

        // A hierarchical post type's own query var
        foreach ((array) $wp->get('post_type') as $type) {
            $object = get_post_type_object((string) $type);

            if ($object !== null && $object->hierarchical && is_string($object->query_var) && is_string($wp->query[$object->query_var] ?? null)) {
                return $wp->query[$object->query_var];
            }
        }

        throw new UnsupportedQuery('unsupported_arg:pagename');
    }

    private function pageByPath(QueryInterface $query, string $path): int
    {
        $postType = $query->get('post_type');

        if ($postType === 'page' || $postType === '' || $postType === null) {
            $page = get_page_by_path($path);

            return $page instanceof \WP_Post ? (int) $page->ID : 0;
        }

        foreach ((array) $postType as $type) {
            $object = get_post_type_object((string) $type);

            if ($object !== null && $object->hierarchical) {
                $page = get_page_by_path($path, OBJECT, (string) $type);

                if ($page instanceof \WP_Post) {
                    return (int) $page->ID;
                }
            }
        }

        return 0;
    }

    private function id(QueryInterface $query): ?string
    {
        $p = abs((int) $query->get('p'));

        // WordPress copies page_id into p
        if ($p === 0 && QueryVars::wp($query) === null) {
            $p = abs((int) $query->get('page_id'));
        }

        if ($p > 0) {
            return 'ID = '.$p;
        }

        $in = $query->get('post__in');

        if (! empty($in)) {
            return 'ID IN ['.implode(', ', $this->ids($in)).']';
        }

        $notIn = $query->get('post__not_in');

        if (! empty($notIn)) {
            return 'ID NOT IN ['.implode(', ', $this->ids($notIn)).']';
        }

        return null;
    }

    private function parent(QueryInterface $query): ?string
    {
        $parent = $query->get('post_parent');

        if (is_numeric($parent)) {
            return 'post_parent = '.(int) $parent;
        }

        $in = $query->get('post_parent__in');

        if (! empty($in)) {
            return 'post_parent IN ['.implode(', ', $this->ids($in)).']';
        }

        $notIn = $query->get('post_parent__not_in');

        if (! empty($notIn)) {
            return 'post_parent NOT IN ['.implode(', ', $this->ids($notIn)).']';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function author(QueryInterface $query): array
    {
        $in = (array) ($query->get('author__in') ?: []);
        $notIn = (array) ($query->get('author__not_in') ?: []);

        // WordPress spreads author over author__in and author__not_in
        $author = $query->get('author');
        if (QueryVars::wp($query) === null && ! empty($author)) {
            foreach (array_unique(array_map('intval', preg_split('/[,\s]+/', urldecode((string) $author)) ?: [])) as $id) {
                $id > 0 ? $in[] = $id : $notIn[] = abs($id);
            }
        }

        $filters = [];

        if ($notIn !== []) {
            $filters[] = 'post_author NOT IN ['.implode(', ', $this->ids($notIn)).']';
        } elseif ($in !== []) {
            $filters[] = 'post_author IN ['.implode(', ', $this->ids($in)).']';
        }

        $authorName = $this->string($query->get('author_name'));

        if ($authorName !== '') {
            if (str_contains($authorName, '/')) {
                $parts = explode('/', $authorName);
                $authorName = (string) (end($parts) ?: ($parts[count($parts) - 2] ?? ''));
            }

            $user = function_exists('get_user_by') ? get_user_by('slug', $this->sanitizeSlug($authorName)) : false;
            $filters[] = 'post_author = '.($user ? (int) $user->ID : 0);
        }

        return $filters;
    }

    private function password(QueryInterface $query): ?string
    {
        $hasPassword = $query->get('has_password');

        if ($hasPassword === null) {
            return null;
        }

        return 'has_password = '.($hasPassword ? 'true' : 'false');
    }

    private function commentCount(QueryInterface $query): ?string
    {
        $count = $query->get('comment_count');

        if ($count === null || $count === '') {
            return null;
        }

        if (is_numeric($count)) {
            $count = ['value' => (int) $count];
        }

        if (! is_array($count) || ! isset($count['value'])) {
            return null;
        }

        $compare = $count['compare'] ?? '=';
        $compare = in_array($compare, ['=', '!=', '>', '>=', '<', '<='], true) ? $compare : '=';

        return "comment_count {$compare} ".(int) $count['value'];
    }

    private function menuOrder(QueryInterface $query): ?string
    {
        $menuOrder = $query->get('menu_order');

        if ($menuOrder === null || $menuOrder === '') {
            return null;
        }

        if (! is_numeric($menuOrder)) {
            throw new UnsupportedQuery('unsupported_arg:menu_order');
        }

        return 'menu_order = '.(int) $menuOrder;
    }

    /**
     * Ids as WordPress reads them in these arguments: absolute integers, once each.
     *
     * @return list<int>
     */
    private function ids(mixed $ids): array
    {
        $ids = is_array($ids) ? $ids : (preg_split('/[,\s]+/', (string) $ids) ?: []);

        return array_values(array_unique(array_map(static fn ($id) => abs((int) $id), $ids)));
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function sanitizeSlug(string $slug): string
    {
        return function_exists('sanitize_title_for_query') ? sanitize_title_for_query($slug) : $slug;
    }
}
