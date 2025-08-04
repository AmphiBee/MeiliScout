<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query;

use Pollora\MeiliScout\Contracts\QueryInterface;
use WP_Query;

/**
 * Adapter for WordPress WP_Query to the QueryInterface.
 * 
 * Allows WP_Query objects to be used with the MeiliScout query system.
 */
class WPQueryAdapter implements QueryInterface
{
    /**
     * The WordPress query instance.
     *
     * @var WP_Query
     */
    private WP_Query $wpQuery;

    /**
     * Constructor.
     *
     * @param WP_Query $wpQuery WordPress query instance to adapt
     */
    public function __construct(WP_Query $wpQuery)
    {
        $this->wpQuery = $wpQuery;
    }

    /**
     * Gets a query parameter value.
     *
     * @param string $key The parameter key
     * @param mixed $default Default value if parameter doesn't exist
     * @return mixed The parameter value or default
     */
    public function get(string $key, $default = null)
    {
        return $this->wpQuery->get($key, $default);
    }

    /**
     * Sets a query parameter value.
     *
     * @param string $key The parameter key
     * @param mixed $value The parameter value
     * @return mixed The set value
     */
    public function set($key, $value)
    {
        return $this->wpQuery->set($key, $value);
    }
}
