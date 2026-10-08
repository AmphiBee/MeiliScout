<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;
use Pollora\MeiliScout\Indexables\PostIndexable;
use Pollora\MeiliScout\Query\Builders\Concerns\FormatsValues;
use Pollora\MeiliScout\Query\QuerySupport;
use Pollora\MeiliScout\Query\QueryVars;
use Pollora\MeiliScout\Query\UnsupportedQuery;
use Pollora\MeiliScout\Services\IndexNames;

/**
 * Filters on the post types and statuses the query is about.
 *
 * The types are the ones WordPress works out (posts by default, the types of
 * a custom taxonomy on its archive, every searchable type for 'any'); the
 * statuses the ones it would include, among those the index holds. That a
 * status the index lacks has no post is checked by QuerySupport.
 *
 * When private posts are indexed, a logged-in user gets them as WordPress
 * gives them: all of a type whose private posts they may read, their own
 * otherwise.
 *
 * On a taxonomy archive that may hold attachments, WordPress gives an
 * attachment ('inherit') whose parent has one of the statuses asked for:
 * documents carry the parent's status for that.
 */
class TypeStatusBuilder implements QueryBuilderInterface
{
    use FormatsValues;

    /**
     * @param  array<string, mixed>  $searchParams
     */
    public function build(QueryInterface $query, array &$searchParams): void
    {
        $searchParams['filter'] = $searchParams['filter'] ?? [];

        $types = QueryVars::postTypes($query);
        $requested = QuerySupport::requestedStatuses($query);
        $statuses = self::statuses($query);

        if ($requested['explicit']) {
            foreach ($requested['statuses'] as $status) {
                if (! in_array($status, PostIndexable::queryableStatuses(), true)) {
                    throw new UnsupportedQuery('unindexed_status:'.$status);
                }
            }
        }

        // Private posts a user may only see their own of, as WP_Query::get_posts() restricts them
        if (! $requested['explicit'] && ! QueryVars::wp($query)?->is_singular && $types !== 'any' && in_array('private', $statuses, true) && function_exists('current_user_can')) {
            $searchParams['filter'][] = $this->readablePrivate($types, $statuses);

            return;
        }

        // 'any' without WordPress to list the types: the index only holds indexed ones
        if ($types !== 'any') {
            $searchParams['filter'][] = $this->filter('post_type', $types);
        }

        if ($requested['explicit'] && self::joinsParentStatus($query)) {
            if (IndexNames::activeSchema('posts') < 4) {
                throw new UnsupportedQuery('schema_too_old');
            }

            $searchParams['filter'][] = "({$this->filter('post_status', $statuses)} OR (post_status = 'inherit' AND {$this->filter('parent_status', $requested['statuses'])}))";

            return;
        }

        $searchParams['filter'][] = $this->filter('post_status', $statuses);
    }

    /**
     * Whether WP_Query::get_posts() reads the parent's status for 'inherit': a taxonomy archive without a post type, or with attachments.
     */
    private static function joinsParentStatus(QueryInterface $query): bool
    {
        $wp = QueryVars::wp($query);

        if ($wp === null || ! $wp->is_tax) {
            return false;
        }

        $asked = $wp->query['post_type'] ?? '';

        return empty($asked) || in_array('attachment', (array) $asked, true);
    }

    /**
     * The statuses the query includes, among those the index holds every post of.
     *
     * @return list<string>
     */
    public static function statuses(QueryInterface $query): array
    {
        $requested = QuerySupport::requestedStatuses($query);

        // A single post is not filtered on its status in SQL: WordPress checks it after the query, on ours too
        if (! $requested['explicit'] && QueryVars::wp($query)?->is_singular) {
            return PostIndexable::queryableStatuses();
        }

        return array_values(array_intersect($requested['statuses'], PostIndexable::queryableStatuses()));
    }

    /**
     * Each type with its statuses: private posts of the types the user may not read others' private posts of are theirs only.
     *
     * @param  list<string>  $types
     * @param  list<string>  $statuses
     */
    private function readablePrivate(array $types, array $statuses): string
    {
        $readable = [];
        $own = [];

        foreach ($types as $type) {
            $object = get_post_type_object($type);
            $capability = $object !== null ? $object->cap->read_private_posts : "read_private_{$type}s";

            if (current_user_can($capability)) {
                $readable[] = $type;
            } else {
                $own[] = $type;
            }
        }

        $groups = [];

        if ($readable !== []) {
            $groups[] = "({$this->filter('post_type', $readable)} AND {$this->filter('post_status', $statuses)})";
        }

        if ($own !== []) {
            if (IndexNames::activeSchema() < 3) {
                throw new UnsupportedQuery('schema_too_old');
            }

            $others = array_values(array_diff($statuses, ['private']));
            $mine = "(post_status = 'private' AND post_author = ".get_current_user_id().')';
            $status = $others === [] ? $mine : "({$this->filter('post_status', $others)} OR {$mine})";

            $groups[] = "({$this->filter('post_type', $own)} AND {$status})";
        }

        return count($groups) === 1 ? $groups[0] : '('.implode(' OR ', $groups).')';
    }

    /**
     * @param  list<string>  $values
     */
    private function filter(string $attribute, array $values): string
    {
        $values = array_values(array_unique($values));

        return count($values) === 1
            ? "{$attribute} = {$this->quote($values[0])}"
            : "{$attribute} IN [".implode(', ', array_map(fn (string $value) => $this->quote($value), $values)).']';
    }
}
