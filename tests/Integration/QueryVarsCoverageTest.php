<?php

declare(strict_types=1);

use Pollora\MeiliScout\Diagnostics\QueryParity;
use Pollora\MeiliScout\Query\QuerySupport;

/*
 * Every argument WP_Query knows is either translated, or falls back with a
 * case of its own in the parity harness. A version of WordPress adding an
 * argument breaks this test until the argument is translated or listed in
 * QuerySupport::UNTRANSLATED.
 */

/**
 * WP_Query's arguments: the ones fill_query_vars() sets, the public and private query vars, and the documented ones.
 *
 * @return list<string>
 */
function wpQueryArguments(): array
{
    global $wp;

    $documented = [
        'attachment_id', 'author', 'author__in', 'author__not_in', 'author_name', 'cache_results', 'cat',
        'category__and', 'category__in', 'category__not_in', 'category_name', 'comment_count', 'comment_status',
        'comments_per_page', 'date_query', 'day', 'exact', 'fields', 'hour', 'ignore_sticky_posts', 'lazy_load_term_meta',
        'm', 'menu_order', 'meta_compare', 'meta_compare_key', 'meta_key', 'meta_query', 'meta_type', 'meta_type_key',
        'meta_value', 'meta_value_num', 'minute', 'monthnum', 'name', 'no_found_rows', 'nopaging', 'offset', 'order',
        'orderby', 'p', 'page', 'page_id', 'paged', 'pagename', 'perm', 'ping_status', 'post__in', 'post__not_in',
        'post_mime_type', 'post_name__in', 'post_parent', 'post_parent__in', 'post_parent__not_in', 'post_password',
        'post_status', 'post_type', 'posts_per_archive_page', 'posts_per_page', 's', 'search_columns', 'second',
        'sentence', 'suppress_filters', 'tag', 'tag__and', 'tag__in', 'tag__not_in', 'tag_id', 'tag_slug__and',
        'tag_slug__in', 'tax_query', 'title', 'update_post_meta_cache', 'update_post_term_cache',
        'update_menu_item_cache', 'w', 'year',
    ];

    return array_values(array_unique([
        ...array_keys((new WP_Query)->fill_query_vars([])),
        ...$wp->public_query_vars,
        ...$wp->private_query_vars,
        ...$documented,
    ]));
}

test('every WP_Query argument is translated, or listed as falling back', function () {
    $ownQueryVars = [];

    foreach ([...get_post_types([], 'objects'), ...get_taxonomies([], 'objects')] as $object) {
        if (is_string($object->query_var) && $object->query_var !== '') {
            $ownQueryVars[] = $object->query_var;
        }
    }

    $known = [...QuerySupport::translatedVars(), ...array_keys(QuerySupport::UNTRANSLATED), ...$ownQueryVars];
    $unknown = array_values(array_diff(wpQueryArguments(), $known));

    expect($unknown)->toBe([], 'Neither translated nor listed in QuerySupport::UNTRANSLATED: '.implode(', ', $unknown));
})->group('integration');

test('each argument listed as falling back has its case in the parity harness, which falls back', function () {
    $labels = array_column(QueryParity::cases(), 'mode', 'label');

    foreach (array_keys(QuerySupport::UNTRANSLATED) as $var) {
        expect($labels)->toHaveKey('untranslated '.$var)
            ->and($labels['untranslated '.$var])->toBe(QueryParity::MODE_FALLBACK);
    }
})->group('integration');
