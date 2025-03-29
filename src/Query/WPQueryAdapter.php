<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Pollora\MeiliScout\Contracts\QueryInterface;
use WP_Query;

class WPQueryAdapter implements QueryInterface
{
    private WP_Query $wpQuery;

    public function __construct(WP_Query $wpQuery)
    {
        $this->wpQuery = $wpQuery;
    }

    public function get($key, $default = null)
    {
        return $this->wpQuery->get($key, $default);
    }

    public function set($key, $value)
    {
        return $this->wpQuery->set($key, $value);
    }
}
