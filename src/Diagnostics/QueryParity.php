<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Diagnostics;

use Pollora\MeiliScout\Config\Settings;
use Pollora\MeiliScout\Services\SearchFallbacks;
use WP_Post;
use WP_Query;

/**
 * Runs a WP_Query on MySQL then with use_meilisearch, and compares the two.
 *
 * MySQL is the oracle: a query Meilisearch serves must return the same posts,
 * in the same order when it matters, with the same totals. A query Meilisearch
 * does not serve must say why. The built-in cases pick their posts, terms,
 * authors and meta keys in the site's own data, so they run on any site.
 *
 * Outcomes:
 *   OK        served by Meilisearch, same result
 *   DIFF      served by Meilisearch, different result
 *   FALLBACK  served by MySQL although use_meilisearch was set; the reason is given
 *   INFO      a search: the two engines rank differently by nature, the overlap is reported
 *   SKIP      the site lacks the data the case needs
 *   ERROR     an exception reached the caller
 */
final class QueryParity
{
    public const OK = 'OK';

    public const DIFF = 'DIFF';

    public const FALLBACK = 'FALLBACK';

    public const INFO = 'INFO';

    public const SKIP = 'SKIP';

    public const ERROR = 'ERROR';

    /**
     * Same posts in the same order.
     */
    public const MODE_ORDER = 'order';

    /**
     * Same posts, in any order.
     */
    public const MODE_SET = 'set';

    /**
     * Same number of posts and the same total.
     */
    public const MODE_COUNT = 'count';

    /**
     * A search: the overlap is reported, nothing is compared strictly.
     */
    public const MODE_SEARCH = 'search';

    /**
     * Same sort keys in the same order: posts that tie may come in any order, on MySQL too.
     */
    public const MODE_SORTED = 'sorted';

    /**
     * Fields of the first WP_Post compared between the two engines.
     */
    private const POST_FIELDS = [
        'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_title', 'post_status', 'post_name',
        'post_modified', 'post_parent', 'menu_order', 'post_type', 'comment_count', 'filter', 'post_content',
    ];

