<?php

declare(strict_types=1);

namespace {
    if (! function_exists('current_filter')) {
        function current_filter() { return $GLOBALS['current_filter'] ?? ''; }
    }
    if (! class_exists('WP_Term_Query', false)) {
        class WP_Term_Query
        {
            public array $query_vars = [];
        }
    }
}

namespace Pollora\MeiliScout\Tests\Unit\Query\Terms {

    use Pollora\MeiliScout\Query\Terms\TermSqlFilters;

    /**
     * Runs a filter through the watchers, a plugin's callback in between.
     */
    function filterThrough(string $hook, mixed $value, ?callable $plugin = null): mixed
    {
        $GLOBALS['current_filter'] = $hook;
        $value = TermSqlFilters::given($value);
        $value = $plugin !== null ? $plugin($value) : $value;

        return TermSqlFilters::returned($value);
    }

    beforeEach(fn () => TermSqlFilters::reset());

    test('a change made by a filter is recorded for the query it ran for', function () {
        $query = new \WP_Term_Query;
        TermSqlFilters::open($query);
        filterThrough('get_terms_orderby', 't.name');
        filterThrough('terms_clauses', ['where' => 'a'], fn (array $clauses) => ['where' => 'a AND b']);

        expect(TermSqlFilters::close($query))->toBe(['terms_clauses']);
    });

    test('a query run inside another one keeps its own changes', function () {
        $outer = new \WP_Term_Query;
        $inner = new \WP_Term_Query;

        TermSqlFilters::open($outer);
        filterThrough('get_terms_orderby', 't.name', fn () => 't.slug');
        TermSqlFilters::open($inner);
        filterThrough('terms_clauses', ['where' => 'a'], fn () => ['where' => 'b']);

        expect(TermSqlFilters::close($inner))->toBe(['terms_clauses'])
            ->and(TermSqlFilters::close($outer))->toBe(['get_terms_orderby']);
    });

    test('a query that returned before terms_pre_query leaves its frame, whose changes count for the query below', function () {
        $outer = new \WP_Term_Query;
        $inner = new \WP_Term_Query;

        TermSqlFilters::open($outer);
        TermSqlFilters::open($inner);
        // The inner query returns early: no close; the outer one goes on
        filterThrough('list_terms_exclusions', '', fn () => 't.term_id NOT IN (5)');

        expect(TermSqlFilters::close($outer))->toBe(['list_terms_exclusions'])
            ->and(TermSqlFilters::close($inner))->toBe([]);
    });
}
