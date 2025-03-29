<?php

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder;

use Pollora\MeiliScout\Contracts\QueryInterface;

class WP_Query {
    public function get($key, $default = null) { return null; }
}

class MockWPQuery implements QueryInterface
{
    public array $query_vars;

    public function __construct(array $query_vars = [])
    {
        $this->query_vars = array_merge([
            'posts_per_page' => 10,
            'paged' => 1,
            'post_type' => 'post',
            'post_status' => 'publish',
        ], $query_vars);
    }

    public function get($key, $default = null)
    {
        return $this->query_vars[$key] ?? $default;
    }

    public function set($key, $value)
    {
        return $this->query_vars[$key] = $value;
    }
}