    /**
     * The built-in cases, with the site's data filled in.
     *
     * A case whose data is missing has null args, and is skipped.
     *
     * @return list<array{label: string, args: array<string, mixed>|null, mode: string, keys: list<string>}>
     */
    public static function cases(): array
    {
        $d = SiteData::collect();
        $case = static fn (string $label, ?array $args, string $mode = self::MODE_ORDER, array $keys = []): array => ['label' => $label, 'args' => $args, 'mode' => $mode, 'keys' => $keys];
        $sorted = static fn (string $label, ?array $args, string ...$keys): array => $case($label, $args, self::MODE_SORTED, $keys);
        $when = static fn (bool $ok, array $args): ?array => $ok ? $args : null;

        $posts = $d['posts'];
        $hasPosts = count($posts) >= 10;
        $cat = $d['category'];
        $cat2 = $d['category2'];
        $tag = $d['tag'];
        $tag2 = $d['tag2'];
        $type = $d['custom_type'];
        $num = $d['numeric_meta'];
        $str = $d['string_meta'];
        $date = $d['date_meta'];
        $year = $d['year'];
        $month = $d['month'];

        return [
            // Types and statuses
            $case('default (post, publish)', []),
            $case('post_type page', ['post_type' => 'page']),
            $case('post_type custom', $when($type !== null, ['post_type' => $type])),
            $case('post_type array', $when($type !== null, ['post_type' => ['post', $type]])),
            $case('post_type any', ['post_type' => 'any']),
            $case('post_status publish', ['post_status' => 'publish']),
            $case('post_status any', ['post_status' => 'any']),
            $case('post_status draft', ['post_status' => 'draft']),
            $case('post_status private', ['post_status' => 'private']),
            $case('post_status comma list', ['post_status' => 'publish,private']),
            $case('logged in: default statuses (private included)', $when($d['admin'] > 0, ['_user' => $d['admin']])),
            $case('logged in, own private posts only', $when($d['writer'] > 0, ['_user' => $d['writer'], 'post_type' => ['post', 'page'], 'posts_per_page' => -1]), self::MODE_SET),

            // Single post, slugs
            $case('p', $when($hasPosts, ['p' => $posts[5] ?? 0])),
            $case('p, a private post, logged out', $when($d['private_post'] > 0, ['p' => $d['private_post']])),
            $case('p, a private post, logged in', $when($d['private_post'] > 0 && $d['admin'] > 0, ['p' => $d['private_post'], '_user' => $d['admin']])),
            $case('name', $when($d['post_name'] !== null, ['name' => $d['post_name']])),
            $case('pagename (hierarchical path)', $when($d['page_path'] !== null, ['pagename' => $d['page_path']])),
            $case('page_id', $when($d['child_page'] > 0, ['page_id' => $d['child_page']])),
            $case('post__in', $when($hasPosts, ['post__in' => [$posts[3] ?? 0, $posts[1] ?? 0, $posts[8] ?? 0]])),
            $case('post__in + orderby post__in', $when($hasPosts, ['post__in' => [$posts[3] ?? 0, $posts[1] ?? 0, $posts[8] ?? 0], 'orderby' => 'post__in'])),
            $case('post__not_in', $when($hasPosts, ['post__not_in' => [$posts[0] ?? 0, $posts[1] ?? 0]])),
            $case('post_name__in', $when($d['post_name'] !== null && $d['post_name2'] !== null, ['post_name__in' => [$d['post_name'], $d['post_name2']]])),
            $case('post__in empty array (no restriction)', ['post__in' => []]),

            // Parents
            $case('post_parent', $when($d['parent_page'] > 0, ['post_type' => 'page', 'post_parent' => $d['parent_page']])),
            $case('post_parent 0 (top level)', ['post_type' => 'page', 'post_parent' => 0]),
            $case('post_parent__in', $when($d['parent_page'] > 0, ['post_type' => 'page', 'post_parent__in' => array_filter([$d['parent_page'], $d['other_parent']])])),
            $case('post_parent__not_in', $when($d['parent_page'] > 0, ['post_type' => 'page', 'post_parent__not_in' => [$d['parent_page']]])),

            // Authors
            $case('author', $when($d['author'] > 0, ['author' => $d['author']])),
            $case('author negative (exclude)', $when($d['author'] > 0, ['author' => -$d['author']])),
            $case('author comma list', $when($d['author'] > 0 && $d['author2'] > 0, ['author' => "{$d['author']},{$d['author2']}"])),
            $case('author_name', $when($d['author_nicename'] !== null, ['author_name' => $d['author_nicename']])),
            $case('author__in', $when($d['author'] > 0, ['author__in' => [$d['author']]])),
            $case('author__not_in', $when($d['author'] > 0, ['author__not_in' => [$d['author']]])),

            // Categories and tags
            $case('cat', $when($cat !== null, ['cat' => $cat['id'] ?? 0])),
            $case('cat negative', $when($cat !== null, ['cat' => -($cat['id'] ?? 0)])),
            $case('cat parent (includes children)', $when($d['parent_category'] > 0, ['cat' => $d['parent_category']])),
            $case('category_name', $when($cat !== null, ['category_name' => $cat['slug'] ?? ''])),
            $case('category_name a,b (OR)', $when($cat !== null && $cat2 !== null, ['category_name' => ($cat['slug'] ?? '').','.($cat2['slug'] ?? '')])),
            $case('category_name a+b (AND)', $when($cat !== null && $cat2 !== null, ['category_name' => ($cat['slug'] ?? '').'+'.($cat2['slug'] ?? '')])),
            $case('category__in', $when($cat !== null && $cat2 !== null, ['category__in' => [$cat['id'] ?? 0, $cat2['id'] ?? 0]])),
            $case('category__and', $when($cat !== null && $cat2 !== null, ['category__and' => [$cat['id'] ?? 0, $cat2['id'] ?? 0]])),
            $case('category__not_in', $when($cat !== null, ['category__not_in' => [$cat['id'] ?? 0]])),
            $case('tag', $when($tag !== null, ['tag' => $tag['slug'] ?? ''])),
            $case('tag a,b', $when($tag !== null && $tag2 !== null, ['tag' => ($tag['slug'] ?? '').','.($tag2['slug'] ?? '')])),
            $case('tag a+b', $when($tag !== null && $tag2 !== null, ['tag' => ($tag['slug'] ?? '').'+'.($tag2['slug'] ?? '')])),
            $case('tag_id', $when($tag !== null, ['tag_id' => $tag['id'] ?? 0])),
            $case('tag__in', $when($tag !== null && $tag2 !== null, ['tag__in' => [$tag['id'] ?? 0, $tag2['id'] ?? 0]])),
            $case('tag__and', $when($tag !== null && $tag2 !== null, ['tag__and' => [$tag['id'] ?? 0, $tag2['id'] ?? 0]])),
            $case('tag__not_in', $when($tag !== null, ['tag__not_in' => [$tag['id'] ?? 0]])),
            $case('tag_slug__in', $when($tag !== null && $tag2 !== null, ['tag_slug__in' => [$tag['slug'] ?? '', $tag2['slug'] ?? '']])),
            $case('tag_slug__and', $when($tag !== null && $tag2 !== null, ['tag_slug__and' => [$tag['slug'] ?? '', $tag2['slug'] ?? '']])),
            $case('custom taxonomy query var', $when($d['custom_term'] !== null, ['post_type' => $type, ($d['custom_term']['query_var'] ?? '') => $d['custom_term']['slug'] ?? ''])),
            $case('custom taxonomy, no post_type', $when($d['custom_term'] !== null, ['tax_query' => [['taxonomy' => $d['custom_term']['taxonomy'] ?? '', 'field' => 'slug', 'terms' => $d['custom_term']['slug'] ?? '']]])),

            // tax_query
            $case('tax_query slug IN', $when($cat !== null, ['tax_query' => [['taxonomy' => 'category', 'field' => 'slug', 'terms' => [$cat['slug'] ?? '']]]])),
            $case('tax_query term_id', $when($cat !== null, ['tax_query' => [['taxonomy' => 'category', 'terms' => $cat['id'] ?? 0]]])),
            $case('tax_query name', $when($cat !== null, ['tax_query' => [['taxonomy' => 'category', 'field' => 'name', 'terms' => $cat['name'] ?? '']]])),
            $case('tax_query term_taxonomy_id', $when($cat !== null, ['tax_query' => [['taxonomy' => 'category', 'field' => 'term_taxonomy_id', 'terms' => $cat['tt_id'] ?? 0]]])),
            $case('tax_query NOT IN', $when($tag !== null, ['tax_query' => [['taxonomy' => 'post_tag', 'field' => 'slug', 'terms' => [$tag['slug'] ?? ''], 'operator' => 'NOT IN']]])),
            $case('tax_query lowercase not in', $when($tag !== null, ['tax_query' => [['taxonomy' => 'post_tag', 'field' => 'slug', 'terms' => [$tag['slug'] ?? ''], 'operator' => 'not in']]])),
            $case('tax_query AND', $when($tag !== null && $tag2 !== null, ['tax_query' => [['taxonomy' => 'post_tag', 'field' => 'slug', 'terms' => [$tag['slug'] ?? '', $tag2['slug'] ?? ''], 'operator' => 'AND']]])),
            $case('tax_query EXISTS', $when($d['custom_term'] !== null, ['post_type' => $type, 'tax_query' => [['taxonomy' => $d['custom_term']['taxonomy'] ?? '', 'operator' => 'EXISTS']]])),
            $case('tax_query NOT EXISTS', $when($d['custom_term'] !== null, ['post_type' => ['post', $type], 'tax_query' => [['taxonomy' => $d['custom_term']['taxonomy'] ?? '', 'operator' => 'NOT EXISTS']]])),
            $case('tax_query relation OR', $when($cat !== null && $tag !== null, ['tax_query' => ['relation' => 'OR', ['taxonomy' => 'category', 'field' => 'slug', 'terms' => $cat['slug'] ?? ''], ['taxonomy' => 'post_tag', 'field' => 'slug', 'terms' => $tag['slug'] ?? '']]])),
            $case('tax_query nested', $when($cat !== null && $cat2 !== null && $tag !== null && $tag2 !== null, ['tax_query' => ['relation' => 'AND', ['taxonomy' => 'category', 'field' => 'slug', 'terms' => [$cat['slug'] ?? '', $cat2['slug'] ?? '']], ['relation' => 'OR', ['taxonomy' => 'post_tag', 'field' => 'slug', 'terms' => $tag['slug'] ?? ''], ['taxonomy' => 'post_tag', 'field' => 'slug', 'terms' => $tag2['slug'] ?? '']]]])),
            $case('tax_query parent, include_children default', $when($d['parent_category'] > 0, ['tax_query' => [['taxonomy' => 'category', 'terms' => $d['parent_category']]]])),
            $case('tax_query parent, include_children false', $when($d['parent_category'] > 0, ['tax_query' => [['taxonomy' => 'category', 'terms' => $d['parent_category'], 'include_children' => false]]])),
            $case('tax_query unknown term', ['tax_query' => [['taxonomy' => 'category', 'field' => 'slug', 'terms' => 'no-such-term-'.md5('x')]]]),
            $case('tax_query empty terms', ['tax_query' => [['taxonomy' => 'category', 'terms' => []]]]),

            // Metas
            $case('meta_key only (exists)', $when($num !== null, ['post_type' => $num['type'] ?? 'post', 'meta_key' => $num['key'] ?? ''])),
            $case('meta_key + meta_value', $when($str !== null, ['post_type' => $str['type'] ?? 'post', 'meta_key' => $str['key'] ?? '', 'meta_value' => $str['values'][0] ?? ''])),
            $case('meta_key + meta_value + compare !=', $when($str !== null, ['post_type' => $str['type'] ?? 'post', 'meta_key' => $str['key'] ?? '', 'meta_value' => $str['values'][0] ?? '', 'meta_compare' => '!='])),
            $case('meta_value_num + compare >', $when($num !== null, ['post_type' => $num['type'] ?? 'post', 'meta_key' => $num['key'] ?? '', 'meta_value_num' => $num['median'] ?? 0, 'meta_compare' => '>'])),
            $case('meta_query = string', $when($str !== null, ['post_type' => $str['type'] ?? 'post', 'meta_query' => [['key' => $str['key'] ?? '', 'value' => $str['values'][0] ?? '']]])),
            $case('meta_query != string', $when($str !== null, ['post_type' => $str['type'] ?? 'post', 'meta_query' => [['key' => $str['key'] ?? '', 'value' => $str['values'][0] ?? '', 'compare' => '!=']]])),
            $case('meta_query > NUMERIC', $when($num !== null, ['post_type' => $num['type'] ?? 'post', 'meta_query' => [['key' => $num['key'] ?? '', 'value' => $num['median'] ?? 0, 'compare' => '>', 'type' => 'NUMERIC']]])),
            $case('meta_query lowercase numeric type', $when($num !== null, ['post_type' => $num['type'] ?? 'post', 'meta_query' => [['key' => $num['key'] ?? '', 'value' => $num['median'] ?? 0, 'compare' => '>', 'type' => 'numeric']]])),
            $case('meta_query BETWEEN NUMERIC', $when($num !== null, ['post_type' => $num['type'] ?? 'post', 'meta_query' => [['key' => $num['key'] ?? '', 'value' => [$num['low'] ?? 0, $num['high'] ?? 0], 'compare' => 'BETWEEN', 'type' => 'NUMERIC']]])),
            $case('meta_query NOT BETWEEN NUMERIC', $when($num !== null, ['post_type' => $num['type'] ?? 'post', 'meta_query' => [['key' => $num['key'] ?? '', 'value' => [$num['low'] ?? 0, $num['high'] ?? 0], 'compare' => 'NOT BETWEEN', 'type' => 'NUMERIC']]])),
            $case('meta_query IN', $when($str !== null, ['post_type' => $str['type'] ?? 'post', 'meta_query' => [['key' => $str['key'] ?? '', 'value' => $str['values'] ?? [], 'compare' => 'IN']]])),
            $case('meta_query NOT IN', $when($str !== null, ['post_type' => $str['type'] ?? 'post', 'meta_query' => [['key' => $str['key'] ?? '', 'value' => $str['values'] ?? [], 'compare' => 'NOT IN']]])),
            $case('meta_query lowercase not in', $when($str !== null, ['post_type' => $str['type'] ?? 'post', 'meta_query' => [['key' => $str['key'] ?? '', 'value' => $str['values'] ?? [], 'compare' => 'not in']]])),
            $case('meta_query LIKE', $when($str !== null, ['post_type' => $str['type'] ?? 'post', 'meta_query' => [['key' => $str['key'] ?? '', 'value' => $str['fragment'] ?? '', 'compare' => 'LIKE']]])),
            $case('meta_query NOT LIKE', $when($str !== null, ['post_type' => $str['type'] ?? 'post', 'meta_query' => [['key' => $str['key'] ?? '', 'value' => $str['fragment'] ?? '', 'compare' => 'NOT LIKE']]])),
            $case('meta_query EXISTS', $when($date !== null, ['post_type' => $date['type'] ?? 'post', 'meta_query' => [['key' => $date['key'] ?? '', 'compare' => 'EXISTS']]])),
            $case('meta_query NOT EXISTS', $when($date !== null, ['post_type' => $date['type'] ?? 'post', 'meta_query' => [['key' => $date['key'] ?? '', 'compare' => 'NOT EXISTS']]])),
            $case('meta_query DATE >=', $when($date !== null, ['post_type' => $date['type'] ?? 'post', 'meta_query' => [['key' => $date['key'] ?? '', 'value' => $date['median'] ?? '', 'compare' => '>=', 'type' => 'DATE']]])),
            $case('meta_query REGEXP', $when($str !== null, ['post_type' => $str['type'] ?? 'post', 'meta_query' => [['key' => $str['key'] ?? '', 'value' => '^'.mb_substr((string) ($str['values'][0] ?? 'a'), 0, 1), 'compare' => 'REGEXP']]])),
            $case('meta_query relation OR', $when($num !== null && $str !== null && $num['type'] === $str['type'], ['post_type' => $num['type'] ?? 'post', 'meta_query' => ['relation' => 'OR', ['key' => $num['key'] ?? '', 'value' => $num['high'] ?? 0, 'compare' => '>', 'type' => 'NUMERIC'], ['key' => $str['key'] ?? '', 'value' => $str['values'][0] ?? '']]])),
            $case('meta_query nested', $when($num !== null && $str !== null && $num['type'] === $str['type'], ['post_type' => $num['type'] ?? 'post', 'meta_query' => ['relation' => 'AND', ['key' => $num['key'] ?? '', 'value' => $num['low'] ?? 0, 'compare' => '>', 'type' => 'NUMERIC'], ['relation' => 'OR', ['key' => $str['key'] ?? '', 'value' => $str['values'][0] ?? ''], ['key' => $str['key'] ?? '', 'value' => $str['values'][1] ?? '']]]])),
            $case('meta_query on a key not indexed', $when($d['unindexed_meta'] !== null, ['post_type' => $d['unindexed_meta']['type'] ?? 'post', 'meta_query' => [['key' => $d['unindexed_meta']['key'] ?? '', 'compare' => 'EXISTS']]])),
            $case('meta_query boolean value', $when($num !== null, ['post_type' => $num['type'] ?? 'post', 'meta_query' => [['key' => $num['key'] ?? '', 'value' => true]]])),

            // Dates
            $case('year', $when($year > 0, ['year' => $year])),
            $case('year + monthnum', $when($year > 0, ['year' => $year, 'monthnum' => $month])),
            $case('m=YYYYMM', $when($year > 0, ['m' => sprintf('%04d%02d', $year, $month)])),
            $case('w (week)', $when($year > 0, ['year' => $year, 'w' => $d['week']])),
            $case('day', $when($year > 0, ['year' => $year, 'monthnum' => $month, 'day' => $d['day']])),
            $case('date_query after string', ['date_query' => [['after' => '6 months ago']]]),
            $case('date_query before array', $when($year > 0, ['date_query' => [['before' => ['year' => $year, 'month' => 6, 'day' => 1]]]])),
            $case('date_query after+before inclusive', $when($year > 0, ['date_query' => [['after' => "{$year}-01-01", 'before' => "{$year}-12-31", 'inclusive' => true]]])),
            $case('date_query column post_modified', ['date_query' => [['column' => 'post_modified', 'after' => '1 day ago']]]),
            $case('date_query dayofweek', ['date_query' => [['dayofweek' => [1, 7]]]]),
            $case('date_query hour', ['date_query' => [['hour' => 9, 'compare' => '>=']]]),
            $case('date_query relation OR', $when($year > 0, ['date_query' => ['relation' => 'OR', ['year' => $year], ['year' => $year + 1, 'month' => 1]]])),

            // Search
            $case('s single word', $when($d['search_word'] !== null, ['s' => $d['search_word']]), self::MODE_SEARCH),
            $case('s two words', $when($d['search_phrase'] !== null, ['s' => $d['search_phrase']]), self::MODE_SEARCH),
            $case('s exclusion -word', $when($d['search_word'] !== null, ['s' => $d['search_word'].' -zzzz']), self::MODE_SEARCH),
            $case('s exact', $when($d['search_word'] !== null, ['s' => $d['search_word'], 'exact' => true]), self::MODE_SEARCH),
            $case('s sentence', $when($d['search_phrase'] !== null, ['s' => $d['search_phrase'], 'sentence' => true]), self::MODE_SEARCH),
            $case('s + orderby date', $when($d['search_word'] !== null, ['s' => $d['search_word'], 'orderby' => 'date']), self::MODE_SEARCH),
            $case('s + post_type any', $when($d['search_word'] !== null, ['s' => $d['search_word'], 'post_type' => 'any']), self::MODE_SEARCH),
            $case('s + search_columns title', $when($d['search_word'] !== null, ['s' => $d['search_word'], 'search_columns' => ['post_title']]), self::MODE_SEARCH),

            // Order
            $sorted('orderby title ASC', ['orderby' => 'title', 'order' => 'ASC'], 'title'),
            $sorted('orderby title DESC, all', ['orderby' => 'title', 'posts_per_page' => -1], 'title'),
            $sorted('orderby name', ['orderby' => 'name', 'order' => 'ASC'], 'post_name'),
            $sorted('orderby date ASC', ['orderby' => 'date', 'order' => 'ASC'], 'post_date'),
            $sorted('orderby modified', ['orderby' => 'modified'], 'post_modified'),
            $case('orderby ID', ['orderby' => 'ID', 'order' => 'ASC']),
            $sorted('orderby author', ['orderby' => 'author'], 'post_author'),
            $case('orderby author, ID (no tie)', ['orderby' => ['author' => 'DESC', 'ID' => 'ASC']]),
            $sorted('orderby menu_order (pages)', ['post_type' => 'page', 'orderby' => 'menu_order', 'order' => 'ASC'], 'menu_order'),
            $sorted('orderby parent', ['post_type' => 'page', 'orderby' => 'parent', 'order' => 'ASC'], 'post_parent'),
            $sorted('orderby comment_count', ['orderby' => 'comment_count'], 'comment_count'),
            $sorted('orderby type', ['post_type' => 'any', 'orderby' => 'type'], 'post_type'),
            $case('orderby rand', ['orderby' => 'rand', 'ignore_sticky_posts' => true], self::MODE_COUNT),
            $case('orderby rand, all on one page', ['orderby' => 'rand', 'post_type' => 'page', 'posts_per_page' => -1], self::MODE_SET),
            $sorted('orderby array', ['orderby' => ['menu_order' => 'ASC', 'title' => 'DESC'], 'post_type' => 'page'], 'menu_order', 'title'),
            $sorted('orderby meta_value_num', $when($num !== null, ['post_type' => $num['type'] ?? 'post', 'meta_key' => $num['key'] ?? '', 'orderby' => 'meta_value_num', 'order' => 'ASC']), 'meta:'.($num['key'] ?? '')),
            $case('orderby meta_value', $when($str !== null, ['post_type' => $str['type'] ?? 'post', 'meta_key' => $str['key'] ?? '', 'orderby' => 'meta_value', 'order' => 'ASC']), self::MODE_SET),
            $sorted('orderby named meta clause', $when($num !== null, ['post_type' => $num['type'] ?? 'post', 'meta_query' => ['num_clause' => ['key' => $num['key'] ?? '', 'compare' => 'EXISTS', 'type' => 'NUMERIC']], 'orderby' => 'num_clause', 'order' => 'DESC']), 'meta:'.($num['key'] ?? '')),
            // Without a type, MySQL sorts numbers as text; Meilisearch sorts them as numbers
            $case('orderby named meta clause, no type', $when($num !== null, ['post_type' => $num['type'] ?? 'post', 'meta_query' => ['num_clause' => ['key' => $num['key'] ?? '', 'compare' => 'EXISTS']], 'orderby' => 'num_clause', 'order' => 'DESC', 'posts_per_page' => -1]), self::MODE_SET),
            // Unordered: which sticky posts are already on the page varies, and with it post_count
            $case('orderby none', ['orderby' => 'none', 'ignore_sticky_posts' => true], self::MODE_COUNT),
            $case('orderby relevance without s', ['orderby' => 'relevance']),

            // Paging and shape
            $case('posts_per_page 5 paged 3', ['posts_per_page' => 5, 'paged' => 3]),
            $case('offset 7', ['posts_per_page' => 5, 'offset' => 7]),
            $case('offset with paged (offset wins)', ['posts_per_page' => 5, 'offset' => 2, 'paged' => 4]),
            $case('posts_per_page -1', ['posts_per_page' => -1]),
            $case('nopaging', ['nopaging' => true, 'post_type' => 'page']),
            $case('fields ids', ['fields' => 'ids']),
            $case('fields ids paged', ['fields' => 'ids', 'posts_per_page' => 5, 'paged' => 2]),
            $case('fields id=>parent', ['fields' => 'id=>parent', 'post_type' => 'page']),
            $case('no_found_rows', ['no_found_rows' => true]),
            $case('sticky posts on (is_home only in WP)', ['ignore_sticky_posts' => false]),
            $case('has_password false', ['has_password' => false]),
            $case('has_password true', ['has_password' => true]),
            $case('comment_count arg', ['comment_count' => ['value' => 2, 'compare' => '>=']]),
            $case('page beyond the results', ['posts_per_page' => 10, 'paged' => 999]),
        ];
    }

