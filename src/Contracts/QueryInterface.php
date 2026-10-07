<?php

namespace Pollora\MeiliScout\Contracts;

interface QueryInterface
{
    /**
     * Retrieve a value from the query.
     *
     * @param string $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * Set a value on the query.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function set($key, $value);
}