    /**
     * Runs the built-in cases, or the ones whose label contains $filter.
     *
     * @return list<array<string, mixed>>
     */
    public static function runCases(?string $filter = null): array
    {
        $results = [];

        foreach (self::cases() as $case) {
            if ($filter !== null && $filter !== '' && stripos($case['label'], $filter) === false) {
                continue;
            }

            $results[] = $case['args'] === null
                ? ['case' => $case['label'], 'outcome' => self::SKIP, 'notes' => ['the site lacks the data for this case']]
                : ['case' => $case['label'], ...self::compare($case['args'], $case['mode'], $case['keys'])];
        }

        return $results;
    }

    /**
     * Runs one query on both engines and compares them.
     *
     * The pseudo-argument `_user` runs the query as that user.
     *
     * @param  array<string, mixed>  $args
     * @param  list<string>  $keys  For MODE_SORTED: the fields sorted on (title, post_author, meta:price...)
     * @return array{outcome: string, mysql_found?: int, meili_found?: int, reason?: string|null, notes: list<string>, params?: array<string, mixed>|null}
     */
    public static function compare(array $args, string $mode = self::MODE_ORDER, array $keys = []): array
    {
        $previousUser = get_current_user_id();
        $notes = [];

        // Sticky posts go to the top when they are on the page: with ties, whether they are varies
        if ($mode === self::MODE_SORTED) {
            $args += ['ignore_sticky_posts' => true];
        }

        try {
            $mysql = self::run($args, false);
            $meili = SearchFallbacks::withoutRecording(static fn () => self::run($args, true));
        } catch (\Throwable $e) {
            return ['outcome' => self::ERROR, 'notes' => [get_class($e).': '.$e->getMessage()]];
        } finally {
            wp_set_current_user($previousUser);
        }

        $base = ['mysql_found' => $mysql['found'], 'meili_found' => $meili['found'], 'reason' => $meili['reason'], 'params' => $meili['params']];

        if (! $meili['served']) {
            return ['outcome' => self::FALLBACK, ...$base, 'notes' => [$meili['reason'] ?? 'not intercepted']];
        }

        if ($mode === self::MODE_SEARCH) {
            $overlap = count(array_intersect($mysql['ids'], $meili['ids']));
            $notes[] = sprintf('MySQL %d found / Meilisearch %d found; first page overlap %d/%d', $mysql['found'], $meili['found'], $overlap, max(count($mysql['ids']), 1));

            return ['outcome' => self::INFO, ...$base, 'notes' => $notes];
        }

        $outcome = self::OK;
        $sortedA = $mysql['ids'];
        $sortedB = $meili['ids'];
        sort($sortedA);
        sort($sortedB);

        if ($mode === self::MODE_SORTED) {
            $sortKeys = static fn (array $ids): array => array_map(static fn (int $id): string => self::sortKey($id, $keys), $ids);

            if ($sortKeys($mysql['ids']) !== $sortKeys($meili['ids'])) {
                $outcome = self::DIFF;
                $notes[] = 'different order: '.json_encode(array_slice($sortKeys($mysql['ids']), 0, 4), JSON_UNESCAPED_UNICODE).' vs '.json_encode(array_slice($sortKeys($meili['ids']), 0, 4), JSON_UNESCAPED_UNICODE);
            }
        } elseif ($mode === self::MODE_COUNT) {
            if ($mysql['count'] !== $meili['count']) {
                $outcome = self::DIFF;
                $notes[] = "post_count MySQL {$mysql['count']} vs Meilisearch {$meili['count']}";
            }
        } elseif ($sortedA !== $sortedB) {
            $outcome = self::DIFF;
            $notes[] = sprintf(
                'missing %s / extra %s',
                json_encode(array_slice(array_values(array_diff($mysql['ids'], $meili['ids'])), 0, 6)),
                json_encode(array_slice(array_values(array_diff($meili['ids'], $mysql['ids'])), 0, 6))
            );
        } elseif ($mode === self::MODE_ORDER && $mysql['ids'] !== $meili['ids']) {
            $outcome = self::DIFF;
            $notes[] = 'same posts, different order';
        }

        if ($mysql['found'] !== $meili['found']) {
            $outcome = self::DIFF;
            $notes[] = "found_posts MySQL {$mysql['found']} vs Meilisearch {$meili['found']}";
        }

        if ($mysql['pages'] !== $meili['pages']) {
            $outcome = self::DIFF;
            $notes[] = "max_num_pages MySQL {$mysql['pages']} vs Meilisearch {$meili['pages']}";
        }

        if ($mysql['shape'] !== $meili['shape']) {
            $outcome = self::DIFF;
            $notes[] = "posts are {$mysql['shape']} on MySQL, {$meili['shape']} on Meilisearch";
        }

        foreach (self::fieldDifferences($mysql['first'], $meili['first']) as $difference) {
            $outcome = self::DIFF;
            $notes[] = $difference;
        }

        return ['outcome' => $outcome, ...$base, 'notes' => $notes];
    }

    /**
     * Counts the outcomes.
     *
     * @param  list<array{outcome: string}>  $results
     * @return array<string, int>
     */
    public static function tally(array $results): array
    {
        $tally = [];

        foreach ($results as $result) {
            $tally[$result['outcome']] = ($tally[$result['outcome']] ?? 0) + 1;
        }

        ksort($tally);

        return $tally;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ids: list<int>, found: int, count: int, pages: int, served: bool, reason: string|null, params: array<string, mixed>|null, first: mixed, shape: string}
     */
    private static function run(array $args, bool $meilisearch): array
    {
        wp_set_current_user((int) ($args['_user'] ?? 0));
        unset($args['_user']);

        $query = new WP_Query(['use_meilisearch' => $meilisearch, 'suppress_filters' => false] + $args);
        $posts = $query->posts;
        $info = $query->meiliscout ?? null;

        return [
            'ids' => array_map(static fn ($post) => is_object($post) ? (int) $post->ID : (int) $post, $posts),
            'found' => (int) $query->found_posts,
            'count' => (int) $query->post_count,
            'pages' => (int) $query->max_num_pages,
            'served' => is_array($info) && ! empty($info['served']),
            'reason' => is_array($info) ? ($info['reason'] ?? null) : null,
            'params' => is_array($info) ? ($info['params'] ?? null) : null,
            'first' => $posts[0] ?? null,
            'shape' => self::shape($posts[0] ?? null),
        ];
    }

    /**
     * The values a post is sorted on, as one string: posts that tie have the same.
     *
     * @param  list<string>  $keys
     */
    private static function sortKey(int $id, array $keys): string
    {
        $post = get_post($id);
        $values = [];

        foreach ($keys as $key) {
            $values[] = match (true) {
                str_starts_with($key, 'meta:') => (string) get_post_meta($id, substr($key, 5), true),
                // As MySQL's collation compares titles
                $key === 'title' => \Pollora\MeiliScout\Indexables\PostIndexable::titleSortKey((string) $post?->post_title),
                default => $post instanceof WP_Post ? (string) ($post->$key ?? '') : '',
            };
        }

        return implode(' | ', $values);
    }

    private static function shape(mixed $post): string
    {
        return match (true) {
            $post === null => 'none',
            $post instanceof WP_Post => 'WP_Post',
            is_object($post) => 'stdClass',
            default => gettype($post),
        };
    }

    /**
     * @return list<string>
     */
    private static function fieldDifferences(mixed $mysql, mixed $meili): array
    {
        if (! $mysql instanceof WP_Post || ! $meili instanceof WP_Post || $mysql->ID !== $meili->ID) {
            return [];
        }

        $differences = [];

        foreach (self::POST_FIELDS as $field) {
            $a = $mysql->$field ?? null;
            $b = $meili->$field ?? null;

            if ($a !== $b) {
                $differences[] = sprintf(
                    'WP_Post->%s %s vs %s',
                    $field,
                    var_export(is_string($a) ? mb_substr($a, 0, 20) : $a, true),
                    var_export(is_string($b) ? mb_substr($b, 0, 20) : $b, true)
                );
            }
        }

        return $differences;
    }

    /**
     * Whether the indexed meta keys include $key. For SiteData.
     */
    public static function isIndexedMetaKey(string $key): bool
    {
        return in_array($key, (array) Settings::get('indexed_meta_keys', []), true);
    }
}
